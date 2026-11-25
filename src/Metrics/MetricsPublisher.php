<?php

declare(strict_types=1);

namespace Webpatser\Torque\Metrics;

use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\RedisClient;
use Illuminate\Support\Arr;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeName;
use Webpatser\Torque\Support\WorkerId;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;
use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Redis\createRedisConnector;

/**
 * Publishes worker metrics to Redis hashes for dashboard consumption.
 *
 * Each worker process publishes its own metrics via {@see publishWorkerMetrics()},
 * and each master aggregates its own host's workers into a per-host summary hash
 * via {@see publishAggregate()}; readers sum the hosts with
 * {@see getAggregatedMetrics()}.
 *
 * Keys use a configurable prefix and include a heartbeat TTL so stale worker
 * entries auto-expire if a process crashes without cleanup.
 */
final class MetricsPublisher
{
    private const int HEARTBEAT_TTL_SECONDS = 60;

    /**
     * TTL on each host's `{prefix}metrics:live:{host}` aggregate, and the
     * window of the `{prefix}metrics:live` index. A dead publisher must read
     * as "no data" (dashboards and torque:status render placeholders) rather
     * than serving stale numbers forever, and a host that went away drops out
     * of the cluster sums once this has passed.
     */
    private const int AGGREGATE_TTL_SECONDS = 30;

    /**
     * Width of the finest rollup bucket, and the unit every per-minute rate is
     * expressed in.
     */
    private const int BUCKET_SECONDS = 60;

    public const string TIER_MINUTE = 'minute';

    public const string TIER_HOUR = 'hour';

    public const string TIER_DAY = 'day';

    /**
     * Bucket width per tier. Coarser tiers cost one field per bucket, which is
     * what makes a two-year history affordable: 1440 minute fields for a day,
     * 2160 hour fields for 90 days, 730 day fields for two years.
     *
     * @var array<string, int>
     */
    private const array TIER_SECONDS = [
        self::TIER_MINUTE => 60,
        self::TIER_HOUR => 3600,
        self::TIER_DAY => 86400,
    ];

    /**
     * Bucket width of a tier in seconds, for callers that need to align a
     * window to the same boundaries the series readers use.
     */
    #[\NoDiscard]
    public static function tierSeconds(string $tier): int
    {
        return self::TIER_SECONDS[$tier] ?? self::TIER_SECONDS[self::TIER_MINUTE];
    }

    /**
     * Add an outcome pair to one bucket of one rollup hash.
     *
     * The value is a compact "processed,failed" string, so it cannot be
     * HINCRBY'd. Doing the read-modify-write in Lua keeps it atomic and costs
     * a single round trip, and touching exactly one key keeps it valid on
     * Redis Cluster (a MULTI or a multi-key script would hit CROSSSLOT).
     *
     * KEYS[1] rollup hash   ARGV[1] bucket epoch
     * ARGV[2] processed delta   ARGV[3] failed delta
     */
    private const string LUA_ADD_OUTCOME = <<<'LUA'
local current = redis.call('HGET', KEYS[1], ARGV[1])
local processed, failed = 0, 0

if current then
    local p, f = string.match(current, '^(%d+),(%d+)$')
    if p then
        processed, failed = tonumber(p), tonumber(f)
    end
end

processed = processed + tonumber(ARGV[2])
failed = failed + tonumber(ARGV[3])

redis.call('HSET', KEYS[1], ARGV[1], processed .. ',' .. failed)

return 1
LUA;

    /**
     * Fold a batch of gauge samples into one bucket of one gauge hash.
     *
     * Every metric of a tier lives in the same hash under `{epoch}:{metric}`,
     * so a whole tick is one round trip and one key, which keeps it valid on
     * Redis Cluster. The stored value is "sum,count,max": enough for an average
     * and a peak without keeping the samples themselves.
     *
     * KEYS[1] gauge hash   ARGV[1] bucket epoch
     * ARGV[2..] metric name / value pairs
     */
    /**
     * Several fields of one outcome hash in a single call, for the per-host
     * scope where one eval covers the whole fleet.
     *
     * ARGV comes in triples: field, processed, failed.
     */
    private const string LUA_ADD_OUTCOMES = <<<'LUA'
for i = 1, #ARGV, 3 do
    local current = redis.call('HGET', KEYS[1], ARGV[i])
    local processed, failed = 0, 0

    if current then
        local p, f = string.match(current, '^(%d+),(%d+)$')
        if p then
            processed, failed = tonumber(p), tonumber(f)
        end
    end

    redis.call('HSET', KEYS[1], ARGV[i],
        (processed + tonumber(ARGV[i + 1])) .. ',' .. (failed + tonumber(ARGV[i + 2])))
end

return 1
LUA;

    private const string LUA_ADD_GAUGES = <<<'LUA'
for i = 2, #ARGV, 2 do
    local field = ARGV[1] .. ':' .. ARGV[i]
    local value = tonumber(ARGV[i + 1])
    local current = redis.call('HGET', KEYS[1], field)
    local sum, count, max = 0, 0, value

    if current then
        local s, c, m = string.match(current, '^([^,]+),([^,]+),([^,]+)$')
        if s then
            sum, count, max = tonumber(s), tonumber(c), tonumber(m)
            if value > max then max = value end
        end
    end

    redis.call('HSET', KEYS[1], field, (sum + value) .. ',' .. (count + 1) .. ',' .. max)
end

return 1
LUA;

    /**
     * Add one job class's outcomes to a bucket of its rollup hash.
     *
     * Value is "processed,failed,runtimeSumMs,runtimeMaxMs". Runtime max is a
     * high-water mark rather than a sum, so it takes the larger of the two.
     *
     * KEYS[1] rollup hash   ARGV[1] bucket epoch
     * ARGV[2] processed   ARGV[3] failed   ARGV[4] runtime sum   ARGV[5] runtime max
     */
    private const string LUA_ADD_JOB_OUTCOME = <<<'LUA'
local current = redis.call('HGET', KEYS[1], ARGV[1])
local processed, failed, runtime, peak = 0, 0, 0, 0

if current then
    local p, f, r, m = string.match(current, '^([^,]+),([^,]+),([^,]+),([^,]+)$')
    if p then
        processed, failed, runtime, peak = tonumber(p), tonumber(f), tonumber(r), tonumber(m)
    end
end

processed = processed + tonumber(ARGV[2])
failed = failed + tonumber(ARGV[3])
runtime = runtime + tonumber(ARGV[4])

if tonumber(ARGV[5]) > peak then
    peak = tonumber(ARGV[5])
end

redis.call('HSET', KEYS[1], ARGV[1], processed .. ',' .. failed .. ',' .. runtime .. ',' .. peak)

return 1
LUA;

    /** Fleet-wide average job latency in milliseconds. */
    public const string GAUGE_LATENCY = 'avg_latency_ms';

    /** Coroutine slots busy across the fleet. */
    public const string GAUGE_CONCURRENT = 'concurrent';

    /** Resident memory of the whole fleet, in megabytes. */
    public const string GAUGE_MEMORY = 'memory_mb';

    /**
     * Largest single worker's memory in megabytes. This is the number that
     * decides whether a worker gets recycled, so the fleet total hides it.
     */
    public const string GAUGE_WORKER_MEMORY_PEAK = 'worker_memory_peak_mb';

    /** Jobs waiting across every configured stream. */
    public const string GAUGE_PENDING = 'pending';

    /** Jobs scheduled for later across every configured stream. */
    public const string GAUGE_DELAYED = 'delayed';

    /**
     * Gauges every master measures for the whole cluster rather than for its
     * own host. Queue depth comes from the shared streams, so each host's
     * sample is the same number and a sum would multiply it by the host count;
     * these stay in the unscoped `{bucket}:{metric}` field.
     *
     * @var list<string>
     */
    private const array CLUSTER_WIDE_GAUGES = [self::GAUGE_PENDING, self::GAUGE_DELAYED];

    /**
     * Fields of a host's live aggregate that add across hosts. Counts as
     * integers, the rest (throughput per second, megabytes) as floats.
     *
     * @var list<string>
     */
    private const array SUMMED_INT_FIELDS = ['concurrent', 'total_slots', 'jobs_processed', 'jobs_failed', 'workers'];

    /** @var list<string> */
    private const array SUMMED_FLOAT_FIELDS = ['throughput', 'memory_mb'];

    /**
     * Fields every master computes from the cluster-wide minute rollup, so
     * every host carries the same figure. The freshest host's copy is used.
     *
     * @var list<string>
     */
    private const array CLUSTER_WIDE_FIELDS = ['throughput_1m', 'throughput_5m', 'throughput_smoothed', 'jobs_last_hour'];

    /**
     * Per-host gauges. Every host shares one hash per tier,
     * `{prefix}metrics:gauge:{tier}:host`, under field
     * `{bucket}:{host}:{metric}` holding "sum,count,max"; the host is part of
     * the field, not of the key, so a publish tick costs one round trip per
     * tier however large the fleet is.
     *
     * Slots and memory sum across the host's workers; the peak is the largest
     * single worker on the box. `workers` is the fleet size there, which is
     * what lets a reader tell an idle host from one whose workers went away.
     * No latency: a worker's average is over its whole life, so bucketing it
     * would draw a flat line that reads like a measurement and is not one.
     */
    public const string GAUGE_HOST_BUSY_SLOTS = 'busy_slots';

    public const string GAUGE_HOST_TOTAL_SLOTS = 'total_slots';

    public const string GAUGE_HOST_MEMORY = 'memory_mb';

    public const string GAUGE_HOST_WORKER_MEMORY_PEAK = 'worker_memory_peak_mb';

    public const string GAUGE_HOST_WORKERS = 'workers';

    /**
     * Weight of the newest sample in the smoothed throughput EMA. At the
     * default one-second publish interval this settles in a few seconds:
     * fast enough to track a real ramp, slow enough that the needle glides.
     */
    private const float SMOOTHING_ALPHA = 0.3;

    private ?RedisClient $redis = null;

    /**
     * Last pruned bucket per rollup key. Pruning walks the hash, so each key is
     * pruned at most once per bucket of its own tier: the minute tier once a
     * minute, the day tier once a day.
     *
     * @var array<string, int>
     */
    private array $lastPrunedBucket = [];

    /**
     * The last host-index touch, as `[epoch, sorted hosts]`.
     *
     * A publish tick records outcomes and gauges for the same fleet, so without
     * this the second of the two repeats the ZADD the first just made.
     *
     * @var array{0: int, 1: list<string>}|null
     */
    private ?array $lastHostIndexTouch = null;

    /**
     * Hash fields the last {@see gaugeSeriesMulti()} call asked for, so a test
     * can bound what a range read costs by count rather than by timing.
     */
    private int $fieldsRequested = 0;

    /**
     * Earliest epoch this instance wrote to the first-seen index, per host, so
     * a tick does not repeat a ZADD that cannot lower the score any further.
     *
     * @var array<string, int>
     */
    private array $firstSeenWritten = [];

    /**
     * Earliest epoch per host whose first-seen write has not succeeded yet,
     * kept so a retry after a failed ZADD still carries that epoch.
     *
     * @var array<string, int>
     */
    private array $firstSeenPending = [];

    /**
     * @param  array<string, mixed>  $settings  The `torque.metrics` config block (enabled, retention, rollups).
     * @param  float|null  $readTimeout  Seconds to wait for a reply; null or zero waits forever.
     */
    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix = 'torque:',
        private readonly array $settings = [],
        private readonly ?float $readTimeout = null,
    ) {}

    /**
     * Write a hash and set its expiry in one round trip.
     *
     * KEYS[1] hash   ARGV[1] TTL seconds   ARGV[2..] field, value pairs
     */
    private const string LUA_HSET_EXPIRE = <<<'LUA'
redis.call('HSET', KEYS[1], unpack(ARGV, 2))
redis.call('EXPIRE', KEYS[1], ARGV[1])

return 1
LUA;

    /**
     * Add a member to a sorted set and set the set's expiry in one round trip.
     *
     * KEYS[1] sorted set   ARGV[1] score   ARGV[2] member   ARGV[3] TTL seconds
     */
    private const string LUA_ZADD_EXPIRE = <<<'LUA'
redis.call('ZADD', KEYS[1], ARGV[1], ARGV[2])
redis.call('EXPIRE', KEYS[1], ARGV[3])

return 1
LUA;

    /**
     * Publish a single worker's metrics to its dedicated Redis hash.
     *
     * Key: `{prefix}worker:{workerId}`
     * A TTL is set on every publish as a heartbeat, if the worker dies, the
     * key expires and disappears from the dashboard automatically.
     *
     * Two round trips: the hash with its expiry, then the index entry. They
     * stay two scripts because the keys can sit in different cluster slots,
     * and the index goes second so a failed hash write never adds a member.
     *
     * `master` is the id of the master that spawned the worker, which is how a
     * master tells its own workers from another master's on the same node;
     * it is left out when unknown (a worker started by an older master), so
     * readers fall back to host and PID. `group` is the worker group, for
     * display only.
     */
    public function publishWorkerMetrics(string $workerId, WorkerSnapshot $snapshot, ?string $master = null, ?string $group = null): void
    {
        $redis = $this->getRedis();
        $key = $this->prefix.'worker:'.$workerId;
        $parsed = WorkerId::parse($workerId);

        $identity = [];

        if ($master !== null && $master !== '') {
            $identity[] = 'master';
            $identity[] = $master;
        }

        if ($group !== null && $group !== '') {
            $identity[] = 'group';
            $identity[] = $group;
        }

        $redis->eval(self::LUA_HSET_EXPIRE, [$key], [
            (string) self::HEARTBEAT_TTL_SECONDS,
            'pid', (string) ($parsed->pid ?? getmypid()),
            'host', $parsed->host,
            ...$identity,
            'jobs_processed', (string) $snapshot->jobsProcessed,
            'jobs_failed', (string) $snapshot->jobsFailed,
            'active_slots', (string) $snapshot->activeSlots,
            'total_slots', (string) $snapshot->totalSlots,
            'avg_latency_ms', (string) round($snapshot->averageLatencyMs, 2),
            'slot_usage', (string) round($snapshot->slotUsageRatio, 4),
            'memory_bytes', (string) $snapshot->memoryBytes,
            'last_heartbeat', (string) $snapshot->timestamp,
            // Per-stream counters ride along as JSON: one field regardless of
            // how many streams the worker has touched.
            'per_queue', json_encode($snapshot->perQueue) ?: '{}',
            // Same trick for per-class counters: one field, whatever the number
            // of distinct job classes this worker has run.
            'per_job', json_encode($snapshot->perJob) ?: '{}',
        ]);

        // Index for getAllWorkerMetrics(), which would otherwise SCAN the whole
        // keyspace. The hash TTL stays the liveness authority; readers drop
        // members whose hash is gone, so no clock is compared across hosts.
        $redis->eval(self::LUA_ZADD_EXPIRE, [$this->workerIndexKey()], [
            (string) time(), $workerId, (string) self::HEARTBEAT_TTL_SECONDS,
        ]);
    }

    private function workerIndexKey(): string
    {
        return $this->prefix.'metrics:workers';
    }

    /**
     * Publish aggregated metrics across this host's workers.
     *
     * Key: `{prefix}metrics:live:{host}`, see {@see publishAggregate()}.
     *
     * @param  WorkerSnapshot[]  $workerSnapshots
     */
    public function publishAggregatedMetrics(array $workerSnapshots, ?string $host = null): void
    {
        $totalProcessed = 0;
        $totalFailed = 0;
        $totalActive = 0;
        $totalSlots = 0;
        $weightedLatencySum = 0.0;
        $totalJobsForLatency = 0;
        $totalMemory = 0;

        foreach ($workerSnapshots as $snapshot) {
            $totalProcessed += $snapshot->jobsProcessed;
            $totalFailed += $snapshot->jobsFailed;
            $totalActive += $snapshot->activeSlots;
            $totalSlots += $snapshot->totalSlots;
            $totalMemory += $snapshot->memoryBytes;

            // Weight the latency contribution by the number of jobs this worker handled.
            $workerJobs = $snapshot->jobsProcessed + $snapshot->jobsFailed;
            $weightedLatencySum += $snapshot->averageLatencyMs * $workerJobs;
            $totalJobsForLatency += $workerJobs;
        }

        $weightedAvgLatency = $totalJobsForLatency > 0
            ? $weightedLatencySum / $totalJobsForLatency
            : 0.0;

        $workerCount = count($workerSnapshots);

        // Throughput: total processed / seconds since earliest worker snapshot.
        // Falls back to 0 if no workers are reporting.
        $throughput = 0.0;
        if ($workerCount > 0 && $totalProcessed > 0) {
            $earliestTimestamp = min(array_map(
                static fn (WorkerSnapshot $s): int => $s->timestamp,
                $workerSnapshots,
            ));
            $elapsed = time() - $earliestTimestamp;

            // Guard against division by zero on the very first tick.
            $throughput = $elapsed > 0 ? $totalProcessed / $elapsed : (float) $totalProcessed;
        }

        $now = time();

        $this->writeHostAggregate(self::normaliseHost($host ?? self::localHost()), $now,
            'throughput', (string) round($throughput, 2),
            'concurrent', (string) $totalActive,
            'total_slots', (string) $totalSlots,
            'avg_latency', (string) round($weightedAvgLatency, 2),
            'jobs_processed', (string) $totalProcessed,
            'jobs_failed', (string) $totalFailed,
            'memory_mb', (string) round($totalMemory / 1_048_576, 2),
            'workers', (string) $workerCount,
            'updated_at', (string) $now,
        );
    }

    /**
     * Publish an already-aggregated summary (the {@see aggregateFromWorkers()}
     * shape) for one host. Used by the master's monitor loop, which aggregates
     * from its own workers' hashes and computes real throughput from
     * jobs_processed deltas between ticks.
     *
     * Every host has a hash of its own, `{prefix}metrics:live:{host}`, listed
     * in the `{prefix}metrics:live` index; {@see getAggregatedMetrics()} sums
     * them. A single shared hash would hold whichever master wrote last, which
     * on a multi-host fleet is one host's share rather than the fleet.
     *
     * @param  array<string, mixed>  $aggregate
     * @param  string|null  $host  The host the aggregate describes; null is this machine.
     */
    public function publishAggregate(array $aggregate, ?string $host = null): void
    {
        $host = self::normaliseHost($host ?? self::localHost());

        // Rolling rates read off the persisted minute buckets. `throughput`
        // stays the instantaneous per-second delta for backwards compatibility,
        // but a queue that receives one burst every few minutes reads as either
        // zero or a five-figure spike there, which is why the gauge uses these.
        $now = time();
        $buckets = $this->minuteBuckets(60, $now);
        $perMinute5 = self::perMinuteRate($buckets, 5, $now);
        $smoothed = self::SMOOTHING_ALPHA * $perMinute5
            + (1 - self::SMOOTHING_ALPHA) * $this->previousSmoothedThroughput($host, $perMinute5);

        $this->writeHostAggregate($host, $now,
            'throughput', (string) round(Narrow::float($aggregate['throughput'] ?? null), 2),
            'throughput_1m', (string) round(self::perMinuteRate($buckets, 1, $now), 2),
            'throughput_5m', (string) round($perMinute5, 2),
            'throughput_smoothed', (string) round($smoothed, 2),
            'jobs_last_hour', (string) array_sum($buckets),
            'concurrent', (string) Narrow::int($aggregate['concurrent'] ?? null),
            'total_slots', (string) Narrow::int($aggregate['total_slots'] ?? null),
            'avg_latency', (string) round(Narrow::float($aggregate['avg_latency'] ?? null), 2),
            'jobs_processed', (string) Narrow::int($aggregate['jobs_processed'] ?? null),
            'jobs_failed', (string) Narrow::int($aggregate['jobs_failed'] ?? null),
            'memory_mb', (string) round(Narrow::float($aggregate['memory_mb'] ?? null), 2),
            'workers', (string) Narrow::int($aggregate['workers'] ?? null),
            'updated_at', (string) $now,
        );
    }

    /**
     * Write one host's live aggregate and list it in the index.
     *
     * The hash expires after {@see AGGREGATE_TTL_SECONDS}, and readers only
     * look at index entries scored within the same window, so a host that went
     * away drops out of the sums on its own. Every command touches one key,
     * which keeps this valid on Redis Cluster.
     */
    private function writeHostAggregate(string $host, int $now, string ...$fields): void
    {
        $redis = $this->getRedis();
        $key = $this->liveKey($host);
        $index = $this->prefix.'metrics:live';

        $redis->execute('HSET', $key, ...$fields);
        $redis->execute('EXPIRE', $key, (string) self::AGGREGATE_TTL_SECONDS);

        $redis->execute('ZADD', $index, (string) $now, $host);
        $redis->execute('ZREMRANGEBYSCORE', $index, '-inf', '('.($now - self::AGGREGATE_TTL_SECONDS));
        $redis->execute('EXPIRE', $index, (string) self::AGGREGATE_TTL_SECONDS);
    }

    private function liveKey(string $host): string
    {
        return $this->prefix.'metrics:live:'.$host;
    }

    /**
     * This machine's node name, the same one its workers put in their ids.
     * Identical to the hostname while no node name is configured.
     */
    #[\NoDiscard]
    public static function localHost(): string
    {
        return NodeName::current();
    }

    /**
     * Record the outcomes finished since the previous tick into every tier.
     *
     * Keys: `{prefix}metrics:rollup:{tier}` cluster-wide and
     * `{prefix}metrics:rollup:{tier}:{queue}` per stream, field = bucket start
     * epoch, value = "processed,failed".
     *
     * The master already computes these deltas every second for the
     * instantaneous throughput; persisting them is what gives the dashboard a
     * rate that survives a page reload and a history that outlives the day.
     *
     * Costs one round trip per tier per scope, so 3 x (1 + active streams) per
     * publish tick, and nothing at all while the cluster is idle.
     *
     * @param  array<string, array{0: int, 1: int}>  $perQueue  Queue name => [processed, failed].
     */
    public function recordOutcomes(int $processed, int $failed, array $perQueue = [], ?int $now = null): void
    {
        if (! $this->metricsEnabled()) {
            return;
        }

        // A worker that restarts resets its counters, so the master can hand us
        // a negative delta. Clamping beats writing nonsense into a history that
        // is kept for two years.
        $scopes = [];

        if (max(0, $processed) > 0 || max(0, $failed) > 0) {
            $scopes[] = [null, max(0, $processed), max(0, $failed)];
        }

        foreach ($perQueue as $queue => $outcome) {
            $queueProcessed = max(0, (int) $outcome[0]);
            $queueFailed = max(0, (int) $outcome[1]);

            if ($queueProcessed > 0 || $queueFailed > 0) {
                $scopes[] = [(string) $queue, $queueProcessed, $queueFailed];
            }
        }

        if ($scopes === []) {
            return;
        }

        $now ??= time();
        $redis = $this->getRedis();

        foreach (self::TIER_SECONDS as $tier => $seconds) {
            $bucket = intdiv($now, $seconds) * $seconds;

            foreach ($scopes as [$queue, $queueProcessed, $queueFailed]) {
                $key = $this->rollupKey($tier, $queue);

                $redis->eval(self::LUA_ADD_OUTCOME, [$key], [(string) $bucket, $queueProcessed, $queueFailed]);

                $this->pruneKey($key, $tier, $bucket);
            }
        }
    }

    /**
     * Record a batch of gauge samples into every tier.
     *
     * Counters answer "how many"; gauges answer "how deep, how slow, how big"
     * at a point in time. One sample per metric per publish tick is folded into
     * "sum,count,max" per bucket, which is what lets the dashboard draw an
     * average line and a peak without storing 86400 samples a day.
     *
     * Costs exactly one round trip per tier, whatever the number of metrics.
     *
     * With a host, every metric except the cluster-wide queue depths is folded
     * under `{bucket}:{host}:{metric}`, so each master writes only its own
     * host's sample and {@see gaugeSeriesMulti()} combines the hosts on read.
     * Folding every master into one field would average the hosts: two boxes
     * with 10 busy slots each read as 10 rather than 20. Without a host the
     * samples land in the unscoped `{bucket}:{metric}` field, the layout
     * before host scoping, which readers still use for buckets no host wrote.
     *
     * @param  array<string, float|int>  $samples  Metric name => current value.
     */
    public function recordGauges(array $samples, ?int $now = null, ?string $host = null): void
    {
        if (! $this->metricsEnabled() || $samples === []) {
            return;
        }

        $now ??= time();
        $redis = $this->getRedis();
        $host = $host === null ? null : self::normaliseHost($host);

        foreach (self::TIER_SECONDS as $tier => $seconds) {
            $bucket = intdiv($now, $seconds) * $seconds;
            $key = $this->gaugeKey($tier);
            $args = [(string) $bucket];

            foreach ($samples as $metric => $value) {
                $metric = (string) $metric;
                $args[] = $host === null || in_array($metric, self::CLUSTER_WIDE_GAUGES, true) ? $metric : $host.':'.$metric;
                $args[] = (string) round((float) $value, 3);
            }

            $redis->eval(self::LUA_ADD_GAUGES, [$key], $args);

            $this->pruneKey($key, $tier, $bucket);
        }

        if ($host !== null) {
            $this->touchHostIndex([$host], $now);
        }
    }

    /**
     * Record per-job-class outcomes into every tier.
     *
     * Deliberately no memory figure: fibers run many jobs concurrently in one
     * process, so a before/after memory delta around a single job measures
     * whatever its neighbours happened to be doing. Runtime is per job and
     * therefore meaningful; memory is not.
     *
     * @param  array<string, array{0: int, 1: int, 2: float, 3: float}>  $perJob
     *                                                                            Class => [processed, failed, runtimeSumMs, runtimeMaxMs].
     */
    public function recordJobOutcomes(array $perJob, ?int $now = null): void
    {
        if (! $this->metricsEnabled() || $perJob === []) {
            return;
        }

        $now ??= time();
        $redis = $this->getRedis();
        $recorded = [];

        foreach ($perJob as $class => $outcome) {
            $processed = max(0, (int) $outcome[0]);
            $failed = max(0, (int) $outcome[1]);
            $runtimeSum = max(0.0, (float) $outcome[2]);
            $runtimeMax = max(0.0, (float) $outcome[3]);

            if ($processed === 0 && $failed === 0) {
                continue;
            }

            $class = (string) $class;
            $recorded[] = $class;

            foreach (self::TIER_SECONDS as $tier => $seconds) {
                $bucket = intdiv($now, $seconds) * $seconds;
                $key = $this->jobKey($tier, $class);

                $redis->eval(self::LUA_ADD_JOB_OUTCOME, [$key], [
                    (string) $bucket,
                    $processed,
                    $failed,
                    (string) round($runtimeSum, 3),
                    (string) round($runtimeMax, 3),
                ]);

                $this->pruneKey($key, $tier, $bucket);
            }
        }

        if ($recorded !== []) {
            // The class list cannot expire per member, so it is pruned against
            // the day tier whenever the day rolls over.
            $redis->execute('SADD', $this->jobIndexKey(), ...array_unique($recorded));

            $this->pruneJobIndex(intdiv($now, self::TIER_SECONDS[self::TIER_DAY]) * self::TIER_SECONDS[self::TIER_DAY]);
        }
    }

    /**
     * Record the outcomes finished since the previous tick, per host.
     *
     * History hangs off the host, not the worker id. A worker mints a fresh
     * `{host}-{pid}-{hex}` name on every start, so with a 24 hour lifetime a
     * 16-worker fleet would produce some 5800 ids a year, each with three tiers
     * of keys and a row in a 90 day view nobody wants to read. Hosts are
     * machines: bounded, stable across rotation, and already what the workers
     * screen presents as the identity.
     *
     * Every host lives in one hash per tier (field `{bucket}:{host}`) rather
     * than in a key of its own, which is what keeps the write cost independent
     * of fleet size: three evals a tick whether there are two hosts or fifty,
     * against the per-stream scope's three per stream. The workers screen wants
     * every host at once anyway, so it reads two hashes instead of two per host.
     *
     * On Kubernetes the hostname is the pod name, which is re-minted on every
     * rollout. The index below bounds what the screen shows, but the keys still
     * grow with pods per retention window; see the note in config/torque.php.
     *
     * @param  array<string, array{0: int, 1: int}>  $perHost  Host => [processed, failed] delta.
     */
    public function recordHostOutcomes(array $perHost, ?int $now = null): void
    {
        if (! $this->metricsEnabled() || $perHost === []) {
            return;
        }

        $scopes = [];

        foreach ($perHost as $host => $outcome) {
            $host = self::normaliseHost((string) $host);
            $processed = max(0, (int) $outcome[0]);
            $failed = max(0, (int) $outcome[1]);

            if ($processed > 0 || $failed > 0) {
                $scopes[$host] = [$processed, $failed];
            }
        }

        if ($scopes === []) {
            return;
        }

        $now ??= time();
        $redis = $this->getRedis();

        foreach (self::TIER_SECONDS as $tier => $seconds) {
            $bucket = intdiv($now, $seconds) * $seconds;
            $key = $this->hostRollupKey($tier);
            $args = [];

            foreach ($scopes as $host => [$processed, $failed]) {
                $args[] = $bucket.':'.$host;
                $args[] = $processed;
                $args[] = $failed;
            }

            $redis->eval(self::LUA_ADD_OUTCOMES, [$key], $args);

            $this->pruneKey($key, $tier, $bucket);
        }

        $this->touchHostIndex(array_keys($scopes), $now);
    }

    /**
     * Record one gauge sample per metric per host into every tier.
     *
     * Same "sum,count,max" fold as {@see recordGauges()} under a host-scoped
     * field, so it reuses that script unchanged and costs one round trip per
     * tier however many hosts and metrics there are.
     *
     * During a reload takeover two masters on the same box each see half the
     * fleet, so for those few seconds a host's gauge is the mean of two partial
     * samples rather than the whole. The counters above are unaffected (they
     * add), and widening the script to distinguish concurrent publishers is not
     * worth it for a window that short.
     *
     * @param  array<string, array<string, float|int>>  $samples  Host => metric => value.
     */
    public function recordHostGauges(array $samples, ?int $now = null): void
    {
        if (! $this->metricsEnabled() || $samples === []) {
            return;
        }

        $now ??= time();
        $redis = $this->getRedis();
        $hosts = [];

        foreach (self::TIER_SECONDS as $tier => $seconds) {
            $bucket = intdiv($now, $seconds) * $seconds;
            $key = $this->hostGaugeKey($tier);
            $args = [(string) $bucket];

            foreach ($samples as $host => $metrics) {
                $host = self::normaliseHost((string) $host);

                if ($host === '' || $metrics === []) {
                    continue;
                }

                $hosts[$host] = true;

                foreach ($metrics as $metric => $value) {
                    $args[] = $host.':'.$metric;
                    $args[] = (string) round((float) $value, 3);
                }
            }

            if (count($args) < 3) {
                return;
            }

            $redis->eval(self::LUA_ADD_GAUGES, [$key], $args);

            $this->pruneKey($key, $tier, $bucket);
        }

        $this->touchHostIndex(array_keys($hosts), $now);
    }

    /**
     * Hash fields the last {@see gaugeSeriesMulti()} call requested, gauge and
     * latency-weight reads together; reset at the start of every call.
     */
    #[\NoDiscard]
    public function fieldsRequested(): int
    {
        return $this->fieldsRequested;
    }

    /**
     * Hosts the rollups saw at or after an epoch, with the epoch they were
     * last seen at.
     *
     * The index is scored by last-seen, so "which hosts were alive in the
     * selected range" is one call rather than a read of every host's series.
     * A host gone for longer than the range simply is not returned.
     *
     * @return array<string, int> Host => last-seen unix epoch.
     */
    #[\NoDiscard]
    public function hostsSeen(int $sinceEpoch = 0): array
    {
        if (! $this->metricsEnabled()) {
            return [];
        }

        $members = $this->getRedis()->execute(
            'ZRANGEBYSCORE',
            $this->hostIndexKey(),
            (string) max(0, $sinceEpoch),
            '+inf',
            'WITHSCORES',
        );

        if (! is_array($members)) {
            return [];
        }

        // Flat [member, score, member, score, ...] reply.
        $hosts = [];
        $count = count($members);

        for ($i = 0; $i + 1 < $count; $i += 2) {
            $hosts[Narrow::string($members[$i])] = Narrow::int($members[$i + 1]);
        }

        ksort($hosts);

        return $hosts;
    }

    /**
     * Per-bucket outcomes for several hosts, oldest first and gap-filled.
     *
     * Reads exactly the fields the range covers rather than the whole hash:
     * the minute tier holds a full day for every host at once, so a HGETALL
     * here would pull a day of every machine on every dashboard poll.
     *
     * @param  list<string>  $hosts
     * @return array<string, array<int, array{processed: int, failed: int}>>
     */
    #[\NoDiscard]
    public function hostSeriesMulti(array $hosts, string $tier, int $count, ?int $now = null): array
    {
        $buckets = self::bucketRange($tier, $count, $now);
        $hosts = array_values(array_unique(array_map(self::normaliseHost(...), $hosts)));
        $fields = [];

        foreach ($hosts as $host) {
            foreach ($buckets as $bucket) {
                $fields[] = $bucket.':'.$host;
            }
        }

        $raw = $this->metricsEnabled() ? $this->readFields($this->hostRollupKey($tier), $fields) : [];
        $series = [];

        foreach ($hosts as $host) {
            $series[$host] = [];

            foreach ($buckets as $bucket) {
                $series[$host][$bucket] = self::parseOutcome($raw[$bucket.':'.$host] ?? null);
            }
        }

        return $series;
    }

    /**
     * Several gauges for several hosts, off a single read of the tier's hash.
     *
     * @param  list<string>  $metrics
     * @param  list<string>  $hosts
     * @return array<string, array<string, array<int, array{avg: float, max: float}>>> Host => metric => series.
     */
    #[\NoDiscard]
    public function hostGaugeSeriesMulti(array $metrics, array $hosts, string $tier, int $count, ?int $now = null): array
    {
        $buckets = self::bucketRange($tier, $count, $now);
        $hosts = array_values(array_unique(array_map(self::normaliseHost(...), $hosts)));
        $metrics = array_map(strval(...), $metrics);
        $fields = [];

        foreach ($hosts as $host) {
            foreach ($metrics as $metric) {
                foreach ($buckets as $bucket) {
                    $fields[] = $bucket.':'.$host.':'.$metric;
                }
            }
        }

        $raw = $this->metricsEnabled() ? $this->readFields($this->hostGaugeKey($tier), $fields) : [];
        $series = [];

        foreach ($hosts as $host) {
            foreach ($metrics as $metric) {
                $series[$host][$metric] = [];

                foreach ($buckets as $bucket) {
                    $series[$host][$metric][$bucket] = self::parseGauge($raw[$bucket.':'.$host.':'.$metric] ?? null);
                }
            }
        }

        return $series;
    }

    /**
     * Note every host that reported this tick, and sweep the index once a day.
     *
     * `GT` so two masters on the same box during a reload takeover cannot walk
     * a score backwards.
     *
     * Both recorders call this, and a tick calls both with the same fleet, so an
     * identical host set inside the same second is skipped. The comparison is on
     * the set itself, never on the clock alone: a host the first call did not
     * carry still reaches the index in the second.
     *
     * @param  list<string>  $hosts
     */
    private function touchHostIndex(array $hosts, int $now): void
    {
        if ($hosts === []) {
            return;
        }

        sort($hosts);

        if ($this->lastHostIndexTouch === [$now, $hosts]) {
            return;
        }

        $this->lastHostIndexTouch = [$now, $hosts];

        $args = [];

        foreach ($hosts as $host) {
            $args[] = (string) $now;
            $args[] = $host;
        }

        $this->getRedis()->execute('ZADD', $this->hostIndexKey(), 'GT', ...$args);

        // First sighting, `LT` so the score only ever moves back. Range reads
        // use it to skip the buckets before a host existed; written once per
        // host per process, since a later epoch could never lower it. Marked
        // written only once the ZADD succeeded: a failed write stays pending
        // with its epoch, so the retry still carries the earliest sighting
        // rather than letting a later process record a later one.
        $first = [];
        $pending = [];

        foreach ($hosts as $host) {
            $earliest = min($this->firstSeenPending[$host] ?? $now, $now);

            if (($this->firstSeenWritten[$host] ?? PHP_INT_MAX) > $earliest) {
                $this->firstSeenPending[$host] = $earliest;
                $pending[$host] = $earliest;
                $first[] = (string) $earliest;
                $first[] = $host;
            }
        }

        if ($first !== []) {
            $this->getRedis()->execute('ZADD', $this->hostFirstSeenKey(), 'LT', ...$first);

            foreach ($pending as $host => $epoch) {
                $this->firstSeenWritten[$host] = $epoch;
                unset($this->firstSeenPending[$host]);
            }
        }

        $this->pruneHostIndex(intdiv($now, self::TIER_SECONDS[self::TIER_DAY]) * self::TIER_SECONDS[self::TIER_DAY]);
    }

    /**
     * The bucket start epochs a (tier, count) window covers, oldest first.
     *
     * @return list<int>
     */
    private static function bucketRange(string $tier, int $count, ?int $now = null): array
    {
        $seconds = self::TIER_SECONDS[$tier]
            ?? throw new \InvalidArgumentException("Unknown metrics tier [{$tier}].");

        $count = max(1, $count);
        $now ??= time();
        $current = intdiv($now, $seconds) * $seconds;

        $buckets = [];

        for ($bucket = $current - ($count - 1) * $seconds; $bucket <= $current; $bucket += $seconds) {
            $buckets[] = $bucket;
        }

        return $buckets;
    }

    /**
     * Read a gauge's average and peak per bucket, oldest first and gap-filled.
     *
     * @return array<int, array{avg: float, max: float}> Bucket start epoch => sample.
     */
    #[\NoDiscard]
    public function gaugeSeries(string $metric, string $tier, int $count, ?int $now = null): array
    {
        return $this->gaugeSeriesMulti([$metric], $tier, $count, $now)[$metric];
    }

    /**
     * Several gauges at once, cluster-wide, off one read of the tier's hash.
     *
     * Hosts write their own `{bucket}:{host}:{metric}` fields (see
     * {@see recordGauges()}); a bucket any host wrote is derived from those
     * alone, a bucket none did falls back to the unscoped field. That keeps
     * history from before host scoping readable, and an old master still
     * writing the unscoped field mid-deploy is never added on top of the hosts.
     *
     * How hosts combine per bucket:
     *  - concurrent, memory_mb: summed. Slots and resident memory add up across
     *    machines. The peak is the sum of the host peaks, an upper bound, since
     *    the hosts need not peak in the same second.
     *  - worker_memory_peak_mb: the largest host, for both average and peak; it
     *    is the single largest worker anywhere.
     *  - avg_latency_ms: the hosts' averages weighted by the jobs each finished
     *    in the bucket (the per-host rollup), plain mean when none finished
     *    any; the peak is the largest host peak.
     *  - pending, delayed: never host scoped; every master measures the same
     *    streams.
     *
     * Reads only the fields the window covers rather than the whole hash, and
     * asks each host only for the buckets between its first and last sighting
     * in the host indexes. Pods that get a new hostname per deploy stay in the
     * index for the day tier's retention, so asking every indexed host for the
     * whole window would grow with every pod ever started; this grows with the
     * buckets hosts were actually live in. A host without a first sighting
     * (indexed before that index existed) is asked from the window's start.
     *
     * @param  list<string>  $metrics
     * @return array<string, array<int, array{avg: float, max: float}>>
     */
    #[\NoDiscard]
    public function gaugeSeriesMulti(array $metrics, string $tier, int $count, ?int $now = null): array
    {
        $this->fieldsRequested = 0;
        $buckets = self::bucketRange($tier, $count, $now);
        $metrics = array_map(strval(...), $metrics);
        $live = $this->metricsEnabled() ? $this->liveBuckets($tier, $buckets) : [];
        $fields = [];

        foreach ($metrics as $metric) {
            foreach ($buckets as $bucket) {
                $fields[] = $bucket.':'.$metric;
            }

            if (in_array($metric, self::CLUSTER_WIDE_GAUGES, true)) {
                continue;
            }

            foreach ($live as $host => $hostBuckets) {
                foreach ($hostBuckets as $bucket) {
                    $fields[] = $bucket.':'.$host.':'.$metric;
                }
            }
        }

        $raw = [];

        if ($this->metricsEnabled()) {
            $raw = $this->readFields($this->gaugeKey($tier), $fields);
            $this->fieldsRequested += count($fields);
        }

        $jobs = [];

        if (in_array(self::GAUGE_LATENCY, $metrics, true) && $live !== []) {
            $jobFields = [];

            foreach ($live as $host => $hostBuckets) {
                foreach ($hostBuckets as $bucket) {
                    $jobFields[] = $bucket.':'.$host;
                }
            }

            $jobs = $this->readFields($this->hostRollupKey($tier), $jobFields);
            $this->fieldsRequested += count($jobFields);
        }

        $series = [];

        foreach ($metrics as $metric) {
            $series[$metric] = [];

            foreach ($buckets as $bucket) {
                $samples = [];
                $weights = [];

                foreach (array_keys($live) as $host) {
                    $value = $raw[$bucket.':'.$host.':'.$metric] ?? null;

                    if ($value !== null) {
                        $samples[] = self::parseGauge($value);
                        $outcome = self::parseOutcome($jobs[$bucket.':'.$host] ?? null);
                        $weights[] = $outcome['processed'] + $outcome['failed'];
                    }
                }

                $series[$metric][$bucket] = $samples === []
                    ? self::parseGauge($raw[$bucket.':'.$metric] ?? null)
                    : self::combineHostGauges($metric, $samples, $weights);
            }
        }

        return $series;
    }

    /**
     * Per host seen in the window, the window's buckets it can have written.
     *
     * The last sighting comes from the host index, the first from the
     * first-seen index; one ZRANGEBYSCORE and one ZMSCORE whatever the window.
     * Both are taken at write time with the same epoch as the samples, so a
     * bucket outside [first, last] holds nothing for that host.
     *
     * @param  list<int>  $buckets
     * @return array<string, list<int>> Host => bucket start epochs, oldest first.
     */
    private function liveBuckets(string $tier, array $buckets): array
    {
        $seen = $this->hostsSeen($buckets[0]);

        if ($seen === []) {
            return [];
        }

        $hosts = array_map(strval(...), array_keys($seen));
        $scores = $this->getRedis()->execute('ZMSCORE', $this->hostFirstSeenKey(), ...$hosts);
        $scores = is_array($scores) ? array_values($scores) : [];
        $seconds = self::TIER_SECONDS[$tier];
        $live = [];

        foreach ($hosts as $i => $host) {
            $first = $scores[$i] ?? null;
            $from = is_numeric($first) ? intdiv((int) $first, $seconds) * $seconds : null;
            $to = intdiv($seen[$host], $seconds) * $seconds;

            $hostBuckets = array_values(array_filter(
                $buckets,
                static fn (int $bucket): bool => ($from === null || $bucket >= $from) && $bucket <= $to,
            ));

            if ($hostBuckets !== []) {
                $live[$host] = $hostBuckets;
            }
        }

        return $live;
    }

    /**
     * Combine one bucket of one gauge across hosts, per the rules on
     * {@see gaugeSeriesMulti()}.
     *
     * @param  non-empty-list<array{avg: float, max: float}>  $samples
     * @param  list<int>  $weights  Jobs each host finished in the bucket, same order.
     * @return array{avg: float, max: float}
     */
    private static function combineHostGauges(string $metric, array $samples, array $weights): array
    {
        $averages = array_column($samples, 'avg');
        $peaks = array_column($samples, 'max');

        if ($metric === self::GAUGE_WORKER_MEMORY_PEAK) {
            return ['avg' => max($averages), 'max' => max($peaks)];
        }

        if ($metric === self::GAUGE_LATENCY) {
            $totalWeight = array_sum($weights);
            $average = $totalWeight > 0
                ? array_sum(array_map(static fn (float $avg, int $weight): float => $avg * $weight, $averages, $weights)) / $totalWeight
                : array_sum($averages) / count($averages);

            return ['avg' => round($average, 3), 'max' => max($peaks)];
        }

        return ['avg' => round(array_sum($averages), 3), 'max' => round(array_sum($peaks), 3)];
    }

    /**
     * Every job class the rollups have ever seen, alphabetically.
     *
     * @return list<string>
     */
    #[\NoDiscard]
    public function jobClasses(): array
    {
        if (! $this->metricsEnabled()) {
            return [];
        }

        $members = $this->getRedis()->execute('SMEMBERS', $this->jobIndexKey());

        if (! is_array($members)) {
            return [];
        }

        $classes = array_map(Narrow::string(...), Narrow::list($members));
        sort($classes);

        return $classes;
    }

    /**
     * Per-bucket outcomes for one job class, oldest first and gap-filled.
     *
     * @return array<int, array{processed: int, failed: int, runtimeSumMs: float, runtimeMaxMs: float}>
     */
    #[\NoDiscard]
    public function jobSeries(string $class, string $tier, int $count, ?int $now = null): array
    {
        return $this->jobSeriesMulti([$class], $tier, $count, $now)[$class];
    }

    /**
     * Per-bucket outcomes for several job classes, oldest first and gap-filled.
     *
     * Every class has a rollup key of its own, so no single command covers
     * them. The reads go out together instead, pipelined on the one connection:
     * one round trip for the whole jobs screen rather than one per class. Each
     * is a single-key HMGET of the window's buckets, which keeps it valid on
     * Redis Cluster and stops a 60 minute view from pulling back the full day
     * of minute buckets a HGETALL would.
     *
     * @param  list<string>  $classes
     * @return array<string, array<int, array{processed: int, failed: int, runtimeSumMs: float, runtimeMaxMs: float}>> Class => series.
     */
    #[\NoDiscard]
    public function jobSeriesMulti(array $classes, string $tier, int $count, ?int $now = null): array
    {
        $buckets = self::bucketRange($tier, $count, $now);
        $fields = array_map(strval(...), $buckets);
        $classes = array_values(array_unique(array_map(strval(...), $classes)));
        $raw = [];

        if ($this->metricsEnabled() && $classes !== []) {
            $raw = await(array_combine($classes, array_map(
                fn (string $class) => async(fn (): array => $this->readFields($this->jobKey($tier, $class), $fields)),
                $classes,
            )));
        }

        $series = [];

        foreach ($classes as $class) {
            $series[$class] = [];

            foreach ($buckets as $bucket) {
                $series[$class][$bucket] = self::parseJobOutcome($raw[$class][$bucket] ?? null);
            }
        }

        return $series;
    }

    /**
     * Totals for one job class since an epoch, off the finest covering tier.
     *
     * @return array{processed: int, failed: int, avgRuntimeMs: float, maxRuntimeMs: float}
     */
    #[\NoDiscard]
    public function jobTotals(string $class, int $sinceEpoch, ?int $now = null): array
    {
        $now ??= time();
        $tier = $this->finestTierCovering(max(0, $now - $sinceEpoch));
        $seconds = self::TIER_SECONDS[$tier];
        $from = intdiv($sinceEpoch, $seconds) * $seconds;

        $processed = 0;
        $failed = 0;
        $runtimeSum = 0.0;
        $runtimeMax = 0.0;

        foreach ($this->metricsEnabled() ? $this->readHash($this->jobKey($tier, $class)) : [] as $bucket => $value) {
            if ((int) $bucket < $from) {
                continue;
            }

            $outcome = self::parseJobOutcome($value);
            $processed += $outcome['processed'];
            $failed += $outcome['failed'];
            $runtimeSum += $outcome['runtimeSumMs'];
            $runtimeMax = max($runtimeMax, $outcome['runtimeMaxMs']);
        }

        // Runtime is only sampled on the jobs that finished, so the average
        // divides by those rather than by processed alone.
        $finished = $processed + $failed;

        return [
            'processed' => $processed,
            'failed' => $failed,
            'avgRuntimeMs' => $finished > 0 ? round($runtimeSum / $finished, 2) : 0.0,
            'maxRuntimeMs' => round($runtimeMax, 2),
        ];
    }

    /**
     * Read the last N buckets of one tier, oldest first and gap-filled.
     *
     * Gap filling matters for the charts: a quiet bucket must render as a zero
     * column, not disappear and compress the time axis.
     *
     * @param  string  $tier  One of the TIER_* constants.
     * @param  string|null  $queue  Null for the cluster-wide series.
     * @return array<int, array{processed: int, failed: int}> Bucket start epoch => outcomes.
     */
    #[\NoDiscard]
    public function series(string $tier, int $count, ?string $queue = null, ?int $now = null): array
    {
        $seconds = self::TIER_SECONDS[$tier]
            ?? throw new \InvalidArgumentException("Unknown metrics tier [{$tier}].");

        $count = max(1, $count);
        $now ??= time();
        $current = intdiv($now, $seconds) * $seconds;
        $oldest = $current - ($count - 1) * $seconds;

        $raw = $this->readRollup($tier, $queue);
        $series = [];

        for ($bucket = $oldest; $bucket <= $current; $bucket += $seconds) {
            $series[$bucket] = self::parseOutcome($raw[(string) $bucket] ?? null);
        }

        return $series;
    }

    /**
     * Totals from `$sinceEpoch` until now, read off the finest tier that still
     * covers the whole range.
     *
     * "Processed today" lands on the minute tier (a day of minutes is retained
     * by default) and is therefore exact; a year-to-date question falls through
     * to the day tier.
     *
     * @return array{processed: int, failed: int}
     */
    #[\NoDiscard]
    public function totalsSince(int $sinceEpoch, ?string $queue = null, ?int $now = null): array
    {
        $now ??= time();
        $tier = $this->finestTierCovering(max(0, $now - $sinceEpoch));
        $seconds = self::TIER_SECONDS[$tier];
        $from = intdiv($sinceEpoch, $seconds) * $seconds;

        $processed = 0;
        $failed = 0;

        foreach ($this->readRollup($tier, $queue) as $bucket => $value) {
            if ((int) $bucket < $from) {
                continue;
            }

            $outcome = self::parseOutcome($value);
            $processed += $outcome['processed'];
            $failed += $outcome['failed'];
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * Jobs finished per minute over the last N minutes, oldest first.
     *
     * The projection of the minute tier that the gauge and the rate helpers
     * work with.
     *
     * @return array<int, int> Minute start epoch => jobs finished in that minute.
     */
    #[\NoDiscard]
    public function minuteBuckets(int $minutes = 60, ?int $now = null, ?string $queue = null): array
    {
        return array_map(
            static fn (array $outcome): int => $outcome['processed'],
            $this->series(self::TIER_MINUTE, $minutes, $queue, $now),
        );
    }

    /**
     * Jobs per minute over the last N minutes of a bucket map.
     *
     * The newest bucket is still filling, so the divisor counts only the part
     * of the current minute that has actually elapsed. Without that, every
     * rate would dip to zero at the top of each minute and climb back.
     *
     * @param  array<int, int>  $buckets  Output of {@see minuteBuckets()}.
     */
    #[\NoDiscard]
    public static function perMinuteRate(array $buckets, int $minutes, ?int $now = null): float
    {
        if ($buckets === []) {
            return 0.0;
        }

        $minutes = max(1, $minutes);
        $now ??= time();
        $window = array_slice($buckets, -$minutes, preserve_keys: true);

        $elapsedInCurrent = max(1, $now % self::BUCKET_SECONDS) / self::BUCKET_SECONDS;
        $elapsedMinutes = (count($window) - 1) + $elapsedInCurrent;

        if ($elapsedMinutes <= 0) {
            return 0.0;
        }

        return array_sum($window) / $elapsedMinutes;
    }

    /**
     * Remove a worker's metrics key from Redis.
     *
     * Called during graceful shutdown so the worker doesn't appear as a
     * ghost in the dashboard until the TTL expires.
     */
    public function removeWorkerMetrics(string $workerId): void
    {
        $this->getRedis()->execute('DEL', $this->prefix.'worker:'.$workerId);
        $this->getRedis()->execute('ZREM', $this->workerIndexKey(), $workerId);
    }

    /**
     * Remove all worker metrics keys from Redis.
     *
     * Called by the master process after all workers have exited to ensure
     * no ghost entries remain (e.g. after SIGKILL or crash).
     */
    public function removeAllWorkerMetrics(): void
    {
        $redis = $this->getRedis();
        $pattern = $this->prefix.'worker:*';
        $prefixLen = strlen($this->prefix.'worker:');
        $cursor = '0';

        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            $cursor = Narrow::string($result[0] ?? null);
            $keys = Narrow::list($result[1] ?? null);

            foreach ($keys as $key) {
                $key = Narrow::string($key);
                $redis->execute('DEL', $key);
                $redis->execute('ZREM', $this->workerIndexKey(), substr($key, $prefixLen));
            }
        } while ($cursor !== '0');
    }

    /**
     * Read a single worker's metrics from Redis.
     *
     * @return array<string, string>|null Null if the key does not exist (worker expired).
     */
    #[\NoDiscard]
    public function getWorkerMetrics(string $workerId): ?array
    {
        $redis = $this->getRedis();
        $key = $this->prefix.'worker:'.$workerId;

        $result = $redis->execute('HGETALL', $key);

        if (! is_array($result) || $result === []) {
            return null;
        }

        return $this->flatPairsToAssoc($result);
    }

    /**
     * Read metrics for all currently alive workers.
     *
     * Reads the members of `{prefix}metrics:workers` that
     * {@see publishWorkerMetrics()} maintains: one command plus one HGETALL
     * per worker, however large the keyspace. Members whose hash has expired
     * are dropped from the index.
     *
     * SCAN over `{prefix}worker:*` is added only when the fleet may hold
     * workers of the release before the index, which write their hash alone:
     * when the index is empty, or while the shared `{prefix}metrics` hash
     * exists. Only masters of that release write it, with a 30 second TTL,
     * so a fleet that is fully upgraded never scans.
     *
     * @return array<string, array<string, string>> Keyed by worker ID.
     */
    #[\NoDiscard]
    public function getAllWorkerMetrics(): array
    {
        $redis = $this->getRedis();
        $ids = $redis->execute('ZRANGE', $this->workerIndexKey(), '0', '-1');
        $ids = is_array($ids) ? $ids : [];
        $workers = [];

        if ($ids !== []) {
            $oldMasters = Narrow::int($redis->execute('EXISTS', $this->prefix.'metrics')) > 0;
            $gone = [];

            foreach ($ids as $id) {
                $id = Narrow::string($id);
                $data = $redis->execute('HGETALL', $this->prefix.'worker:'.$id);

                if (is_array($data) && $data !== []) {
                    $workers[$id] = $this->flatPairsToAssoc($data);
                } else {
                    $gone[] = $id;
                }
            }

            if ($gone !== []) {
                $redis->execute('ZREM', $this->workerIndexKey(), ...$gone);
            }

            if (! $oldMasters) {
                return $workers;
            }
        }

        $pattern = $this->prefix.'worker:*';
        $prefixLen = strlen($this->prefix.'worker:');
        $cursor = '0';

        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            $cursor = Narrow::string($result[0] ?? null);
            $keys = Narrow::list($result[1] ?? null);

            foreach ($keys as $key) {
                $key = Narrow::string($key);
                $workerId = substr($key, $prefixLen);

                if (isset($workers[$workerId])) {
                    continue;
                }

                $data = $redis->execute('HGETALL', $key);

                if (is_array($data) && $data !== []) {
                    $workers[$workerId] = $this->flatPairsToAssoc($data);
                }
            }
        } while ($cursor !== '0');

        return $workers;
    }

    /**
     * The cluster aggregate: every live host's aggregate, combined.
     *
     * @return array<string, string> Empty array if no aggregated metrics have been published yet.
     */
    #[\NoDiscard]
    public function getAggregatedMetrics(): array
    {
        return self::readAggregate($this->getRedis(), $this->prefix);
    }

    /**
     * Read and combine the live per-host aggregates on any client.
     *
     * Static so `torque:status`, which holds its own connection, reads the
     * same figures the dashboard does.
     *
     * Hosts listed in `{prefix}metrics:live` within the last
     * {@see AGGREGATE_TTL_SECONDS} are read; one whose hash already expired
     * reads as empty and is skipped. Only when no host has a live aggregate is
     * the shared `{prefix}metrics` hash of the layout before per-host keys
     * read, so a fleet of old masters still shows up mid-deploy and is never
     * added on top of upgraded ones.
     *
     * @return array<string, string>
     */
    #[\NoDiscard]
    public static function readAggregate(RedisClient $redis, string $prefix, ?int $now = null): array
    {
        $now ??= time();
        $hosts = $redis->execute('ZRANGEBYSCORE', $prefix.'metrics:live', (string) ($now - self::AGGREGATE_TTL_SECONDS), '+inf');
        $aggregates = [];

        foreach (is_array($hosts) ? $hosts : [] as $host) {
            $fields = $redis->execute('HGETALL', $prefix.'metrics:live:'.Narrow::string($host));

            if (is_array($fields) && $fields !== []) {
                $aggregates[] = self::flatPairsToAssoc($fields);
            }
        }

        if ($aggregates !== []) {
            return self::combineAggregates($aggregates);
        }

        $legacy = $redis->execute('HGETALL', $prefix.'metrics');

        return is_array($legacy) && $legacy !== [] ? self::flatPairsToAssoc($legacy) : [];
    }

    /**
     * Combine per-host aggregates into the cluster figure.
     *
     * Counts, slots, memory and the instantaneous throughput add up. Latency is
     * each host's average weighted by its processed jobs, the same weighting
     * {@see aggregateFromWorkers()} applies to workers. The rolling rates are
     * computed by every master from the shared minute rollup, so they agree
     * across hosts and the freshest copy is taken rather than a sum. The
     * smoothed rate is only close: each host steps its own EMA, re-seeded when
     * its hash expired, so the copies converge rather than match.
     * A single host is returned exactly as published.
     *
     * @param  non-empty-list<array<string, string>>  $aggregates
     * @return array<string, string>
     */
    #[\NoDiscard]
    public static function combineAggregates(array $aggregates): array
    {
        if (count($aggregates) === 1) {
            return $aggregates[0];
        }

        $ints = [];
        $floats = [];
        $latencySum = 0.0;
        $latencyJobs = 0;
        $hasLatency = false;
        $freshest = [];
        $freshestAt = -1;

        foreach ($aggregates as $fields) {
            foreach (self::SUMMED_INT_FIELDS as $field) {
                if (isset($fields[$field])) {
                    $ints[$field] = ($ints[$field] ?? 0) + (int) $fields[$field];
                }
            }

            foreach (self::SUMMED_FLOAT_FIELDS as $field) {
                if (isset($fields[$field])) {
                    $floats[$field] = ($floats[$field] ?? 0.0) + (float) $fields[$field];
                }
            }

            if (isset($fields['avg_latency'])) {
                $hasLatency = true;
                $jobs = max(0, (int) ($fields['jobs_processed'] ?? 0));
                $latencySum += (float) $fields['avg_latency'] * $jobs;
                $latencyJobs += $jobs;
            }

            $updatedAt = (int) ($fields['updated_at'] ?? 0);

            if ($updatedAt > $freshestAt) {
                $freshestAt = $updatedAt;
                $freshest = $fields;
            }
        }

        $combined = [];

        foreach (self::CLUSTER_WIDE_FIELDS as $field) {
            if (isset($freshest[$field])) {
                $combined[$field] = $freshest[$field];
            }
        }

        foreach ($ints as $field => $sum) {
            $combined[$field] = (string) $sum;
        }

        foreach ($floats as $field => $sum) {
            $combined[$field] = (string) round($sum, 2);
        }

        if ($hasLatency) {
            $combined['avg_latency'] = (string) ($latencyJobs > 0 ? round($latencySum / $latencyJobs, 2) : 0);
        }

        $combined['updated_at'] = (string) max(0, $freshestAt);

        return $combined;
    }

    /**
     * Aggregate metrics on-the-fly from individual worker hashes.
     *
     * Unlike {@see getAggregatedMetrics()} which reads a pre-computed hash,
     * this builds a summary directly from `{prefix}worker:*` keys, no
     * master process required.
     *
     * @param  array<string, array<string, string>>  $workers  Output of {@see getAllWorkerMetrics()}.
     * @return array<string, mixed>
     */
    #[\NoDiscard]
    public function aggregateFromWorkers(array $workers): array
    {
        $totalProcessed = 0;
        $totalFailed = 0;
        $totalSlots = 0;
        $totalActive = 0;
        $totalMemory = 0;
        $latencySum = 0.0;
        $latencyCount = 0;
        $latestHeartbeat = 0;
        $perQueue = [];
        $perJob = [];
        $peakWorkerMemory = 0;

        foreach ($workers as $w) {
            $totalProcessed += (int) ($w['jobs_processed'] ?? 0);
            $totalFailed += (int) ($w['jobs_failed'] ?? 0);
            $totalSlots += (int) ($w['total_slots'] ?? 0);
            $totalActive += (int) ($w['active_slots'] ?? 0);
            $totalMemory += (int) ($w['memory_bytes'] ?? 0);
            // The fleet total hides the one worker about to be recycled, so
            // keep the largest single worker as well.
            $peakWorkerMemory = max($peakWorkerMemory, (int) ($w['memory_bytes'] ?? 0));
            $heartbeat = (int) ($w['last_heartbeat'] ?? 0);

            if ($heartbeat > $latestHeartbeat) {
                $latestHeartbeat = $heartbeat;
            }

            $processed = (int) ($w['jobs_processed'] ?? 0);
            if ($processed > 0) {
                $latencySum += (float) ($w['avg_latency_ms'] ?? 0) * $processed;
                $latencyCount += $processed;
            }

            foreach (self::decodePerQueue($w['per_queue'] ?? null) as $queue => [$queueProcessed, $queueFailed]) {
                [$sumProcessed, $sumFailed] = $perQueue[$queue] ?? [0, 0];
                $perQueue[$queue] = [$sumProcessed + $queueProcessed, $sumFailed + $queueFailed];
            }

            foreach (self::decodePerJob($w['per_job'] ?? null) as $class => [$classProcessed, $classFailed, $runtimeSum, $runtimeMax]) {
                [$sumProcessed, $sumFailed, $sumRuntime, $peakRuntime] = $perJob[$class] ?? [0, 0, 0.0, 0.0];

                // Counters and runtime sums add up across workers; the runtime
                // high-water mark is the largest any one of them saw.
                $perJob[$class] = [
                    $sumProcessed + $classProcessed,
                    $sumFailed + $classFailed,
                    $sumRuntime + $runtimeSum,
                    max($peakRuntime, $runtimeMax),
                ];
            }
        }

        return [
            'workers' => count($workers),
            'total_slots' => $totalSlots,
            'concurrent' => $totalActive,
            'jobs_processed' => $totalProcessed,
            'jobs_failed' => $totalFailed,
            'throughput' => 0.0,
            'avg_latency' => $latencyCount > 0 ? round($latencySum / $latencyCount, 2) : 0,
            'memory_mb' => round($totalMemory / 1024 / 1024, 2),
            'memory_peak_mb' => round($peakWorkerMemory / 1024 / 1024, 2),
            'per_queue' => $perQueue,
            'per_job' => $perJob,
            'updated_at' => $latestHeartbeat,
        ];
    }

    /**
     * Decode a worker's `per_job` JSON field, tolerating anything malformed.
     *
     * @return array<string, array{0: int, 1: int, 2: float, 3: float}>
     */
    private static function decodePerJob(?string $json): array
    {
        if ($json === null || $json === '' || $json === '[]') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $perJob = [];

        foreach ($decoded as $class => $outcome) {
            $outcome = Narrow::list($outcome);
            $perJob[(string) $class] = [
                Narrow::int($outcome[0] ?? null),
                Narrow::int($outcome[1] ?? null),
                Narrow::float($outcome[2] ?? null),
                Narrow::float($outcome[3] ?? null),
            ];
        }

        return $perJob;
    }

    /**
     * Decode a worker's `per_queue` JSON field, tolerating anything malformed.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private static function decodePerQueue(?string $json): array
    {
        if ($json === null || $json === '' || $json === '[]') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $perQueue = [];

        foreach ($decoded as $queue => $outcome) {
            $outcome = Narrow::list($outcome);
            $perQueue[(string) $queue] = [Narrow::int($outcome[0] ?? null), Narrow::int($outcome[1] ?? null)];
        }

        return $perQueue;
    }

    /**
     * Previous EMA value, so the smoothed rate survives across ticks without
     * the master holding any state. Seeds from the current sample on the first
     * publish, otherwise the needle would crawl up from zero after a restart.
     *
     * Read from the host's own hash, so each master advances its own EMA once
     * per tick instead of every master stepping one shared value.
     */
    private function previousSmoothedThroughput(string $host, float $fallback): float
    {
        $previous = $this->getRedis()->execute('HGET', $this->liveKey($host), 'throughput_smoothed');

        return is_string($previous) || is_numeric($previous) ? (float) $previous : $fallback;
    }

    /**
     * Drop buckets that fell out of a tier's retention window, and refresh the
     * key's own TTL so an idle cluster's history disappears instead of
     * lingering.
     *
     * Runs once per bucket of the tier at most (once a minute for the minute
     * tier, once a day for the day tier), and once across the whole fleet:
     * the hashes are shared by every master, so a `SET NX` claim per key and
     * bucket lets the first master prune and the rest skip. The walk is an
     * HSCAN in pages rather than one HKEYS, so a large hash never comes back
     * in a single reply.
     */
    private function pruneKey(string $key, string $tier, int $currentBucket): void
    {
        if (($this->lastPrunedBucket[$key] ?? null) === $currentBucket) {
            return;
        }

        $this->lastPrunedBucket[$key] = $currentBucket;

        $redis = $this->getRedis();
        $retention = $this->tierRetentionSeconds($tier);

        // Retention 0 means keep forever. Drop any TTL a previous setting left
        // behind, otherwise "forever" would quietly expire.
        if ($retention === 0) {
            $redis->execute('PERSIST', $key);

            return;
        }

        // The claim lives one bucket width, so the next bucket has a fresh one.
        $claim = $redis->execute(
            'SET',
            $this->prefix.'metrics:prune:'.substr($key, strlen($this->prefix)).':'.$currentBucket,
            '1',
            'NX',
            'EX',
            (string) self::TIER_SECONDS[$tier],
        );

        if ($claim === null || $claim === false) {
            return;
        }

        $oldestKept = $currentBucket - $retention + self::TIER_SECONDS[$tier];
        $cursor = '0';

        do {
            $reply = $redis->execute('HSCAN', $key, $cursor, 'COUNT', '1000');

            if (! is_array($reply) || count($reply) < 2) {
                break;
            }

            $cursor = Narrow::string($reply[0] ?? null);
            $page = Narrow::list($reply[1] ?? null);
            $stale = [];

            // Flat [field, value, ...]. Gauge fields are "{epoch}:..." and
            // counter fields bare epochs; an int cast reads the leading epoch
            // out of both.
            for ($i = 0, $n = count($page); $i < $n; $i += 2) {
                $field = Narrow::string($page[$i]);

                if ((int) $field < $oldestKept) {
                    $stale[] = $field;
                }
            }

            if ($stale !== []) {
                $redis->execute('HDEL', $key, ...$stale);
            }
        } while ($cursor !== '0');

        $redis->execute('EXPIRE', $key, (string) ($retention * 2));
    }

    /**
     * Read a whole rollup hash as field => raw "processed,failed" value.
     *
     * Bucket fields are numeric, so PHP stores them as integer keys.
     *
     * @return array<int|string, string>
     */
    private function readRollup(string $tier, ?string $queue): array
    {
        if (! $this->metricsEnabled()) {
            return [];
        }

        return $this->readHash($this->rollupKey($tier, $queue));
    }

    /**
     * HGETALL one hash as an associative array.
     *
     * Numeric fields (bucket epochs) become integer keys, as PHP always does.
     *
     * @return array<int|string, string>
     */
    private function readHash(string $key): array
    {
        $result = $this->getRedis()->execute('HGETALL', $key);

        return is_array($result) && $result !== [] ? $this->flatPairsToAssoc($result) : [];
    }

    /**
     * The finest tier whose retention still covers a span of seconds.
     */
    private function finestTierCovering(int $span): string
    {
        $hourRetention = $this->tierRetentionSeconds(self::TIER_HOUR);

        return match (true) {
            $span <= $this->tierRetentionSeconds(self::TIER_MINUTE) => self::TIER_MINUTE,
            $hourRetention === 0 || $span <= $hourRetention => self::TIER_HOUR,
            default => self::TIER_DAY,
        };
    }

    /**
     * Drop classes from the known-class set once their daily rollup is gone.
     *
     * A Redis set has no per-member TTL, so the index would otherwise grow
     * forever with classes that were renamed or deleted years ago. Runs at most
     * once a day, in step with the day tier's own prune.
     */
    private function pruneJobIndex(int $currentDayBucket): void
    {
        $indexKey = $this->jobIndexKey();

        if (($this->lastPrunedBucket[$indexKey] ?? null) === $currentDayBucket) {
            return;
        }

        $this->lastPrunedBucket[$indexKey] = $currentDayBucket;

        $redis = $this->getRedis();
        $members = $redis->execute('SMEMBERS', $indexKey);

        if (! is_array($members)) {
            return;
        }

        foreach ($members as $member) {
            $class = Narrow::string($member);

            if (Narrow::int($redis->execute('EXISTS', $this->jobKey(self::TIER_DAY, $class))) === 0) {
                $redis->execute('SREM', $indexKey, $class);
            }
        }
    }

    /**
     * Drop hosts from the index once they are older than the day tier keeps.
     *
     * A ZSET scored by last-seen, so unlike {@see pruneJobIndex()} this is one
     * command rather than an EXISTS per member. Swept at most once a day.
     */
    private function pruneHostIndex(int $currentDayBucket): void
    {
        $indexKey = $this->hostIndexKey();

        if (($this->lastPrunedBucket[$indexKey] ?? null) === $currentDayBucket) {
            return;
        }

        $this->lastPrunedBucket[$indexKey] = $currentDayBucket;

        $retention = $this->tierRetentionSeconds(self::TIER_DAY);

        // Retention 0 means the day tier keeps everything, so the index does too.
        if ($retention === 0) {
            return;
        }

        $cutoff = '('.($currentDayBucket - $retention);
        $redis = $this->getRedis();

        $redis->execute('ZREMRANGEBYSCORE', $indexKey, '-inf', $cutoff);

        // A first sighting can be older than the cutoff while the host is still
        // reporting, so the first-seen index is not pruned by its own score:
        // of the members first seen before the cutoff, drop the ones the host
        // index no longer holds (swept above, or by the housekeeper).
        $old = $redis->execute('ZRANGEBYSCORE', $this->hostFirstSeenKey(), '-inf', $cutoff);

        if (! is_array($old) || $old === []) {
            return;
        }

        $old = array_map(Narrow::string(...), $old);
        $alive = $redis->execute('ZMSCORE', $indexKey, ...$old);
        $alive = is_array($alive) ? array_values($alive) : [];
        $gone = array_values(array_filter($old, static fn (int $i): bool => ! is_numeric($alive[$i] ?? null), ARRAY_FILTER_USE_KEY));

        if ($gone !== []) {
            $redis->execute('ZREM', $this->hostFirstSeenKey(), ...$gone);
        }
    }

    /**
     * Read named fields of a hash, omitting the ones that are not set.
     *
     * The per-host hashes hold a full retention window for the whole fleet, so
     * a range read names its fields instead of pulling everything back.
     *
     * Keyed by field name, except that PHP turns a decimal field such as a
     * bare bucket epoch into an int key, hence array-key.
     *
     * @param  list<string>  $fields
     * @return array<array-key, string>
     */
    private function readFields(string $key, array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $values = $this->getRedis()->execute('HMGET', $key, ...$fields);

        if (! is_array($values)) {
            return [];
        }

        $result = [];

        foreach ($fields as $i => $field) {
            $value = $values[$i] ?? null;

            if ($value !== null && $value !== false) {
                $result[$field] = Narrow::string($value);
            }
        }

        return $result;
    }

    /**
     * @return array{avg: float, max: float}
     */
    private static function parseGauge(?string $value): array
    {
        if ($value === null || $value === '') {
            return ['avg' => 0.0, 'max' => 0.0];
        }

        $parts = explode(',', $value, 3);
        $sum = (float) $parts[0];
        $count = (int) ($parts[1] ?? 0);

        return [
            'avg' => $count > 0 ? round($sum / $count, 3) : 0.0,
            'max' => round((float) ($parts[2] ?? 0), 3),
        ];
    }

    /**
     * @return array{processed: int, failed: int, runtimeSumMs: float, runtimeMaxMs: float}
     */
    private static function parseJobOutcome(?string $value): array
    {
        if ($value === null || $value === '') {
            return ['processed' => 0, 'failed' => 0, 'runtimeSumMs' => 0.0, 'runtimeMaxMs' => 0.0];
        }

        $parts = explode(',', $value, 4);

        return [
            'processed' => (int) $parts[0],
            'failed' => (int) ($parts[1] ?? 0),
            'runtimeSumMs' => (float) ($parts[2] ?? 0),
            'runtimeMaxMs' => (float) ($parts[3] ?? 0),
        ];
    }

    /**
     * @return array{processed: int, failed: int}
     */
    private static function parseOutcome(?string $value): array
    {
        if ($value === null || $value === '') {
            return ['processed' => 0, 'failed' => 0];
        }

        $parts = explode(',', $value, 2);

        return ['processed' => (int) $parts[0], 'failed' => (int) ($parts[1] ?? 0)];
    }

    private function rollupKey(string $tier, ?string $queue): string
    {
        $key = $this->prefix.'metrics:rollup:'.$tier;

        return $queue === null || $queue === '' ? $key : $key.':'.$queue;
    }

    private function gaugeKey(string $tier): string
    {
        return $this->prefix.'metrics:gauge:'.$tier;
    }

    /**
     * Rollup key for one job class.
     *
     * No cluster hash tag, for the same reason the per-queue keys carry none:
     * every script here touches exactly one key, so the slot it lands in does
     * not matter.
     */
    private function jobKey(string $tier, string $class): string
    {
        return $this->prefix.'metrics:rollup:'.$tier.':job:'.$class;
    }

    private function jobIndexKey(): string
    {
        return $this->prefix.'metrics:jobs';
    }

    /**
     * Per-host rollup and gauge keys: one hash per tier holding every host,
     * fields `{bucket}:{host}` and `{bucket}:{host}:{metric}`.
     *
     * The host is in the field rather than the key so a write covers the whole
     * fleet in one eval. `pruneKey()` reads the leading epoch off either shape
     * with an int cast, exactly as it already does for the cluster gauges.
     */
    private function hostRollupKey(string $tier): string
    {
        return $this->prefix.'metrics:rollup:'.$tier.':host';
    }

    private function hostGaugeKey(string $tier): string
    {
        return $this->prefix.'metrics:gauge:'.$tier.':host';
    }

    private function hostIndexKey(): string
    {
        return $this->prefix.'metrics:hosts';
    }

    /**
     * Same members as the host index, scored by the first sighting instead of
     * the last.
     */
    private function hostFirstSeenKey(): string
    {
        return $this->prefix.'metrics:hosts:first';
    }

    /**
     * Make a hostname safe to use inside a colon-delimited field.
     *
     * A colon in a host would silently invent a field segment, and `*`, `?`,
     * `[` break the SCAN patterns housekeeping matches on. Anything outside
     * `[A-Za-z0-9._-]` is replaced, and a short digest is appended whenever the
     * replacement changed something, so two machines whose names clean to the
     * same string can never collapse into one row.
     *
     * Idempotent: a name this method produced comes back unchanged, so a host
     * read from the index and normalised again still finds its history.
     */
    #[\NoDiscard]
    public static function normaliseHost(string $host): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,48}(~[0-9a-f]{6})?$/', $host) === 1) {
            return $host;
        }

        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($host)) ?? '';

        if ($clean === '') {
            return 'unknown';
        }

        $clean = substr($clean, 0, 48);

        return $clean === $host ? $clean : $clean.'~'.substr(sha1($host), 0, 6);
    }

    /**
     * Retention for a tier in seconds. Zero means keep forever, which only the
     * day tier allows.
     */
    private function tierRetentionSeconds(string $tier): int
    {
        return match ($tier) {
            self::TIER_HOUR => max(0, Narrow::int($this->setting('rollups.hourly_days', 90))) * 86400,
            self::TIER_DAY => max(0, Narrow::int($this->setting('rollups.daily_days', 730))) * 86400,
            default => $this->retentionSeconds(),
        };
    }

    /**
     * Retention of the minute tier. Unlike the coarse tiers this is expressed
     * in seconds and can never be "forever": a day of minutes is already 1440
     * fields, and the hour tier exists precisely to take over from there.
     */
    private function retentionSeconds(): int
    {
        return max(self::BUCKET_SECONDS, Narrow::int($this->setting('retention', 86400)));
    }

    /**
     * Metrics can be switched off wholesale; when they are, the bucket hash is
     * neither written nor read.
     */
    private function metricsEnabled(): bool
    {
        return (bool) $this->setting('enabled', true);
    }

    /**
     * Read a `torque.metrics.*` setting, falling back to the package default.
     *
     * The block is injected at construction because this class is also built
     * directly by the master, by workers and by console commands, where a
     * booted container is not guaranteed.
     */
    private function setting(string $key, mixed $default): mixed
    {
        return Arr::get($this->settings, $key, $default);
    }

    /**
     * Lazily create the Redis client on first use.
     *
     * A single dedicated connection is sufficient, metrics publishing
     * is infrequent and non-blocking.
     *
     * With a read timeout a reply lost to a half-open socket fails the call
     * instead of blocking the caller until TCP gives up, which in the master's
     * synchronous monitor loop would stop reaping and the watchdog as well.
     */
    private function getRedis(): RedisClient
    {
        return $this->redis ??= $this->readTimeout !== null && $this->readTimeout > 0
            ? new RedisClient(new ReconnectingRedisLink(createRedisConnector($this->redisUri), readTimeout: $this->readTimeout))
            : createRedisClient($this->redisUri);
    }

    /**
     * Convert a flat [key, value, key, value, ...] array from HGETALL
     * into an associative array.
     *
     * @param  array<mixed>  $pairs
     * @return array<string, string>
     */
    private static function flatPairsToAssoc(array $pairs): array
    {
        $assoc = [];

        for ($i = 0, $count = count($pairs); $i < $count; $i += 2) {
            $assoc[Narrow::string($pairs[$i])] = Narrow::string($pairs[$i + 1]);
        }

        return $assoc;
    }
}
