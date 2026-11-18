<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Fledge\Async\Redis\RedisClient;
use Illuminate\Console\Command;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\StreamQueueResolver;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;
use function Fledge\Async\Redis\createRedisClient;

/**
 * Flush all pending and delayed jobs from Torque queues.
 *
 * Every stream entry that exists when the flush starts is removed, claimed or
 * not, as are all delayed jobs. A worker already running a claimed job still
 * finishes it, but its entry is gone from the stream, so if that worker dies
 * nothing is left to reclaim. The per-job event streams (`{prefix}job:{uuid}`)
 * and dashboard index members are removed only for the unclaimed and delayed
 * jobs; claimed and dead-lettered jobs keep theirs.
 *
 * The work runs in pages of {@see PAGE} entries: read, remove, classify,
 * delete the job keys. A failure partway leaves every earlier page fully
 * flushed and every later one untouched; only the page in progress can lose
 * its entries while its job keys stay, until their TTL expires them.
 *
 * Usage:
 *   php artisan torque:flush
 *   php artisan torque:flush --force
 *   php artisan torque:flush --queues=emails,notifications
 */
final class TorqueFlushCommand extends Command
{
    protected $signature = 'torque:flush
        {--force : Force flush in production}
        {--queues= : Comma-separated queue names (default: all configured)}';

    protected $description = 'Flush all pending and delayed jobs from Torque queues';

    /** Entries per page, and keys per delete batch. */
    private const int PAGE = 500;

    /**
     * Remove the given members from a delayed set and return the 1-based
     * positions of those that were still there. A member the master moved to
     * the stream in the meantime is not reported, so its job key stays.
     */
    private const string LUA_ZREM_REPORT = <<<'LUA'
        local gone = {}
        for i = 1, #ARGV do
            if redis.call('ZREM', KEYS[1], ARGV[i]) == 1 then
                gone[#gone + 1] = i
            end
        end
        return gone
        LUA;

    private ?RedisClient $redis = null;

    /** @var array<string, true>|null Uuids of the dead-lettered jobs, read once on first use. */
    private ?array $deadLetters = null;

    /** Removed stream entries that a worker had claimed (in flight) at the time. */
    private int $claimedRemoved = 0;

    /**
     * Run against the given client instead of one opened from the config.
     */
    public function usingRedis(RedisClient $redis): self
    {
        $this->redis = $redis;

        return $this;
    }

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force to flush in production.');

            return self::FAILURE;
        }

        $config = Narrow::map(config('torque'));
        $redisConfig = Narrow::map($config['redis'] ?? null);
        $prefix = Narrow::string($redisConfig['prefix'] ?? null, 'torque:');
        $redisUri = Narrow::string($redisConfig['uri'] ?? null, 'redis://127.0.0.1:6379');

        // Resolve queue names. Empty names are dropped: the queue resolver maps
        // '' to the default queue, so `--queues=emails,` would flush it too.
        // Braces are allowed for an explicitly hash-tagged name (`{emails}`).
        if ($this->option('queues') !== null) {
            $queues = array_values(array_filter(
                array_map('trim', explode(',', Narrow::string($this->option('queues')))),
                static fn (string $queue): bool => $queue !== '',
            ));

            if ($queues === []) {
                $this->components->error('No queue names given in --queues.');

                return self::FAILURE;
            }

            foreach ($queues as $queue) {
                if (! preg_match('/^[a-zA-Z0-9_\-.:{}]+$/', $queue)) {
                    $this->components->error("Invalid queue name: {$queue}");

                    return self::FAILURE;
                }
            }
        } elseif (isset($config['streams']) && is_array($config['streams'])) {
            $queues = array_map('strval', array_keys($config['streams']));
        } else {
            $queues = ['default'];
        }

        // Warn if Torque is running.
        if (MasterProcess::readPid() !== null) {
            $this->components->warn('Torque is running. In-flight jobs will complete normally. Only queued/delayed jobs are flushed.');
        }

        try {
            $redis = $this->redis ?? createRedisClient($redisUri);
            $streamQueue = StreamQueueResolver::make();
        } catch (\Throwable $e) {
            $this->components->error("Cannot connect to Redis: {$e->getMessage()}");

            return self::FAILURE;
        }

        $consumerGroup = Narrow::string($config['consumer_group'] ?? null, 'torque');
        $cluster = Narrow::bool($redisConfig['cluster'] ?? null);

        $totalStream = 0;
        $totalDelayed = 0;
        $jobsDeleted = 0;

        foreach ($queues as $queue) {
            $streamKey = $streamQueue->getStreamKey((string) $queue);

            [$streamSize, $streamJobs] = $this->flushStream($redis, $streamKey, $consumerGroup, $prefix, $cluster);
            [$delayedSize, $delayedJobs] = $this->flushDelayed($redis, $streamKey.':delayed', $prefix, $cluster);

            $totalStream += $streamSize;
            $totalDelayed += $delayedSize;
            $jobsDeleted += $streamJobs + $delayedJobs;

            $this->components->twoColumnDetail(
                $queue,
                "{$streamSize} queued, {$delayedSize} delayed",
            );
        }

        $this->newLine();

        if ($this->claimedRemoved > 0) {
            $this->components->warn(
                "{$this->claimedRemoved} claimed jobs were in flight. They finish normally, but their stream entries are gone: "
                .'a worker that dies while running one leaves nothing to reclaim.',
            );
        }

        $this->components->info("Flushed {$totalStream} queued, {$totalDelayed} delayed, {$jobsDeleted} job keys.");

        return self::SUCCESS;
    }

    /**
     * Remove every entry up to the stream's last id at the start, a page at a time.
     *
     * Each page is read, removed, and only then classified against the PEL,
     * which keeps the ids of removed entries. An entry a worker claims after
     * the read is in the PEL by then and counts as claimed; after the removal
     * nothing can claim it any more. Entries added after the start stay.
     *
     * @return array{int, int} Entries removed, job keys deleted.
     */
    private function flushStream(RedisClient $redis, string $streamKey, string $consumerGroup, string $prefix, bool $cluster): array
    {
        $last = Narrow::entries($redis->execute('XREVRANGE', $streamKey, '+', '-', 'COUNT', '1'));

        if ($last === []) {
            $this->dropRedeliveriesIfEmpty($redis, $streamKey);

            return [0, 0];
        }

        $end = $last[0]['id'];
        $from = '-';
        $removed = 0;
        $deleted = 0;

        do {
            $page = Narrow::entries($redis->execute('XRANGE', $streamKey, $from, $end, 'COUNT', (string) self::PAGE));

            if ($page === []) {
                break;
            }

            $ids = array_column($page, 'id');
            $first = $ids[0];
            $from = '('.$ids[count($ids) - 1];

            $removed += Narrow::int($redis->execute('XDEL', $streamKey, ...$ids));

            // The redelivery counts of the entries just removed.
            $redis->execute('HDEL', $streamKey.StreamQueue::REDELIVERIES_SUFFIX, ...$ids);

            $claimed = $this->pendingIds($redis, $streamKey, $consumerGroup, $first, $ids[count($ids) - 1]);
            $uuids = [];

            foreach ($page as $entry) {
                if (isset($claimed[$entry['id']])) {
                    $this->claimedRemoved++;

                    continue;
                }

                $uuid = self::payloadUuid($entry['fields']['payload'] ?? null);

                if ($uuid !== null) {
                    $uuids[] = $uuid;
                }
            }

            $deleted += $this->forgetJobs($redis, $prefix, $uuids, $cluster);
        } while (count($page) === self::PAGE);

        $this->dropRedeliveriesIfEmpty($redis, $streamKey);

        return [$removed, $deleted];
    }

    /**
     * Delete the whole redeliveries hash once the stream holds no entry.
     *
     * The page loop only drops the fields of the ids it removed; fields left
     * behind for ids that are long gone (a crash between ack and HDEL) would
     * otherwise outlive a full flush. Entries pushed during the flush survive
     * it, so the hash goes only when none did. Same slot as the stream.
     */
    private function dropRedeliveriesIfEmpty(RedisClient $redis, string $streamKey): void
    {
        if (Narrow::int($redis->execute('XLEN', $streamKey)) === 0) {
            $redis->execute('DEL', $streamKey.StreamQueue::REDELIVERIES_SUFFIX);
        }
    }

    /**
     * The ids in the consumer group's PEL between two ids, empty when no group exists yet.
     *
     * @return array<string, true>
     */
    private function pendingIds(RedisClient $redis, string $streamKey, string $consumerGroup, string $start, string $end): array
    {
        $ids = [];

        do {
            try {
                $page = Narrow::pending($redis->execute('XPENDING', $streamKey, $consumerGroup, $start, $end, (string) self::PAGE));
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'NOGROUP')) {
                    return [];
                }

                throw $e;
            }

            foreach ($page as $entry) {
                $ids[$entry['id']] = true;
            }

            $start = $page === [] ? $start : '('.$page[count($page) - 1]['id'];
        } while (count($page) === self::PAGE);

        return $ids;
    }

    /**
     * Remove the delayed jobs present at the start, a page at a time.
     *
     * @return array{int, int} Members removed, job keys deleted.
     */
    private function flushDelayed(RedisClient $redis, string $delayedKey, string $prefix, bool $cluster): array
    {
        $size = Narrow::int($redis->execute('ZCARD', $delayedKey));
        $removed = 0;
        $deleted = 0;

        for ($seen = 0; $seen < $size; $seen += self::PAGE) {
            $page = array_map(Narrow::string(...), Narrow::list($redis->execute('ZRANGE', $delayedKey, '0', (string) (self::PAGE - 1))));

            if ($page === []) {
                break;
            }

            $gone = Narrow::list($redis->execute('EVAL', self::LUA_ZREM_REPORT, '1', $delayedKey, ...$page));
            $uuids = [];

            foreach ($gone as $position) {
                $uuid = self::payloadUuid($page[Narrow::int($position) - 1] ?? null);

                if ($uuid !== null) {
                    $uuids[] = $uuid;
                }
            }

            $removed += count($gone);
            $deleted += $this->forgetJobs($redis, $prefix, $uuids, $cluster);
        }

        return [$removed, $deleted];
    }

    /**
     * Delete one page's job event streams and drop them from the dashboard indexes.
     *
     * Dead-lettered jobs are skipped. Without a cluster one UNLINK covers the
     * page; on a cluster the per-job keys hash to different slots, so the
     * single-key UNLINKs go out pipelined on the one connection instead.
     *
     * @param  list<string>  $uuids
     */
    private function forgetJobs(RedisClient $redis, string $prefix, array $uuids, bool $cluster): int
    {
        if ($uuids === []) {
            return 0;
        }

        $deadLetters = $this->deadLetterUuids($redis, $prefix);
        $uuids = array_values(array_filter($uuids, static fn (string $uuid): bool => ! isset($deadLetters[$uuid])));

        if ($uuids === []) {
            return 0;
        }

        $keys = array_map(static fn (string $uuid): string => $prefix.'job:'.$uuid, $uuids);

        if ($cluster) {
            $deleted = array_sum(await(array_map(
                static fn (string $key) => async(static fn (): int => Narrow::int($redis->execute('UNLINK', $key))),
                $keys,
            )));
        } else {
            $deleted = Narrow::int($redis->execute('UNLINK', ...$keys));
        }

        $redis->execute('ZREM', $prefix.'jobs:recent', ...$uuids);
        $redis->execute('ZREM', $prefix.'jobs:active', ...$uuids);

        return $deleted;
    }

    /**
     * The uuids of every dead-lettered job, across all queues.
     *
     * The dead-letter stream keeps no uuid index, so it is read once, a page
     * at a time, holding only the uuids. Its length is capped by
     * `torque.dead_letter.max_entries`, which bounds this set.
     *
     * @return array<string, true>
     */
    private function deadLetterUuids(RedisClient $redis, string $prefix): array
    {
        if ($this->deadLetters !== null) {
            return $this->deadLetters;
        }

        $uuids = [];
        $from = '-';

        do {
            $page = Narrow::entries($redis->execute('XRANGE', $prefix.'dead-letter', $from, '+', 'COUNT', (string) self::PAGE));

            foreach ($page as $entry) {
                $uuid = self::payloadUuid($entry['fields']['payload'] ?? null);

                if ($uuid !== null) {
                    $uuids[$uuid] = true;
                }
            }

            $from = $page === [] ? $from : '('.$page[count($page) - 1]['id'];
        } while (count($page) === self::PAGE);

        return $this->deadLetters = $uuids;
    }

    /**
     * The uuid of a stored payload, or null when it has none or cannot be decoded.
     */
    private static function payloadUuid(mixed $payload): ?string
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $uuid = StreamQueue::decodePayload($payload)['uuid'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
