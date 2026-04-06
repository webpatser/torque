<?php

declare(strict_types=1);

namespace Webpatser\Torque\Metrics;

use Amp\Redis\RedisClient;

use function Amp\Redis\createRedisClient;

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

    private ?RedisClient $redis = null;

    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix = 'torque:',
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
        $key = $this->prefix . 'worker:' . $workerId;

        $redis->execute('HSET', $key,
            'jobs_processed', (string) $snapshot->jobsProcessed,
            'jobs_failed', (string) $snapshot->jobsFailed,
            'active_slots', (string) $snapshot->activeSlots,
            'total_slots', (string) $snapshot->totalSlots,
            'avg_latency_ms', (string) round($snapshot->averageLatencyMs, 2),
            'slot_usage', (string) round($snapshot->slotUsageRatio, 4),
            'memory_bytes', (string) $snapshot->memoryBytes,
            'last_heartbeat', (string) $snapshot->timestamp,
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
        $key = $this->prefix . 'metrics';

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
    }

    /**
     * Read a single worker's metrics from Redis.
     *
     * @return array<string, string>|null  Null if the key does not exist (worker expired).
     */
    #[\NoDiscard]
    public function getWorkerMetrics(string $workerId): ?array
    {
        $redis = $this->getRedis();
        $key = $this->prefix . 'worker:' . $workerId;

        $result = $redis->execute('HGETALL', $key);

        if (!is_array($result) || $result === []) {
            return null;
        }

        return $this->flatPairsToAssoc($result);
    }

    /**
     * Read metrics for all currently alive workers.
     *
     * Uses SCAN to iterate `{prefix}worker:*` keys without blocking Redis.
     *
     * @return array<string, array<string, string>>  Keyed by worker ID.
     */
    #[\NoDiscard]
    public function getAllWorkerMetrics(): array
    {
        $redis = $this->getRedis();
        $pattern = $this->prefix . 'worker:*';
        $prefixLen = strlen($this->prefix . 'worker:');
        $workers = [];
        $cursor = '0';

        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            if (!is_array($result) || count($result) < 2) {
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
     * @return array<string, string>  Empty array if no aggregated metrics have been published yet.
     */
    #[\NoDiscard]
    public function getAggregatedMetrics(): array
    {
        $redis = $this->getRedis();
        $key = $this->prefix . 'metrics';

        $result = $redis->execute('HGETALL', $key);

        if (!is_array($result) || $result === []) {
            return [];
        }

        return $this->flatPairsToAssoc($result);
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
