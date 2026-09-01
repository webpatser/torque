<?php

declare(strict_types=1);

namespace Webpatser\Torque\Redis;

use Fledge\Async\Redis\RedisClient;
use Webpatser\Torque\Job\DeadLetterHandler;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Keeps Torque's Redis key space from growing without bound.
 *
 * Two jobs, both of which used to have no owner at all:
 *
 *  - the dead-letter stream: drop entries older than `dead_letter.ttl`
 *    (XTRIM MINID) and cap the total length (XTRIM MAXLEN ~), so a failure
 *    storm can never fill Redis again (scrpr 2026-08-27: 3.9M entries, 22 GB,
 *    OOM loop);
 *  - consumer groups: XGROUP DELCONSUMER every consumer that has no pending
 *    messages and has been idle longer than the given threshold. Every worker
 *    restart mints a new `{host}-{pid}-{hex}` consumer name, and nothing ever
 *    removed the old ones (124k per stream were found).
 *
 * On top of that, {@see deepClean()} sweeps everything an upgrade from an
 * older Torque can leave behind: per-job event streams that never got their
 * terminal EXPIRE, index members pointing at streams that are gone, and
 * legacy metric keys. It runs once per version through
 * {@see UpgradeRunner}, and on demand via `torque:prune --deep`.
 *
 * The master runs the light pass on a timer (`dead_letter.prune_interval`);
 * the `torque:prune` command is the same logic on demand.
 */
final class StreamHousekeeper
{
    /**
     * Extra grace on top of `job_streams.ttl` before a per-job stream without
     * an expiry is considered abandoned. A job can legitimately run for a long
     * time; only streams whose last event is older than ttl + this margin are
     * treated as leaked by a worker that died mid-flight.
     */
    public const int ORPHAN_MARGIN_SECONDS = 3600;

    /**
     * Mirrors MetricsPublisher::HEARTBEAT_TTL_SECONDS (private there). Worker
     * hashes carry that TTL on every publish, so anything older is a leftover
     * from a version that did not set one.
     */
    private const int WORKER_HEARTBEAT_TTL = 60;

    /** Keys deleted per round trip while sweeping. */
    private const int BATCH_SIZE = 100;

    private ?RedisClient $redis = null;

    /** Whether this Redis understands UNLINK (Redis 4+); resolved on first use. */
    private ?bool $supportsUnlink = null;

    /**
     * @param  list<string>  $queues  Queue names whose consumer groups get swept.
     * @param  int  $maxEntries  Extra hard cap applied after the handler's own policy. 0 = handler only.
     * @param  int  $jobStreamTtl  `job_streams.ttl`, the basis for the orphaned-stream cutoff.
     * @param  int  $dailyRetentionDays  `metrics.rollups.daily_days`; 0 keeps the day tier forever.
     */
    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix,
        private readonly string $consumerGroup,
        private readonly array $queues,
        private readonly DeadLetterHandler $deadLetters,
        private readonly int $maxEntries = 0,
        private readonly bool $cluster = false,
        private readonly int $jobStreamTtl = 300,
        private readonly int $dailyRetentionDays = 730,
    ) {}

    /**
     * Build a housekeeper from a merged Torque config array.
     *
     * @param  array<string, mixed>  $config
     * @param  int|null  $maxEntries  Override for the dead-letter cap (the `torque:prune` option).
     */
    #[\NoDiscard]
    public static function fromConfig(
        array $config,
        ?DeadLetterHandler $deadLetters = null,
        ?int $maxEntries = null,
    ): self {
        $redisUri = (string) ($config['redis']['uri'] ?? 'redis://127.0.0.1:6379');
        $prefix = (string) ($config['redis']['prefix'] ?? 'torque:');
        $deadLetterConfig = (array) ($config['dead_letter'] ?? []);
        $cap = $maxEntries ?? (int) ($deadLetterConfig['max_entries'] ?? 100000);

        /** @var list<string> $queues */
        $queues = array_keys((array) ($config['streams'] ?? ['default' => []]));

        return new self(
            redisUri: $redisUri,
            prefix: $prefix,
            consumerGroup: (string) ($config['consumer_group'] ?? 'torque'),
            queues: $queues === [] ? ['default'] : $queues,
            deadLetters: $deadLetters ?? new DeadLetterHandler(
                redisUri: $redisUri,
                ttl: (int) ($deadLetterConfig['ttl'] ?? 604800),
                prefix: $prefix,
                allowedQueues: $queues,
                maxEntries: (int) ($deadLetterConfig['max_entries'] ?? 100000),
            ),
            maxEntries: $cap,
            cluster: (bool) ($config['redis']['cluster'] ?? false),
            jobStreamTtl: (int) ($config['job_streams']['ttl'] ?? 300),
            dailyRetentionDays: (int) ($config['metrics']['rollups']['daily_days'] ?? 730),
        );
    }

    /**
     * Apply the dead-letter retention policy and report the length change.
     *
     * @return array{before: int, after: int}
     */
    #[\NoDiscard]
    public function pruneDeadLetter(bool $dryRun = false): array
    {
        $key = $this->deadLetters->streamKey();
        $before = (int) $this->redis()->execute('XLEN', $key);

        if ($dryRun) {
            return ['before' => $before, 'after' => $before];
        }

        // TTL trim plus the handler's configured cap.
        $this->deadLetters->trim();

        // An explicit override (torque:prune --dead-letter-max) caps further.
        if ($this->maxEntries > 0) {
            $this->redis()->execute('XTRIM', $key, 'MAXLEN', '~', (string) $this->maxEntries);
        }

        return ['before' => $before, 'after' => (int) $this->redis()->execute('XLEN', $key)];
    }

    /**
     * Delete consumers that have been idle past the threshold with nothing pending.
     *
     * A consumer with pending entries is left alone: DELCONSUMER discards its
     * PEL, so those jobs would be lost instead of being reclaimed by the next
     * worker's XAUTOCLAIM.
     *
     * @return array<string, int> Removed (or removable, when dry running) consumers per queue.
     */
    #[\NoDiscard]
    public function pruneConsumers(int $idleSeconds, bool $dryRun = false): array
    {
        $idleMs = max(0, $idleSeconds) * 1000;
        $removed = [];

        foreach ($this->queues as $queue) {
            $streamKey = $this->streamKey($queue);
            $removed[$queue] = 0;

            try {
                $consumers = $this->redis()->execute('XINFO', 'CONSUMERS', $streamKey, $this->consumerGroup);
            } catch (\Throwable) {
                continue; // Stream or group does not exist yet.
            }

            if (! is_array($consumers)) {
                continue;
            }

            foreach ($consumers as $consumer) {
                if (! is_array($consumer)) {
                    continue;
                }

                $info = self::normalise($consumer);

                if ((int) ($info['pending'] ?? 1) !== 0 || (int) ($info['idle'] ?? 0) < $idleMs) {
                    continue;
                }

                if (! $dryRun) {
                    $this->redis()->execute('XGROUP', 'DELCONSUMER', $streamKey, $this->consumerGroup, (string) $info['name']);
                }

                $removed[$queue]++;
            }
        }

        return $removed;
    }

    /**
     * Sweep everything an upgrade from an older Torque can leave behind.
     *
     * Five categories, each counted separately and each honouring `$dryRun`:
     *
     *  - `job_streams`: per-job event streams with no expiry whose last event
     *    is older than `job_streams.ttl` plus {@see ORPHAN_MARGIN_SECONDS}.
     *    Only terminal events set an EXPIRE, so every job killed mid-flight by
     *    an OOM restart or a deploy leaked its stream forever.
     *  - `index_members`: entries in the `jobs:active` / `jobs:recent` sorted
     *    sets whose job stream no longer exists.
     *  - `dead_letter`: entries dropped by the normal TTL and cap policy.
     *  - `consumers`: stale consumer names, per the given idle threshold.
     *  - `legacy_keys`: the pre-rollup `metrics:buckets` hash (only once its
     *    replacement exists, so the migration cannot be short-circuited) and
     *    worker hashes without a recent heartbeat.
     *  - `host_index`: hosts the per-host rollups have not seen inside the day
     *    tier's retention.
     *
     * @param  int  $consumerIdleSeconds  Idle threshold for the consumer sweep.
     * @return array<string, int>
     */
    #[\NoDiscard]
    public function deepClean(bool $dryRun = false, int $consumerIdleSeconds = 3600): array
    {
        $counts = [
            'job_streams' => $this->pruneOrphanedJobStreams($dryRun),
            'index_members' => $this->pruneStaleIndexMembers($dryRun),
            'dead_letter' => 0,
            'consumers' => 0,
            'legacy_keys' => $this->pruneLegacyKeys($dryRun),
        ];

        $deadLetter = $this->pruneDeadLetter($dryRun);
        $counts['dead_letter'] = max(0, $deadLetter['before'] - $deadLetter['after']);
        $counts['consumers'] = array_sum($this->pruneConsumers($consumerIdleSeconds, $dryRun));
        $counts['host_index'] = $this->pruneHostIndex($dryRun);

        return $counts;
    }

    /**
     * Drop hosts the per-host rollups have not seen inside the day tier's
     * retention.
     *
     * The publisher sweeps this index itself once a day, and every per-host key
     * carries an EXPIRE, so this is a catch-up for an index a master never got
     * round to: after a long outage, or after an upgrade from a version that
     * did not write one.
     */
    private function pruneHostIndex(bool $dryRun): int
    {
        $indexKey = $this->prefix.'metrics:hosts';
        $retentionDays = max(0, $this->dailyRetentionDays);

        // Zero means the day tier keeps everything, so the index does too.
        if ($retentionDays === 0) {
            return 0;
        }

        $cutoff = time() - $retentionDays * 86400;

        try {
            if ($dryRun) {
                $stale = $this->redis()->execute('ZCOUNT', $indexKey, '-inf', '('.$cutoff);

                return is_int($stale) ? $stale : 0;
            }

            $removed = $this->redis()->execute('ZREMRANGEBYSCORE', $indexKey, '-inf', '('.$cutoff);

            return is_int($removed) ? $removed : 0;
        } catch (\Throwable) {
            // Best-effort, like every other sweep here.
            return 0;
        }
    }

    /**
     * Delete per-job event streams that never received their terminal EXPIRE.
     */
    private function pruneOrphanedJobStreams(bool $dryRun): int
    {
        $cutoffMs = (time() - $this->jobStreamTtl - self::ORPHAN_MARGIN_SECONDS) * 1000;
        $removed = 0;
        $batch = [];

        foreach ($this->scan($this->prefix.'job:*') as $key) {
            try {
                // -1 is "no expiry": exactly the leak. Streams that reached a
                // terminal event carry a TTL and expire on their own.
                if ((int) $this->redis()->execute('TTL', $key) !== -1) {
                    continue;
                }

                $last = $this->redis()->execute('XREVRANGE', $key, '+', '-', 'COUNT', '1');
            } catch (\Throwable) {
                continue; // Not a stream, or it vanished mid-scan.
            }

            if (! is_array($last) || $last === []) {
                continue;
            }

            $lastMs = (int) strtok((string) ($last[0][0] ?? '0'), '-');

            if ($lastMs >= $cutoffMs) {
                continue;
            }

            $batch[] = $key;
            $removed++;

            if (! $dryRun && count($batch) >= self::BATCH_SIZE) {
                $this->drop($batch);
                $batch = [];
            }
        }

        if (! $dryRun && $batch !== []) {
            $this->drop($batch);
        }

        return $removed;
    }

    /**
     * Drop index members whose job stream is gone.
     */
    private function pruneStaleIndexMembers(bool $dryRun): int
    {
        $removed = 0;

        foreach (['jobs:active', 'jobs:recent'] as $suffix) {
            $indexKey = $this->prefix.$suffix;

            $stale = [];

            // ZSCAN rather than a full ZRANGE: after an incident these indexes
            // can hold a very large number of uuids, and the whole point of
            // this sweep is to survive that case.
            foreach ($this->scanSortedSet($indexKey) as $uuid) {
                if ((int) $this->redis()->execute('EXISTS', $this->prefix.'job:'.$uuid) === 1) {
                    continue;
                }

                $stale[] = $uuid;
                $removed++;

                if (! $dryRun && count($stale) >= self::BATCH_SIZE) {
                    $this->redis()->execute('ZREM', $indexKey, ...$stale);
                    $stale = [];
                }
            }

            if (! $dryRun && $stale !== []) {
                $this->redis()->execute('ZREM', $indexKey, ...$stale);
            }
        }

        return $removed;
    }

    /**
     * Remove metric keys that newer code no longer writes.
     */
    private function pruneLegacyKeys(bool $dryRun): int
    {
        $removed = 0;
        $legacyBuckets = $this->prefix.'metrics:buckets';

        try {
            // Only once the rollup tier it migrates into exists: deleting it
            // before MetricsPublisher::migrateLegacyBuckets() has run would
            // throw the history away instead of moving it.
            if ((int) $this->redis()->execute('EXISTS', $legacyBuckets) === 1
                && (int) $this->redis()->execute('EXISTS', $this->prefix.'metrics:rollup:minute') === 1) {
                if (! $dryRun) {
                    $this->drop([$legacyBuckets]);
                }

                $removed++;
            }
        } catch (\Throwable) {
            // Best-effort: a missing or unreadable key is not worth failing on.
        }

        $now = time();
        $batch = [];

        foreach ($this->scan($this->prefix.'worker:*') as $key) {
            try {
                $heartbeat = (int) $this->redis()->execute('HGET', $key, 'last_heartbeat');
            } catch (\Throwable) {
                continue;
            }

            // Every publish refreshes both the field and a 60s TTL, so a hash
            // without a recent heartbeat belongs to a process that is gone.
            if ($heartbeat > 0 && ($now - $heartbeat) <= self::WORKER_HEARTBEAT_TTL) {
                continue;
            }

            $batch[] = $key;
            $removed++;

            if (! $dryRun && count($batch) >= self::BATCH_SIZE) {
                $this->drop($batch);
                $batch = [];
            }
        }

        if (! $dryRun && $batch !== []) {
            $this->drop($batch);
        }

        return $removed;
    }

    /**
     * Iterate the members of a sorted set with ZSCAN.
     *
     * @return \Generator<int, string>
     */
    private function scanSortedSet(string $key): \Generator
    {
        $cursor = '0';

        do {
            try {
                $result = $this->redis()->execute('ZSCAN', $key, $cursor, 'COUNT', '500');
            } catch (\Throwable) {
                return;
            }

            if (! is_array($result)) {
                return;
            }

            $cursor = (string) $result[0];
            $pairs = (array) ($result[1] ?? []);

            // ZSCAN replies are a flat [member, score, member, score, ...] list.
            for ($i = 0, $count = count($pairs); $i < $count; $i += 2) {
                yield (string) $pairs[$i];
            }
        } while ($cursor !== '0');
    }

    /**
     * Iterate keys matching a pattern with SCAN.
     *
     * Never KEYS: this runs against production instances with millions of
     * keys, where KEYS blocks the server for the whole sweep.
     *
     * @return \Generator<int, string>
     */
    private function scan(string $pattern): \Generator
    {
        $cursor = '0';

        do {
            $result = $this->redis()->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500');

            if (! is_array($result)) {
                return;
            }

            $cursor = (string) $result[0];

            foreach ((array) ($result[1] ?? []) as $key) {
                yield (string) $key;
            }
        } while ($cursor !== '0');
    }

    /**
     * Delete a batch of keys, preferring the non-blocking UNLINK.
     *
     * @param  list<string>  $keys
     */
    private function drop(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        if ($this->supportsUnlink !== false) {
            try {
                $this->redis()->execute('UNLINK', ...$keys);
                $this->supportsUnlink = true;

                return;
            } catch (\Throwable) {
                // Redis older than 4.0 (or a proxy without UNLINK): fall back
                // once and stop trying for the rest of the sweep.
                $this->supportsUnlink = false;
            }
        }

        $this->redis()->execute('DEL', ...$keys);
    }

    /**
     * Cluster-safe stream key, matching StreamQueue::getStreamKey().
     */
    private function streamKey(string $queue): string
    {
        if ($this->cluster && ! str_contains($queue, '{')) {
            $queue = '{'.$queue.'}';
        }

        return $this->prefix.$queue;
    }

    /**
     * Connect lazily so constructing a housekeeper never touches Redis.
     */
    private function redis(): RedisClient
    {
        return $this->redis ??= createRedisClient($this->redisUri);
    }

    /**
     * XINFO replies arrive as a RESP3 map (assoc) or a RESP2 flat key/value list.
     *
     * @param  array<int|string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function normalise(array $entry): array
    {
        if (array_is_list($entry)) {
            $assoc = [];

            for ($i = 0, $n = count($entry) - 1; $i < $n; $i += 2) {
                $assoc[(string) $entry[$i]] = $entry[$i + 1];
            }

            return $assoc;
        }

        return $entry;
    }
}
