<?php

declare(strict_types=1);

namespace Webpatser\Torque\Metrics;

use Fledge\Async\Redis\RedisClient;
use Illuminate\Support\Arr;
use Webpatser\Torque\Support\WorkerId;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Publishes worker metrics to Redis hashes for dashboard consumption.
 *
 * Each worker process publishes its own metrics via {@see publishWorkerMetrics()},
 * and the master process aggregates all workers into a single summary hash via
 * {@see publishAggregatedMetrics()}.
 *
 * Keys use a configurable prefix and include a heartbeat TTL so stale worker
 * entries auto-expire if a process crashes without cleanup.
 */
final class MetricsPublisher
{
    private const int HEARTBEAT_TTL_SECONDS = 60;

    /**
     * TTL on the aggregated `{prefix}metrics` hash. A dead publisher must
     * read as "no data" (dashboards and torque:status render placeholders)
     * rather than serving stale numbers forever.
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
     * Pre-rollup key that held minute counts as bare integers keyed by minute
     * index. Migrated into the minute tier on first use, then deleted.
     */
    private const string LEGACY_BUCKETS_SUFFIX = 'metrics:buckets';

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
     * Per-host gauges, written to `{prefix}metrics:gauge:{tier}:host:{host}`.
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

    /** Whether the one-time legacy key migration has been attempted. */
    private bool $legacyMigrated = false;

    /**
     * @param  array<string, mixed>  $settings  The `torque.metrics` config block (enabled, retention, rollups).
     */
    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix = 'torque:',
        private readonly array $settings = [],
    ) {}

    /**
     * Publish a single worker's metrics to its dedicated Redis hash.
     *
     * Key: `{prefix}worker:{workerId}`
     * A TTL is set on every publish as a heartbeat — if the worker dies, the
     * key expires and disappears from the dashboard automatically.
     */
    public function publishWorkerMetrics(string $workerId, WorkerSnapshot $snapshot): void
    {
        $redis = $this->getRedis();
        $key = $this->prefix.'worker:'.$workerId;
        $parsed = WorkerId::parse($workerId);

        $redis->execute('HSET', $key,
            'pid', (string) ($parsed->pid ?? getmypid()),
            'host', $parsed->host,
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
        );

        $redis->execute('EXPIRE', $key, (string) self::HEARTBEAT_TTL_SECONDS);
    }

    /**
     * Publish aggregated metrics across all workers.
     *
     * Key: `{prefix}metrics`
     * Called by the master process on a timer to provide a single-key
     * overview for the dashboard.
     *
     * @param  WorkerSnapshot[]  $workerSnapshots
     */
    public function publishAggregatedMetrics(array $workerSnapshots): void
    {
        $redis = $this->getRedis();
        $key = $this->prefix.'metrics';

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

        $redis->execute('HSET', $key,
            'throughput', (string) round($throughput, 2),
            'concurrent', (string) $totalActive,
            'total_slots', (string) $totalSlots,
            'avg_latency', (string) round($weightedAvgLatency, 2),
            'jobs_processed', (string) $totalProcessed,
            'jobs_failed', (string) $totalFailed,
            'memory_mb', (string) round($totalMemory / 1_048_576, 2),
            'workers', (string) $workerCount,
            'updated_at', (string) time(),
        );

        $redis->execute('EXPIRE', $key, (string) self::AGGREGATE_TTL_SECONDS);
    }

    /**
     * Publish an already-aggregated summary (the {@see aggregateFromWorkers()}
     * shape) to the `{prefix}metrics` hash. Used by the master's monitor loop,
     * which aggregates from the worker hashes and computes real throughput
     * from jobs_processed deltas between ticks.
     *
     * @param  array<string, mixed>  $aggregate
     */
    public function publishAggregate(array $aggregate): void
    {
        $redis = $this->getRedis();
        $key = $this->prefix.'metrics';

        // Rolling rates read off the persisted minute buckets. `throughput`
        // stays the instantaneous per-second delta for backwards compatibility,
        // but a queue that receives one burst every few minutes reads as either
        // zero or a five-figure spike there, which is why the gauge uses these.
        $now = time();
        $buckets = $this->minuteBuckets(60, $now);
        $perMinute5 = self::perMinuteRate($buckets, 5, $now);
        $smoothed = self::SMOOTHING_ALPHA * $perMinute5
            + (1 - self::SMOOTHING_ALPHA) * $this->previousSmoothedThroughput($perMinute5);

        $redis->execute('HSET', $key,
            'throughput', (string) round((float) ($aggregate['throughput'] ?? 0.0), 2),
            'throughput_1m', (string) round(self::perMinuteRate($buckets, 1, $now), 2),
            'throughput_5m', (string) round($perMinute5, 2),
            'throughput_smoothed', (string) round($smoothed, 2),
            'jobs_last_hour', (string) array_sum($buckets),
            'concurrent', (string) (int) ($aggregate['concurrent'] ?? 0),
            'total_slots', (string) (int) ($aggregate['total_slots'] ?? 0),
            'avg_latency', (string) round((float) ($aggregate['avg_latency'] ?? 0.0), 2),
            'jobs_processed', (string) (int) ($aggregate['jobs_processed'] ?? 0),
            'jobs_failed', (string) (int) ($aggregate['jobs_failed'] ?? 0),
            'memory_mb', (string) round((float) ($aggregate['memory_mb'] ?? 0.0), 2),
            'workers', (string) (int) ($aggregate['workers'] ?? 0),
            'updated_at', (string) $now,
        );

        $redis->execute('EXPIRE', $key, (string) self::AGGREGATE_TTL_SECONDS);
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
            $outcome = array_values((array) $outcome);
            $queueProcessed = max(0, (int) ($outcome[0] ?? 0));
            $queueFailed = max(0, (int) ($outcome[1] ?? 0));

            if ($queueProcessed > 0 || $queueFailed > 0) {
                $scopes[] = [(string) $queue, $queueProcessed, $queueFailed];
            }
        }

        if ($scopes === []) {
            return;
        }

        $now ??= time();
        $this->migrateLegacyBuckets();
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
     * @param  array<string, float|int>  $samples  Metric name => current value.
     */
    public function recordGauges(array $samples, ?int $now = null): void
    {
        if (! $this->metricsEnabled() || $samples === []) {
            return;
        }

        $now ??= time();
        $redis = $this->getRedis();

        foreach (self::TIER_SECONDS as $tier => $seconds) {
            $bucket = intdiv($now, $seconds) * $seconds;
            $key = $this->gaugeKey($tier);
            $args = [(string) $bucket];

            foreach ($samples as $metric => $value) {
                $args[] = (string) $metric;
                $args[] = (string) round((float) $value, 3);
            }

            $redis->eval(self::LUA_ADD_GAUGES, [$key], $args);

            $this->pruneKey($key, $tier, $bucket);
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
            $outcome = array_values((array) $outcome);
            $processed = max(0, (int) ($outcome[0] ?? 0));
            $failed = max(0, (int) ($outcome[1] ?? 0));
            $runtimeSum = max(0.0, (float) ($outcome[2] ?? 0));
            $runtimeMax = max(0.0, (float) ($outcome[3] ?? 0));

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
            $outcome = array_values((array) $outcome);
            $processed = max(0, (int) ($outcome[0] ?? 0));
            $failed = max(0, (int) ($outcome[1] ?? 0));

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

                if ($host === '' || ! is_array($metrics) || $metrics === []) {
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
            $hosts[(string) $members[$i]] = (int) $members[$i + 1];
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
     * @param  list<string>  $hosts
     */
    private function touchHostIndex(array $hosts, int $now): void
    {
        if ($hosts === []) {
            return;
        }

        $args = [];

        foreach ($hosts as $host) {
            $args[] = (string) $now;
            $args[] = $host;
        }

        $this->getRedis()->execute('ZADD', $this->hostIndexKey(), 'GT', ...$args);

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
     * Several gauges at once, off a single read of the tier's hash.
     *
     * The overview draws five of these side by side, and they all live in the
     * same hash, so reading them one by one would be five identical HGETALLs.
     *
     * @param  list<string>  $metrics
     * @return array<string, array<int, array{avg: float, max: float}>>
     */
    #[\NoDiscard]
    public function gaugeSeriesMulti(array $metrics, string $tier, int $count, ?int $now = null): array
    {
        $seconds = self::TIER_SECONDS[$tier]
            ?? throw new \InvalidArgumentException("Unknown metrics tier [{$tier}].");

        $count = max(1, $count);
        $now ??= time();
        $current = intdiv($now, $seconds) * $seconds;
        $oldest = $current - ($count - 1) * $seconds;

        $raw = $this->metricsEnabled() ? $this->readHash($this->gaugeKey($tier)) : [];
        $series = [];

        foreach ($metrics as $metric) {
            $metric = (string) $metric;
            $series[$metric] = [];

            for ($bucket = $oldest; $bucket <= $current; $bucket += $seconds) {
                $series[$metric][$bucket] = self::parseGauge($raw[$bucket.':'.$metric] ?? null);
            }
        }

        return $series;
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

        $classes = array_map(strval(...), $members);
        sort($classes);

        return array_values($classes);
    }

    /**
     * Per-bucket outcomes for one job class, oldest first and gap-filled.
     *
     * @return array<int, array{processed: int, failed: int, runtimeSumMs: float, runtimeMaxMs: float}>
     */
    #[\NoDiscard]
    public function jobSeries(string $class, string $tier, int $count, ?int $now = null): array
    {
        $seconds = self::TIER_SECONDS[$tier]
            ?? throw new \InvalidArgumentException("Unknown metrics tier [{$tier}].");

        $count = max(1, $count);
        $now ??= time();
        $current = intdiv($now, $seconds) * $seconds;
        $oldest = $current - ($count - 1) * $seconds;

        $raw = $this->metricsEnabled() ? $this->readHash($this->jobKey($tier, $class)) : [];
        $series = [];

        for ($bucket = $oldest; $bucket <= $current; $bucket += $seconds) {
            $series[$bucket] = self::parseJobOutcome($raw[(string) $bucket] ?? null);
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
        $cursor = '0';

        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            $cursor = (string) $result[0];
            $keys = is_array($result[1]) ? $result[1] : [];

            foreach ($keys as $key) {
                $redis->execute('DEL', (string) $key);
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
     * Uses SCAN to iterate `{prefix}worker:*` keys without blocking Redis.
     *
     * @return array<string, array<string, string>> Keyed by worker ID.
     */
    #[\NoDiscard]
    public function getAllWorkerMetrics(): array
    {
        $redis = $this->getRedis();
        $pattern = $this->prefix.'worker:*';
        $prefixLen = strlen($this->prefix.'worker:');
        $workers = [];
        $cursor = '0';

        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            $cursor = (string) $result[0];
            $keys = is_array($result[1]) ? $result[1] : [];

            foreach ($keys as $key) {
                $key = (string) $key;
                $data = $redis->execute('HGETALL', $key);

                if (is_array($data) && $data !== []) {
                    $workerId = substr($key, $prefixLen);
                    $workers[$workerId] = $this->flatPairsToAssoc($data);
                }
            }
        } while ($cursor !== '0');

        return $workers;
    }

    /**
     * Read the aggregated metrics hash.
     *
     * @return array<string, string> Empty array if no aggregated metrics have been published yet.
     */
    #[\NoDiscard]
    public function getAggregatedMetrics(): array
    {
        $redis = $this->getRedis();
        $key = $this->prefix.'metrics';

        $result = $redis->execute('HGETALL', $key);

        if (! is_array($result) || $result === []) {
            return [];
        }

        return $this->flatPairsToAssoc($result);
    }

    /**
     * Aggregate metrics on-the-fly from individual worker hashes.
     *
     * Unlike {@see getAggregatedMetrics()} which reads a pre-computed hash,
     * this builds a summary directly from `{prefix}worker:*` keys — no
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
            $outcome = array_values((array) $outcome);
            $perJob[(string) $class] = [
                (int) ($outcome[0] ?? 0),
                (int) ($outcome[1] ?? 0),
                (float) ($outcome[2] ?? 0),
                (float) ($outcome[3] ?? 0),
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
            $outcome = array_values((array) $outcome);
            $perQueue[(string) $queue] = [(int) ($outcome[0] ?? 0), (int) ($outcome[1] ?? 0)];
        }

        return $perQueue;
    }

    /**
     * Previous EMA value, so the smoothed rate survives across ticks without
     * the master holding any state. Seeds from the current sample on the first
     * publish, otherwise the needle would crawl up from zero after a restart.
     */
    private function previousSmoothedThroughput(float $fallback): float
    {
        $previous = $this->getRedis()->execute('HGET', $this->prefix.'metrics', 'throughput_smoothed');

        return is_string($previous) || is_numeric($previous) ? (float) $previous : $fallback;
    }

    /**
     * Drop buckets that fell out of a tier's retention window, and refresh the
     * key's own TTL so an idle cluster's history disappears instead of
     * lingering.
     *
     * Runs once per bucket of the tier at most (once a minute for the minute
     * tier, once a day for the day tier): the field count is bounded by
     * retention, so this is a few thousand integer comparisons per day.
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

        $oldestKept = $currentBucket - $retention + self::TIER_SECONDS[$tier];
        $fields = $redis->execute('HKEYS', $key);

        if (is_array($fields)) {
            // Gauge fields are "{epoch}:{metric}", counter fields are bare
            // epochs; an int cast reads the leading epoch out of both.
            $stale = array_values(array_filter(
                array_map(strval(...), $fields),
                static fn (string $field): bool => (int) $field < $oldestKept,
            ));

            if ($stale !== []) {
                $redis->execute('HDEL', $key, ...$stale);
            }
        }

        $redis->execute('EXPIRE', $key, (string) ($retention * 2));
    }

    /**
     * Move the pre-rollup `metrics:buckets` hash into the minute tier.
     *
     * Its fields were minute indices holding a bare processed count, so they
     * are rescaled to bucket epochs and given a zero failure count. Runs once
     * per process, costing a single EXISTS when there is nothing to do.
     */
    private function migrateLegacyBuckets(): void
    {
        if ($this->legacyMigrated) {
            return;
        }

        $this->legacyMigrated = true;

        $redis = $this->getRedis();
        $legacyKey = $this->prefix.self::LEGACY_BUCKETS_SUFFIX;

        if ((int) $redis->execute('EXISTS', $legacyKey) !== 1) {
            return;
        }

        $raw = $redis->execute('HGETALL', $legacyKey);

        if (is_array($raw) && $raw !== []) {
            $target = $this->rollupKey(self::TIER_MINUTE, null);

            foreach ($this->flatPairsToAssoc($raw) as $minuteIndex => $processed) {
                $redis->eval(
                    self::LUA_ADD_OUTCOME,
                    [$target],
                    [(string) ((int) $minuteIndex * self::BUCKET_SECONDS), (int) $processed, 0],
                );
            }
        }

        $redis->execute('DEL', $legacyKey);
    }

    /**
     * Read a whole rollup hash as field => raw "processed,failed" value.
     *
     * @return array<string, string>
     */
    private function readRollup(string $tier, ?string $queue): array
    {
        if (! $this->metricsEnabled()) {
            return [];
        }

        $this->migrateLegacyBuckets();

        return $this->readHash($this->rollupKey($tier, $queue));
    }

    /**
     * HGETALL one hash as an associative array.
     *
     * @return array<string, string>
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
            $class = (string) $member;

            if ((int) $redis->execute('EXISTS', $this->jobKey(self::TIER_DAY, $class)) === 0) {
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

        $this->getRedis()->execute(
            'ZREMRANGEBYSCORE',
            $indexKey,
            '-inf',
            '('.($currentDayBucket - $retention),
        );
    }

    /**
     * Read named fields of a hash, omitting the ones that are not set.
     *
     * The per-host hashes hold a full retention window for the whole fleet, so
     * a range read names its fields instead of pulling everything back.
     *
     * @param  list<string>  $fields
     * @return array<string, string>
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
                $result[$field] = (string) $value;
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
     * Make a hostname safe to use inside a colon-delimited field.
     *
     * A colon in a host would silently invent a field segment, and `*`, `?`,
     * `[` break the SCAN patterns housekeeping matches on. Anything outside
     * `[A-Za-z0-9._-]` is replaced, and a short digest is appended whenever the
     * replacement changed something, so two machines whose names clean to the
     * same string can never collapse into one row.
     */
    #[\NoDiscard]
    public static function normaliseHost(string $host): string
    {
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
            self::TIER_HOUR => max(0, (int) $this->setting('rollups.hourly_days', 90)) * 86400,
            self::TIER_DAY => max(0, (int) $this->setting('rollups.daily_days', 730)) * 86400,
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
        return max(self::BUCKET_SECONDS, (int) $this->setting('retention', 86400));
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
     * A single dedicated connection is sufficient — metrics publishing
     * is infrequent and non-blocking.
     */
    private function getRedis(): RedisClient
    {
        return $this->redis ??= createRedisClient($this->redisUri);
    }

    /**
     * Convert a flat [key, value, key, value, ...] array from HGETALL
     * into an associative array.
     *
     * @param  list<mixed>  $pairs
     * @return array<string, string>
     */
    private function flatPairsToAssoc(array $pairs): array
    {
        $assoc = [];

        for ($i = 0, $count = count($pairs); $i < $count; $i += 2) {
            $assoc[(string) $pairs[$i]] = (string) $pairs[$i + 1];
        }

        return $assoc;
    }
}
