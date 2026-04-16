<?php

declare(strict_types=1);

use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Metrics\WorkerSnapshot;

/**
 * Helper to create a MetricsPublisher pointed at the test Redis database.
 */
function createPublisher(string $prefix = 'torque-test:'): MetricsPublisher
{
    return new MetricsPublisher(
        redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
        prefix: $prefix,
    );
}

/**
 * Helper to flush test keys after each test.
 */
function cleanupRedisKeys(MetricsPublisher $publisher, string $prefix, array $workerIds): void
{
    $redis = (new ReflectionClass($publisher))
        ->getMethod('getRedis')
        ->invoke($publisher);

    foreach ($workerIds as $id) {
        $redis->execute('DEL', $prefix . 'worker:' . $id);
    }

    $redis->execute('DEL', $prefix . 'metrics');
}

function makeSnapshot(
    int $jobsProcessed = 10,
    int $jobsFailed = 1,
    int $activeSlots = 5,
    int $totalSlots = 50,
    float $averageLatencyMs = 25.0,
    float $slotUsageRatio = 0.1,
    int $memoryBytes = 52_428_800,
    ?int $timestamp = null,
): WorkerSnapshot {
    return new WorkerSnapshot(
        jobsProcessed: $jobsProcessed,
        jobsFailed: $jobsFailed,
        activeSlots: $activeSlots,
        totalSlots: $totalSlots,
        averageLatencyMs: $averageLatencyMs,
        slotUsageRatio: $slotUsageRatio,
        memoryBytes: $memoryBytes,
        timestamp: $timestamp ?? time(),
    );
}

it('publishes worker metrics to Redis hash with correct fields', function () {
    $prefix = 'torque-pub-test:';
    $publisher = createPublisher($prefix);

    try {
        $snapshot = makeSnapshot(
            jobsProcessed: 42,
            jobsFailed: 3,
            activeSlots: 7,
            totalSlots: 50,
            averageLatencyMs: 12.5678,
            slotUsageRatio: 0.14,
            memoryBytes: 1_048_576,
        );

        $publisher->publishWorkerMetrics('worker-1', $snapshot);

        $metrics = $publisher->getWorkerMetrics('worker-1');

        expect($metrics)->not->toBeNull()
            ->and($metrics['jobs_processed'])->toBe('42')
            ->and($metrics['jobs_failed'])->toBe('3')
            ->and($metrics['active_slots'])->toBe('7')
            ->and($metrics['total_slots'])->toBe('50')
            ->and($metrics['avg_latency_ms'])->toBe('12.57')
            ->and($metrics['slot_usage'])->toBe('0.14')
            ->and($metrics['memory_bytes'])->toBe('1048576');

        cleanupRedisKeys($publisher, $prefix, ['worker-1']);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('sets TTL on worker metrics key', function () {
    $prefix = 'torque-ttl-test:';
    $publisher = createPublisher($prefix);

    try {
        $publisher->publishWorkerMetrics('worker-ttl', makeSnapshot());

        // Access the private Redis client to check TTL directly.
        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        $ttl = (int) $redis->execute('TTL', $prefix . 'worker:worker-ttl');

        // HEARTBEAT_TTL_SECONDS is 60; TTL should be between 1 and 60.
        expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

        cleanupRedisKeys($publisher, $prefix, ['worker-ttl']);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('reads back published worker metrics', function () {
    $prefix = 'torque-read-test:';
    $publisher = createPublisher($prefix);

    try {
        $snapshot = makeSnapshot(jobsProcessed: 100, jobsFailed: 5);
        $publisher->publishWorkerMetrics('worker-read', $snapshot);

        $metrics = $publisher->getWorkerMetrics('worker-read');

        expect($metrics)->toBeArray()
            ->and($metrics['jobs_processed'])->toBe('100')
            ->and($metrics['jobs_failed'])->toBe('5');

        cleanupRedisKeys($publisher, $prefix, ['worker-read']);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns all worker metrics', function () {
    $prefix = 'torque-all-test:';
    $publisher = createPublisher($prefix);

    try {
        $publisher->publishWorkerMetrics('w1', makeSnapshot(jobsProcessed: 10));
        $publisher->publishWorkerMetrics('w2', makeSnapshot(jobsProcessed: 20));
        $publisher->publishWorkerMetrics('w3', makeSnapshot(jobsProcessed: 30));

        $all = $publisher->getAllWorkerMetrics();

        expect($all)->toBeArray()
            ->toHaveKey('w1')
            ->toHaveKey('w2')
            ->toHaveKey('w3');

        expect($all['w1']['jobs_processed'])->toBe('10');
        expect($all['w2']['jobs_processed'])->toBe('20');
        expect($all['w3']['jobs_processed'])->toBe('30');

        cleanupRedisKeys($publisher, $prefix, ['w1', 'w2', 'w3']);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('publishes aggregated metrics', function () {
    $prefix = 'torque-agg-test:';
    $publisher = createPublisher($prefix);

    try {
        $snapshots = [
            makeSnapshot(jobsProcessed: 100, jobsFailed: 5, activeSlots: 10, totalSlots: 50, averageLatencyMs: 20.0, memoryBytes: 50_000_000, timestamp: time() - 10),
            makeSnapshot(jobsProcessed: 200, jobsFailed: 10, activeSlots: 20, totalSlots: 50, averageLatencyMs: 30.0, memoryBytes: 60_000_000, timestamp: time() - 10),
        ];

        $publisher->publishAggregatedMetrics($snapshots);

        $metrics = $publisher->getAggregatedMetrics();

        expect($metrics)->toBeArray()
            ->toHaveKey('jobs_processed')
            ->toHaveKey('jobs_failed')
            ->toHaveKey('concurrent')
            ->toHaveKey('total_slots')
            ->toHaveKey('avg_latency')
            ->toHaveKey('memory_mb')
            ->toHaveKey('workers')
            ->toHaveKey('updated_at');

        expect($metrics['jobs_processed'])->toBe('300');
        expect($metrics['jobs_failed'])->toBe('15');
        expect($metrics['concurrent'])->toBe('30');
        expect($metrics['total_slots'])->toBe('100');
        expect($metrics['workers'])->toBe('2');

        // Memory: (50_000_000 + 60_000_000) / 1_048_576 = ~104.90
        expect((float) $metrics['memory_mb'])->toBeGreaterThan(100.0);

        // Weighted avg latency: (20.0 * 105 + 30.0 * 210) / 315 = 26.67
        expect((float) $metrics['avg_latency'])->toBeGreaterThan(0.0);

        // Clean up.
        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);
        $redis->execute('DEL', $prefix . 'metrics');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('reads back aggregated metrics', function () {
    $prefix = 'torque-agg-read-test:';
    $publisher = createPublisher($prefix);

    try {
        $publisher->publishAggregatedMetrics([
            makeSnapshot(jobsProcessed: 50, jobsFailed: 2, timestamp: time() - 5),
        ]);

        $metrics = $publisher->getAggregatedMetrics();

        expect($metrics)->toBeArray()->not->toBeEmpty();
        expect($metrics['jobs_processed'])->toBe('50');
        expect($metrics['jobs_failed'])->toBe('2');
        expect($metrics['workers'])->toBe('1');

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);
        $redis->execute('DEL', $prefix . 'metrics');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns null when no worker metrics exist', function () {
    $prefix = 'torque-empty-test:';
    $publisher = createPublisher($prefix);

    try {
        $metrics = $publisher->getWorkerMetrics('nonexistent-worker');

        expect($metrics)->toBeNull();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns empty array when no aggregated metrics exist', function () {
    $prefix = 'torque-empty-agg-test:';
    $publisher = createPublisher($prefix);

    try {
        $metrics = $publisher->getAggregatedMetrics();

        expect($metrics)->toBeArray()->toBeEmpty();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns empty array from getAllWorkerMetrics when no workers exist', function () {
    $prefix = 'torque-empty-all-test:';
    $publisher = createPublisher($prefix);

    try {
        $all = $publisher->getAllWorkerMetrics();

        expect($all)->toBeArray()->toBeEmpty();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
