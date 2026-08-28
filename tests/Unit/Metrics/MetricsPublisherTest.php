<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
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
        $redis->execute('DEL', $prefix.'worker:'.$id);
    }

    $redis->execute('DEL', $prefix.'metrics');
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
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
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

        $ttl = (int) $redis->execute('TTL', $prefix.'worker:worker-ttl');

        // HEARTBEAT_TTL_SECONDS is 60; TTL should be between 1 and 60.
        expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

        cleanupRedisKeys($publisher, $prefix, ['worker-ttl']);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
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
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
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
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
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
        $redis->execute('DEL', $prefix.'metrics');
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
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
        $redis->execute('DEL', $prefix.'metrics');
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('returns null when no worker metrics exist', function () {
    $prefix = 'torque-empty-test:';
    $publisher = createPublisher($prefix);

    try {
        $metrics = $publisher->getWorkerMetrics('nonexistent-worker');

        expect($metrics)->toBeNull();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('returns empty array when no aggregated metrics exist', function () {
    $prefix = 'torque-empty-agg-test:';
    $publisher = createPublisher($prefix);

    try {
        $metrics = $publisher->getAggregatedMetrics();

        expect($metrics)->toBeArray()->toBeEmpty();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('returns empty array from getAllWorkerMetrics when no workers exist', function () {
    $prefix = 'torque-empty-all-test:';
    $publisher = createPublisher($prefix);

    try {
        $all = $publisher->getAllWorkerMetrics();

        expect($all)->toBeArray()->toBeEmpty();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('publishes pid and host fields derived from the worker id', function () {
    $prefix = 'torque-pid-test:';
    $publisher = createPublisher($prefix);

    try {
        $publisher->publishWorkerMetrics('web-01-5123-a1b2c3d4', makeSnapshot());

        $metrics = $publisher->getWorkerMetrics('web-01-5123-a1b2c3d4');

        expect($metrics['pid'])->toBe('5123')
            ->and($metrics['host'])->toBe('web-01');

        cleanupRedisKeys($publisher, $prefix, ['web-01-5123-a1b2c3d4']);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('publishes a precomputed aggregate with an expiry', function () {
    $prefix = 'torque-agg-test:';
    $publisher = createPublisher($prefix);

    try {
        $publisher->publishAggregate([
            'throughput' => 123.456,
            'concurrent' => 7,
            'total_slots' => 200,
            'avg_latency' => 12.5,
            'jobs_processed' => 4200,
            'jobs_failed' => 3,
            'memory_mb' => 128.5,
            'workers' => 4,
        ]);

        $aggregate = $publisher->getAggregatedMetrics();

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);
        $ttl = (int) $redis->execute('TTL', $prefix.'metrics');

        expect($aggregate['throughput'])->toBe('123.46')
            ->and($aggregate['workers'])->toBe('4')
            ->and($aggregate['jobs_processed'])->toBe('4200')
            // A dead publisher must read as "no data", never as stale numbers.
            ->and($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(30);

        cleanupRedisKeys($publisher, $prefix, []);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('sets an expiry on the snapshot-based aggregate publish as well', function () {
    $prefix = 'torque-agg2-test:';
    $publisher = createPublisher($prefix);

    try {
        $publisher->publishAggregatedMetrics([makeSnapshot()]);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);
        $ttl = (int) $redis->execute('TTL', $prefix.'metrics');

        expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(30);

        cleanupRedisKeys($publisher, $prefix, []);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

/**
 * Delete every rollup hash a test may have written, cluster-wide and per queue.
 */
function cleanupRollups(MetricsPublisher $publisher, string $prefix, array $queues = []): void
{
    $redis = (new ReflectionClass($publisher))
        ->getMethod('getRedis')
        ->invoke($publisher);

    foreach (['minute', 'hour', 'day'] as $tier) {
        $redis->execute('DEL', $prefix.'metrics:rollup:'.$tier);

        foreach ($queues as $queue) {
            $redis->execute('DEL', $prefix.'metrics:rollup:'.$tier.':'.$queue);
        }
    }

    $redis->execute('DEL', $prefix.'metrics:buckets');
}

it('records outcomes into every tier at once', function () {
    $prefix = 'torque-tier-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupRollups($publisher, $prefix);

        $publisher->recordOutcomes(40, 2, [], $now);
        $publisher->recordOutcomes(2, 0, [], $now);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        // Every tier holds the same totals, only bucketed differently, and the
        // value is the compact "processed,failed" pair.
        expect($redis->execute('HGET', $prefix.'metrics:rollup:minute', (string) (intdiv($now, 60) * 60)))->toBe('42,2')
            ->and($redis->execute('HGET', $prefix.'metrics:rollup:hour', (string) (intdiv($now, 3600) * 3600)))->toBe('42,2')
            ->and($redis->execute('HGET', $prefix.'metrics:rollup:day', (string) (intdiv($now, 86400) * 86400)))->toBe('42,2');

        // An idle tick writes nothing at all.
        $publisher->recordOutcomes(0, 0, [], $now);

        expect($redis->execute('HLEN', $prefix.'metrics:rollup:minute'))->toBe(1);

        cleanupRollups($publisher, $prefix);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('keeps per-queue rollups separate from the cluster totals', function () {
    $prefix = 'torque-queue-tier-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupRollups($publisher, $prefix, ['default', 'high']);

        $publisher->recordOutcomes(10, 1, ['default' => [7, 1], 'high' => [3, 0]], $now);

        $series = $publisher->series('minute', 3, 'default', $now);
        $high = $publisher->series('minute', 3, 'high', $now);
        $bucket = intdiv($now, 60) * 60;

        expect($series[$bucket])->toBe(['processed' => 7, 'failed' => 1])
            ->and($high[$bucket])->toBe(['processed' => 3, 'failed' => 0])
            ->and($publisher->series('minute', 3, null, $now)[$bucket])->toBe(['processed' => 10, 'failed' => 1])
            // Queues with nothing to report never get a key written.
            ->and($publisher->series('minute', 3, 'unused', $now)[$bucket])->toBe(['processed' => 0, 'failed' => 0]);

        cleanupRollups($publisher, $prefix, ['default', 'high']);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('gap-fills each tier oldest first', function () {
    $prefix = 'torque-gap-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupRollups($publisher, $prefix);

        $publisher->recordOutcomes(1500, 0, [], $now - 7200);
        $publisher->recordOutcomes(30, 5, [], $now);

        $hours = $publisher->series('hour', 3, null, $now);
        $currentHour = intdiv($now, 3600) * 3600;

        expect(array_keys($hours))->toBe([$currentHour - 7200, $currentHour - 3600, $currentHour])
            ->and($hours[$currentHour - 7200]['processed'])->toBe(1500)
            // The quiet hour in between is a zero, not a missing key.
            ->and($hours[$currentHour - 3600])->toBe(['processed' => 0, 'failed' => 0])
            ->and($hours[$currentHour])->toBe(['processed' => 30, 'failed' => 5]);

        // The minute projection keeps its shape: bucket epoch => processed.
        $minutes = $publisher->minuteBuckets(3, $now);

        expect(array_values($minutes))->toBe([0, 0, 30]);

        cleanupRollups($publisher, $prefix);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('prunes each tier on its own schedule', function () {
    $prefix = 'torque-tier-prune-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupRollups($publisher, $prefix);

        // Three days back: outside the minute tier's 24h retention, inside the
        // hourly (90d) and daily (2y) windows.
        $publisher->recordOutcomes(99, 0, [], $now - 3 * 86400);
        $publisher->recordOutcomes(7, 0, [], $now);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        expect($redis->execute('HLEN', $prefix.'metrics:rollup:minute'))->toBe(1)
            ->and($redis->execute('HLEN', $prefix.'metrics:rollup:hour'))->toBe(2)
            ->and($redis->execute('HLEN', $prefix.'metrics:rollup:day'))->toBe(2)
            // The expiry safety net covers the key itself, not just its fields.
            ->and((int) $redis->execute('TTL', $prefix.'metrics:rollup:minute'))->toBeGreaterThan(0);

        cleanupRollups($publisher, $prefix);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('migrates the pre-rollup buckets hash into the minute tier', function () {
    $prefix = 'torque-migrate-test:';
    $publisher = createPublisher($prefix);
    $now = time();
    $minuteIndex = intdiv($now, 60);

    try {
        cleanupRollups($publisher, $prefix);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        // The old shape: minute index => bare processed count.
        $redis->execute('HSET', $prefix.'metrics:buckets', (string) ($minuteIndex - 1), '120', (string) $minuteIndex, '8');

        $buckets = $publisher->minuteBuckets(2, $now);

        expect(array_values($buckets))->toBe([120, 8])
            // Rescaled to bucket epochs and the legacy key is gone.
            ->and(array_keys($buckets))->toBe([($minuteIndex - 1) * 60, $minuteIndex * 60])
            ->and((int) $redis->execute('EXISTS', $prefix.'metrics:buckets'))->toBe(0);

        cleanupRollups($publisher, $prefix);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('totals a range from the finest tier that covers it', function () {
    $prefix = 'torque-since-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupRollups($publisher, $prefix, ['default']);

        $publisher->recordOutcomes(10, 1, ['default' => [10, 1]], $now - 1800);
        $publisher->recordOutcomes(5, 0, ['default' => [5, 0]], $now);
        // Older than the requested range, must not be counted.
        $publisher->recordOutcomes(999, 9, ['default' => [999, 9]], $now - 4 * 86400);

        expect($publisher->totalsSince($now - 3600, null, $now))->toBe(['processed' => 15, 'failed' => 1])
            ->and($publisher->totalsSince($now - 3600, 'default', $now))->toBe(['processed' => 15, 'failed' => 1])
            // A range beyond minute retention falls through to the hour tier,
            // which still has the four-day-old burst.
            ->and($publisher->totalsSince($now - 7 * 86400, null, $now))->toBe(['processed' => 1014, 'failed' => 10]);

        cleanupRollups($publisher, $prefix, ['default']);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('averages jobs per minute over a window that accounts for the partial minute', function () {
    // 09:00:30, so the newest bucket is half elapsed.
    $now = 1_700_000_000 - (1_700_000_000 % 60) + 30;
    $minute = intdiv($now, 60) * 60;

    $buckets = [
        $minute - 240 => 0,
        $minute - 180 => 0,
        $minute - 120 => 1500,
        $minute - 60 => 0,
        $minute => 30,
    ];

    // 1530 jobs over 4 whole minutes plus the elapsed half of the current one.
    expect(MetricsPublisher::perMinuteRate($buckets, 5, $now))->toBe(1530 / 4.5)
        // A one-minute window sees only the partial bucket: 30 jobs in 30s.
        ->and(MetricsPublisher::perMinuteRate($buckets, 1, $now))->toBe(60.0)
        ->and(MetricsPublisher::perMinuteRate([], 5, $now))->toBe(0.0);
});

it('publishes rolling and smoothed throughput alongside the instantaneous rate', function () {
    $prefix = 'torque-rate-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupRollups($publisher, $prefix);
        cleanupRedisKeys($publisher, $prefix, []);

        // One burst of 1500 a minute ago, nothing since: the instantaneous
        // rate is 0 but the queue really did 1500 jobs in the last hour.
        $publisher->recordOutcomes(1500, 0, [], $now - 60);
        $publisher->publishAggregate(['throughput' => 0.0, 'workers' => 2]);

        $aggregate = $publisher->getAggregatedMetrics();

        expect($aggregate)->toHaveKeys(['throughput', 'throughput_1m', 'throughput_5m', 'throughput_smoothed', 'jobs_last_hour'])
            ->and($aggregate['throughput'])->toBe('0')
            ->and($aggregate['jobs_last_hour'])->toBe('1500')
            ->and((float) $aggregate['throughput_5m'])->toBeGreaterThan(250.0)->toBeLessThan(400.0);

        $seeded = (float) $aggregate['throughput_smoothed'];

        // The first publish seeds the EMA, so the needle does not crawl up from
        // zero after a restart.
        expect($seeded)->toBe((float) $aggregate['throughput_5m']);

        // A second burst must move the smoothed value part of the way only.
        $publisher->recordOutcomes(1500, 0, [], $now);
        $publisher->publishAggregate(['throughput' => 25.0, 'workers' => 2]);

        $second = $publisher->getAggregatedMetrics();

        expect((float) $second['throughput_smoothed'])
            ->toBeGreaterThan($seeded)
            ->toBeLessThan((float) $second['throughput_5m']);

        cleanupRollups($publisher, $prefix);
        cleanupRedisKeys($publisher, $prefix, []);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('publishes and sums per-queue counters across workers', function () {
    $prefix = 'torque-perqueue-test:';
    $publisher = createPublisher($prefix);

    try {
        $publisher->publishWorkerMetrics('web-01-1-aaaaaaaa', makeSnapshot(
            jobsProcessed: 10,
            jobsFailed: 1,
        ));
        $publisher->publishWorkerMetrics('web-02-2-bbbbbbbb', new WorkerSnapshot(
            jobsProcessed: 5,
            jobsFailed: 0,
            activeSlots: 1,
            totalSlots: 50,
            averageLatencyMs: 10.0,
            slotUsageRatio: 0.02,
            memoryBytes: 1024,
            timestamp: time(),
            perQueue: ['default' => [4, 1], 'high' => [1, 0]],
        ));

        $workers = $publisher->getAllWorkerMetrics();

        // A worker without attribution contributes an empty list, not a crash.
        expect($workers['web-01-1-aaaaaaaa']['per_queue'])->toBe('[]')
            ->and($workers['web-02-2-bbbbbbbb']['per_queue'])->toBe('{"default":[4,1],"high":[1,0]}');

        $aggregate = $publisher->aggregateFromWorkers($workers);

        expect($aggregate['per_queue'])->toBe(['default' => [4, 1], 'high' => [1, 0]]);

        // Two workers on the same stream sum rather than overwrite.
        $summed = $publisher->aggregateFromWorkers([
            'a' => ['per_queue' => '{"default":[4,1]}'],
            'b' => ['per_queue' => '{"default":[6,2]}'],
            'c' => ['per_queue' => 'not json'],
        ]);

        expect($summed['per_queue'])->toBe(['default' => [10, 3]]);

        cleanupRedisKeys($publisher, $prefix, ['web-01-1-aaaaaaaa', 'web-02-2-bbbbbbbb']);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

/**
 * Delete the gauge hashes and the per-class rollups a test may have written.
 */
function cleanupGaugesAndJobs(MetricsPublisher $publisher, string $prefix, array $classes = []): void
{
    $redis = (new ReflectionClass($publisher))
        ->getMethod('getRedis')
        ->invoke($publisher);

    foreach (['minute', 'hour', 'day'] as $tier) {
        $redis->execute('DEL', $prefix.'metrics:gauge:'.$tier);

        foreach ($classes as $class) {
            $redis->execute('DEL', $prefix.'metrics:rollup:'.$tier.':job:'.$class);
        }
    }

    $redis->execute('DEL', $prefix.'metrics:jobs');
}

it('folds gauge samples into sum, count and max per bucket', function () {
    $prefix = 'torque-gauge-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupGaugesAndJobs($publisher, $prefix);

        $publisher->recordGauges([MetricsPublisher::GAUGE_CONCURRENT => 10, MetricsPublisher::GAUGE_PENDING => 400], $now);
        $publisher->recordGauges([MetricsPublisher::GAUGE_CONCURRENT => 30, MetricsPublisher::GAUGE_PENDING => 200], $now);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        $bucket = intdiv($now, 60) * 60;

        // "sum,count,max": two samples, so the average is 20 and the peak 30.
        expect($redis->execute('HGET', $prefix.'metrics:gauge:minute', $bucket.':concurrent'))->toBe('40,2,30');

        $series = $publisher->gaugeSeries(MetricsPublisher::GAUGE_CONCURRENT, 'minute', 3, $now);

        expect($series[$bucket])->toBe(['avg' => 20.0, 'max' => 30.0])
            // Quiet buckets read as zero rather than vanishing.
            ->and($series[$bucket - 60])->toBe(['avg' => 0.0, 'max' => 0.0])
            ->and(array_keys($series))->toBe([$bucket - 120, $bucket - 60, $bucket]);

        // Every metric of a tier shares one hash, so one read serves them all.
        $both = $publisher->gaugeSeriesMulti(
            [MetricsPublisher::GAUGE_CONCURRENT, MetricsPublisher::GAUGE_PENDING],
            'minute',
            2,
            $now,
        );

        expect($both[MetricsPublisher::GAUGE_PENDING][$bucket])->toBe(['avg' => 300.0, 'max' => 400.0]);

        // The same sample lands in all three tiers.
        expect($publisher->gaugeSeries(MetricsPublisher::GAUGE_CONCURRENT, 'day', 1, $now))
            ->toBe([intdiv($now, 86400) * 86400 => ['avg' => 20.0, 'max' => 30.0]]);

        cleanupGaugesAndJobs($publisher, $prefix);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('records per-job-class outcomes with summed runtime and a peak', function () {
    $prefix = 'torque-jobclass-test:';
    $publisher = createPublisher($prefix);
    $now = time();
    $class = 'App\\Jobs\\ProcessPodcast';

    try {
        cleanupGaugesAndJobs($publisher, $prefix, [$class]);

        $publisher->recordJobOutcomes([$class => [2, 0, 300.0, 200.0]], $now);
        $publisher->recordJobOutcomes([$class => [1, 1, 150.0, 120.0]], $now);

        $bucket = intdiv($now, 60) * 60;
        $series = $publisher->jobSeries($class, 'minute', 2, $now);

        expect($series[$bucket])->toBe([
            'processed' => 3,
            'failed' => 1,
            'runtimeSumMs' => 450.0,
            // Runtime peak is a high-water mark, not a sum.
            'runtimeMaxMs' => 200.0,
        ])
            ->and($series[$bucket - 60]['processed'])->toBe(0);

        $totals = $publisher->jobTotals($class, $now - 3600, $now);

        expect($totals['processed'])->toBe(3)
            ->and($totals['failed'])->toBe(1)
            // 450ms over the four jobs that finished.
            ->and($totals['avgRuntimeMs'])->toBe(112.5)
            ->and($totals['maxRuntimeMs'])->toBe(200.0);

        expect($publisher->jobClasses())->toBe([$class]);

        // An empty outcome is not worth a key or an index entry.
        $publisher->recordJobOutcomes(['App\\Jobs\\Idle' => [0, 0, 0.0, 0.0]], $now);

        expect($publisher->jobClasses())->toBe([$class]);

        cleanupGaugesAndJobs($publisher, $prefix, [$class]);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('drops job classes from the index once their daily rollup is gone', function () {
    $prefix = 'torque-jobindex-test:';
    $publisher = createPublisher($prefix);
    $now = time();
    $class = 'App\\Jobs\\Retired';

    try {
        cleanupGaugesAndJobs($publisher, $prefix, [$class]);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        // A class whose rollups have already aged out, still in the index: a
        // Redis set has no per-member TTL, so only the prune can remove it.
        $redis->execute('SADD', $prefix.'metrics:jobs', $class);

        $publisher->recordJobOutcomes(['App\\Jobs\\Active' => [1, 0, 10.0, 10.0]], $now);

        expect($publisher->jobClasses())->toBe(['App\\Jobs\\Active']);

        cleanupGaugesAndJobs($publisher, $prefix, [$class, 'App\\Jobs\\Active']);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('sums per-class counters across workers and keeps the largest runtime', function () {
    $prefix = 'torque-perjob-test:';
    $publisher = createPublisher($prefix);

    try {
        $aggregate = $publisher->aggregateFromWorkers([
            'a' => [
                'memory_bytes' => (string) (64 * 1024 * 1024),
                'per_job' => '{"App\\\\Jobs\\\\A":[4,1,400,180]}',
            ],
            'b' => [
                'memory_bytes' => (string) (192 * 1024 * 1024),
                'per_job' => '{"App\\\\Jobs\\\\A":[6,2,600,240],"App\\\\Jobs\\\\B":[1,0,20,20]}',
            ],
            'c' => ['per_job' => 'not json'],
        ]);

        // Counters and runtime sums add; the peak is the largest any one worker
        // saw, never their sum.
        expect($aggregate['per_job'])->toBe([
            'App\\Jobs\\A' => [10, 3, 1000.0, 240.0],
            'App\\Jobs\\B' => [1, 0, 20.0, 20.0],
        ])
            // The fleet total hides the one worker about to be recycled.
            ->and($aggregate['memory_mb'])->toBe(256.0)
            ->and($aggregate['memory_peak_mb'])->toBe(192.0);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});
