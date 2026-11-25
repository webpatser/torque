<?php

declare(strict_types=1);

use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Connection\RedisTimeoutException;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Webpatser\Torque\Dashboard\Data\JobMetricsData;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Metrics\WorkerSnapshot;
use Webpatser\Torque\Support\NodeName;

use function Fledge\Async\Redis\createRedisConnector;

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

    $redis->execute('DEL', $prefix.'metrics', $prefix.'metrics:live', $prefix.'metrics:workers');
    deleteKeysMatching($redis, $prefix.'metrics:live:*');
}

/**
 * Point a publisher at a client on the real test Redis that records the name
 * of every command it sends, and return that record.
 *
 * @return ArrayObject<int, string>
 */
function recordPublisherCommands(MetricsPublisher $publisher): ArrayObject
{
    $commands = new ArrayObject;
    $link = new ReconnectingRedisLink(createRedisConnector(env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15')));

    (new ReflectionProperty(MetricsPublisher::class, 'redis'))->setValue($publisher, new RedisClient(new class($link, $commands) implements RedisLink
    {
        public function __construct(private readonly RedisLink $link, private readonly ArrayObject $commands) {}

        public function execute(string $command, array $parameters): RedisResponse
        {
            $this->commands[] = strtoupper($command);

            return $this->link->execute($command, $parameters);
        }
    }));

    return $commands;
}

/**
 * Delete every key matching a pattern; the test database is small, so KEYS is fine.
 */
function deleteKeysMatching(mixed $redis, string $pattern): void
{
    $keys = $redis->execute('KEYS', $pattern);

    foreach (is_array($keys) ? $keys : [] as $key) {
        $redis->execute('DEL', (string) $key);
    }
}

/**
 * The per-host aggregate key a publisher on this machine writes.
 */
function localAggregateKey(string $prefix): string
{
    return $prefix.'metrics:live:'.MetricsPublisher::normaliseHost((string) gethostname());
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

/**
 * The master calls this every tick. A SCAN MATCH walks the whole database in
 * pages of 100 whatever the number of workers, so the command count has to
 * follow the workers and not the keyspace.
 */
it('reads all workers from the index without walking the keyspace', function () {
    $prefix = 'torque-index-test:';
    $publisher = createPublisher($prefix);
    $ids = ['w1', 'w2', 'w3'];
    $filler = array_map(static fn (int $i): string => $prefix.'job:filler-'.$i, range(1, 1000));

    try {
        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);
        cleanupRedisKeys($publisher, $prefix, $ids);

        foreach ($ids as $i => $id) {
            $publisher->publishWorkerMetrics($id, makeSnapshot(jobsProcessed: 10 * ($i + 1)));
        }

        foreach (array_chunk($filler, 250) as $chunk) {
            $redis->execute('MSET', ...array_merge(...array_map(static fn (string $key): array => [$key, '1'], $chunk)));
        }

        $commands = recordPublisherCommands($publisher);
        $all = $publisher->getAllWorkerMetrics();

        expect(array_map(static fn (array $w): string => $w['jobs_processed'], $all))
            ->toEqualCanonicalizing(['w1' => '10', 'w2' => '20', 'w3' => '30'])
            ->and($commands->getArrayCopy())->not->toContain('SCAN')
            // The index, the old-master check, one hash per worker.
            ->and($commands->getArrayCopy())->toBe(['ZRANGE', 'EXISTS', ...array_fill(0, count($ids), 'HGETALL')]);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupRedisKeys($publisher, $prefix, $ids);
        $redis->execute('DEL', ...$filler);
    }
});

it('drops a worker from the index once its hash is gone', function () {
    $prefix = 'torque-index-stale-test:';
    $publisher = createPublisher($prefix);
    $ids = ['w1', 'w2', 'w3'];

    try {
        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);
        cleanupRedisKeys($publisher, $prefix, $ids);

        foreach ($ids as $id) {
            $publisher->publishWorkerMetrics($id, makeSnapshot());
        }

        // w2 left gracefully, w3's hash expired (a crash): neither is listed,
        // and neither stays in the index.
        $publisher->removeWorkerMetrics('w2');
        $redis->execute('DEL', $prefix.'worker:w3');

        expect(array_keys($publisher->getAllWorkerMetrics()))->toBe(['w1'])
            ->and($redis->execute('ZRANGE', $prefix.'metrics:workers', '0', '-1'))->toBe(['w1'])
            ->and((int) $redis->execute('TTL', $prefix.'metrics:workers'))->toBeGreaterThan(0);

        $publisher->removeAllWorkerMetrics();

        expect($publisher->getAllWorkerMetrics())->toBe([])
            ->and($redis->execute('EXISTS', $prefix.'metrics:workers'))->toBe(0);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupRedisKeys($publisher, $prefix, $ids);
    }
});

it('still finds workers that publish without the index, as during a deploy', function () {
    $prefix = 'torque-index-legacy-test:';
    $publisher = createPublisher($prefix);

    try {
        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);
        cleanupRedisKeys($publisher, $prefix, ['old-1']);

        // A worker on the previous release writes its hash and nothing else.
        $redis->execute('HSET', $prefix.'worker:old-1', 'host', 'web-01', 'jobs_processed', '7');
        $redis->execute('EXPIRE', $prefix.'worker:old-1', '60');

        expect($publisher->getAllWorkerMetrics())->toBe(['old-1' => ['host' => 'web-01', 'jobs_processed' => '7']]);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupRedisKeys($publisher, $prefix, ['old-1']);
    }
});

/**
 * A rolling deploy: web-02 already runs this release and fills the index,
 * web-01 still runs the previous one, whose workers write only their hash and
 * whose master writes the shared `{prefix}metrics` hash (30 second TTL). While
 * that hash exists the reader adds a SCAN; once it is gone nothing scans.
 */
it('lists workers of hosts still on the previous release while an old master is alive', function () {
    $prefix = 'torque-index-mixed-test:';
    $publisher = createPublisher($prefix);
    $ids = ['web-02-2-bbbbbbbb', 'old-1'];

    try {
        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);
        cleanupRedisKeys($publisher, $prefix, $ids);

        $publisher->publishWorkerMetrics('web-02-2-bbbbbbbb', makeSnapshot(jobsProcessed: 20));
        $redis->execute('HSET', $prefix.'worker:old-1', 'host', 'web-01', 'jobs_processed', '7');
        $redis->execute('EXPIRE', $prefix.'worker:old-1', '60');
        $redis->execute('HSET', $prefix.'metrics', 'workers', '1');
        $redis->execute('EXPIRE', $prefix.'metrics', '30');

        $mixed = $publisher->getAllWorkerMetrics();

        expect(array_keys($mixed))->toEqualCanonicalizing($ids)
            ->and($mixed['old-1']['jobs_processed'])->toBe('7')
            ->and($mixed['web-02-2-bbbbbbbb']['jobs_processed'])->toBe('20');

        // The last old master stopped: its hash expired, the index alone is read.
        $redis->execute('DEL', $prefix.'metrics');
        $commands = recordPublisherCommands($publisher);

        expect(array_keys($publisher->getAllWorkerMetrics()))->toBe(['web-02-2-bbbbbbbb'])
            ->and($commands->getArrayCopy())->not->toContain('SCAN');
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupRedisKeys($publisher, $prefix, $ids);
    }
});

it('publishes a worker hash and its index entry in two round trips', function () {
    $prefix = 'torque-index-publish-test:';
    $publisher = createPublisher($prefix);

    try {
        cleanupRedisKeys($publisher, $prefix, ['w1']);

        $commands = recordPublisherCommands($publisher);

        // The first publish may load the scripts; count a steady one.
        $publisher->publishWorkerMetrics('w1', makeSnapshot());
        $commands->exchangeArray([]);
        $publisher->publishWorkerMetrics('w1', makeSnapshot(jobsProcessed: 11));

        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);

        expect(count($commands))->toBe(2)
            ->and($publisher->getWorkerMetrics('w1')['jobs_processed'])->toBe('11')
            ->and((int) $redis->execute('TTL', $prefix.'worker:w1'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
            ->and($redis->execute('ZRANGE', $prefix.'metrics:workers', '0', '-1'))->toBe(['w1'])
            ->and((int) $redis->execute('TTL', $prefix.'metrics:workers'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupRedisKeys($publisher, $prefix, ['w1']);
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

        cleanupRedisKeys($publisher, $prefix, []);
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

        cleanupRedisKeys($publisher, $prefix, []);
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

it('publishes the master and group fields when known', function () {
    $prefix = 'torque-master-field-test:';
    $publisher = createPublisher($prefix);

    try {
        $publisher->publishWorkerMetrics('web-01-5123-a1b2c3d4', makeSnapshot(), 'web-01-77-0badf00d', 'imports');
        $publisher->publishWorkerMetrics('web-01-5124-a1b2c3d5', makeSnapshot());

        $known = $publisher->getWorkerMetrics('web-01-5123-a1b2c3d4');
        $unknown = $publisher->getWorkerMetrics('web-01-5124-a1b2c3d5');

        expect($known['master'])->toBe('web-01-77-0badf00d')
            ->and($known['group'])->toBe('imports')
            ->and($unknown)->not->toHaveKey('master')
            ->and($unknown)->not->toHaveKey('group');

        cleanupRedisKeys($publisher, $prefix, ['web-01-5123-a1b2c3d4', 'web-01-5124-a1b2c3d5']);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('names the local host after the node', function () {
    expect(MetricsPublisher::localHost())->toBe(NodeName::current());
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
        $ttl = (int) $redis->execute('TTL', localAggregateKey($prefix));

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
        $ttl = (int) $redis->execute('TTL', localAggregateKey($prefix));

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

    deleteKeysMatching($redis, $prefix.'metrics:prune:*');
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
    deleteKeysMatching($redis, $prefix.'metrics:prune:*');
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

/**
 * Point a publisher at a client on the real test Redis that records every
 * command, the hash fields its HMGETs name, and the round trips: a round trip
 * starts whenever a command goes out while none is in flight, so commands
 * pipelined on the one connection count once.
 */
function recordPublisherRoundTrips(MetricsPublisher $publisher): stdClass
{
    $log = (object) ['commands' => [], 'fields' => 0, 'roundTrips' => 0, 'inFlight' => 0];
    $link = new ReconnectingRedisLink(createRedisConnector(env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15')));

    (new ReflectionProperty(MetricsPublisher::class, 'redis'))->setValue($publisher, new RedisClient(new class($link, $log) implements RedisLink
    {
        public function __construct(private readonly RedisLink $link, private readonly stdClass $log) {}

        public function execute(string $command, array $parameters): RedisResponse
        {
            $command = strtoupper($command);

            if ($this->log->inFlight === 0) {
                $this->log->roundTrips++;
            }

            $this->log->inFlight++;
            $this->log->commands[] = $command;

            if ($command === 'HMGET') {
                $this->log->fields += count($parameters) - 1;
            }

            try {
                return $this->link->execute($command, $parameters);
            } finally {
                $this->log->inFlight--;
            }
        }
    }));

    return $log;
}

/**
 * The jobs screen polls. Every class has a rollup key of its own, so reading
 * them one after the other cost a round trip per class (two on the coarser
 * ranges, for the minute spark), and each read pulled the class's whole hash:
 * a full day of minute buckets for a 60 minute view.
 */
it('reads the jobs screen in one round trip per tier and only the window fields', function () {
    $prefix = 'torque-jobscreen-test:';
    $publisher = createPublisher($prefix);
    $now = time();
    $active = [
        'App\\Jobs\\Alpha' => [10, 0, 100.0, 20.0],
        'App\\Jobs\\Bravo' => [4, 1, 50.0, 30.0],
        'App\\Jobs\\Charlie' => [1, 0, 5.0, 5.0],
        'App\\Jobs\\Delta' => [0, 3, 9.0, 4.0],
    ];
    $stale = 'App\\Jobs\\Stale';
    $classes = [...array_keys($active), $stale];

    try {
        cleanupGaugesAndJobs($publisher, $prefix, $classes);

        $publisher->recordJobOutcomes($active, $now);

        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);

        // In the index, no activity in any window: no row and no spark read.
        $redis->execute('SADD', $prefix.'metrics:jobs', $stale);

        // Old minute buckets the 60 minute view must not pull back.
        $old = intdiv($now, 60) * 60 - 7200;
        $redis->execute('HSET', $prefix.'metrics:rollup:minute:job:App\\Jobs\\Alpha',
            ...array_merge(...array_map(static fn (int $i): array => [(string) ($old - 60 * $i), '1,0,1,1'], range(0, 499))));

        $data = new JobMetricsData($publisher);
        $rows = static fn (array $result): array => array_map(
            static fn (array $row): array => [
                $row['class'], $row['processed'], $row['failed'], $row['avgRuntimeMs'], $row['maxRuntimeMs'], array_sum($row['history']),
            ],
            $result['jobs'],
        );

        // 7 days: the hour tier for the range, the minute tier for the spark.
        $log = recordPublisherRoundTrips($publisher);
        $week = $data->get('7d', 'name', 'asc');

        expect($rows($week))->toBe([
            ['App\\Jobs\\Alpha', 10, 0, 10.0, 20.0, 10],
            ['App\\Jobs\\Bravo', 4, 1, 10.0, 30.0, 4],
            ['App\\Jobs\\Charlie', 1, 0, 5.0, 5.0, 1],
            ['App\\Jobs\\Delta', 0, 3, 3.0, 4.0, 0],
        ])
            ->and($week['totals'])->toBe(['classes' => 4, 'processed' => 15, 'failed' => 4, 'slowest' => 30.0])
            ->and($log->commands)->not->toContain('HGETALL')
            ->and(count($log->commands))->toBe(1 + count($classes) + count($active))
            ->and($log->roundTrips)->toBe(3)
            ->and($log->fields)->toBe(count($classes) * 168 + count($active) * 60);

        // 60 minutes: the minute tier serves the range and the spark at once.
        $log = recordPublisherRoundTrips($publisher);
        $hour = $data->get('1h', 'throughput', 'desc');

        expect(array_column($hour['jobs'], 'class'))->toBe(['App\\Jobs\\Alpha', 'App\\Jobs\\Bravo', 'App\\Jobs\\Delta', 'App\\Jobs\\Charlie'])
            ->and(array_column($hour['jobs'], 'throughput'))->toBe([0.17, 0.08, 0.05, 0.02])
            ->and($log->commands)->not->toContain('HGETALL')
            ->and(count($log->commands))->toBe(1 + count($classes))
            ->and($log->roundTrips)->toBe(2)
            ->and($log->fields)->toBe(count($classes) * 60);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupGaugesAndJobs($publisher, $prefix, $classes);
    }
});

it('reads several job classes in one call with the same series as one at a time', function () {
    $prefix = 'torque-jobmulti-test:';
    $publisher = createPublisher($prefix);
    $now = time();
    $classes = ['App\\Jobs\\One', 'App\\Jobs\\Two', 'App\\Jobs\\Never'];

    try {
        cleanupGaugesAndJobs($publisher, $prefix, $classes);

        $publisher->recordJobOutcomes(['App\\Jobs\\One' => [2, 1, 30.0, 20.0]], $now - 60);
        $publisher->recordJobOutcomes(['App\\Jobs\\Two' => [5, 0, 50.0, 15.0]], $now);

        $multi = $publisher->jobSeriesMulti($classes, 'minute', 3, $now);
        $bucket = intdiv($now, 60) * 60;

        expect(array_keys($multi))->toBe($classes)
            ->and($multi['App\\Jobs\\One'][$bucket - 60])->toBe(['processed' => 2, 'failed' => 1, 'runtimeSumMs' => 30.0, 'runtimeMaxMs' => 20.0])
            ->and($multi['App\\Jobs\\Two'][$bucket]['processed'])->toBe(5)
            ->and(array_keys($multi['App\\Jobs\\Never']))->toBe([$bucket - 120, $bucket - 60, $bucket])
            ->and($multi['App\\Jobs\\Never'][$bucket]['processed'])->toBe(0)
            ->and($publisher->jobSeriesMulti([], 'minute', 3, $now))->toBe([]);

        foreach ($classes as $class) {
            expect($publisher->jobSeries($class, 'minute', 3, $now))->toBe($multi[$class]);
        }
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupGaugesAndJobs($publisher, $prefix, $classes);
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

/**
 * Delete the per-host rollups, gauges and the host index.
 */
function cleanupHosts(MetricsPublisher $publisher, string $prefix): void
{
    $redis = (new ReflectionClass($publisher))
        ->getMethod('getRedis')
        ->invoke($publisher);

    foreach (['minute', 'hour', 'day'] as $tier) {
        $redis->execute('DEL', $prefix.'metrics:rollup:'.$tier.':host');
        $redis->execute('DEL', $prefix.'metrics:gauge:'.$tier.':host');
    }

    $redis->execute('DEL', $prefix.'metrics:hosts', $prefix.'metrics:hosts:first');
}

it('records per-host outcomes into every tier under one key per tier', function () {
    $prefix = 'torque-host-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupHosts($publisher, $prefix);

        $publisher->recordHostOutcomes(['web-01' => [3, 1], 'web-02' => [5, 0]], $now);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        // Host lives in the field, not the key, so one hash per tier holds the
        // whole fleet and a write costs three evals whatever its size.
        $bucket = intdiv($now, 60) * 60;

        expect($redis->execute('HGET', $prefix.'metrics:rollup:minute:host', $bucket.':web-01'))->toBe('3,1')
            ->and($redis->execute('HGET', $prefix.'metrics:rollup:minute:host', $bucket.':web-02'))->toBe('5,0');

        // The same jobs land in the hour and day tiers, so a 90d view sees them.
        $day = intdiv($now, 86400) * 86400;
        expect($redis->execute('HGET', $prefix.'metrics:rollup:day:host', $day.':web-01'))->toBe('3,1');

        // Read back gap-filled: one entry per bucket, newest last.
        $series = $publisher->hostSeriesMulti(['web-01', 'web-02'], MetricsPublisher::TIER_MINUTE, 3, $now);

        expect($series['web-01'])->toHaveCount(3)
            ->and(end($series['web-01']))->toBe(['processed' => 3, 'failed' => 1])
            ->and(reset($series['web-01']))->toBe(['processed' => 0, 'failed' => 0])
            ->and(end($series['web-02']))->toBe(['processed' => 5, 'failed' => 0]);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupHosts($publisher, $prefix);
    }
});

it('keeps per-host counters out of the cluster and per-stream rollups', function () {
    $prefix = 'torque-host-scope-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupHosts($publisher, $prefix);

        $publisher->recordHostOutcomes(['web-01' => [7, 0]], $now);

        // The workers screen must not double-count into the overview.
        $cluster = $publisher->series(MetricsPublisher::TIER_MINUTE, 2, null, $now);

        expect(array_sum(array_column($cluster, 'processed')))->toBe(0);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupHosts($publisher, $prefix);
        (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher)
            ->execute('DEL', $prefix.'metrics:rollup:minute');
    }
});

it('clamps a negative per-host delta instead of indexing the host', function () {
    $prefix = 'torque-host-clamp-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupHosts($publisher, $prefix);

        $publisher->recordHostOutcomes(['web-01' => [-5, -2]], $now);

        expect($publisher->hostsSeen(0))->toBe([]);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupHosts($publisher, $prefix);
    }
});

it('folds per-host gauges into sum, count and max under a host-scoped field', function () {
    $prefix = 'torque-host-gauge-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupHosts($publisher, $prefix);

        $publisher->recordHostGauges([
            'web-01' => [MetricsPublisher::GAUGE_HOST_BUSY_SLOTS => 10],
            'web-02' => [MetricsPublisher::GAUGE_HOST_BUSY_SLOTS => 4],
        ], $now);
        $publisher->recordHostGauges([
            'web-01' => [MetricsPublisher::GAUGE_HOST_BUSY_SLOTS => 30],
        ], $now);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        $bucket = intdiv($now, 60) * 60;

        expect($redis->execute('HGET', $prefix.'metrics:gauge:minute:host', $bucket.':web-01:busy_slots'))->toBe('40,2,30')
            // The second sample must not touch the neighbour's field.
            ->and($redis->execute('HGET', $prefix.'metrics:gauge:minute:host', $bucket.':web-02:busy_slots'))->toBe('4,1,4');

        $series = $publisher->hostGaugeSeriesMulti(
            [MetricsPublisher::GAUGE_HOST_BUSY_SLOTS],
            ['web-01'],
            MetricsPublisher::TIER_MINUTE,
            2,
            $now,
        );

        expect(end($series['web-01'][MetricsPublisher::GAUGE_HOST_BUSY_SLOTS]))->toBe(['avg' => 20.0, 'max' => 30.0]);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupHosts($publisher, $prefix);
    }
});

it('lists only the hosts seen inside the requested range', function () {
    $prefix = 'torque-host-index-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupHosts($publisher, $prefix);

        $publisher->recordHostOutcomes(['web-new' => [1, 0]], $now);
        $publisher->recordHostOutcomes(['web-old' => [1, 0]], $now - 172_800);

        // The index is scored by last-seen, so a host that stopped reporting
        // longer ago than the range never reaches the read model.
        expect(array_keys($publisher->hostsSeen($now - 3600)))->toBe(['web-new'])
            ->and(array_keys($publisher->hostsSeen($now - 604_800)))->toBe(['web-new', 'web-old'])
            ->and($publisher->hostsSeen($now - 3600)['web-new'])->toBe($now);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupHosts($publisher, $prefix);
    }
});

it('keeps a first sighting while the host still reports and drops it with the host', function () {
    $prefix = 'torque-host-first-test:';
    $publisher = createPublisher($prefix);
    $now = time();
    $long = $now - 800 * 86400;

    try {
        cleanupHosts($publisher, $prefix);

        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);

        $publisher->recordHostOutcomes(['web-gone' => [1, 0], 'web-long' => [1, 0]], $long);
        $publisher->recordHostOutcomes(['web-long' => [1, 0]], $now);

        // Both were first seen beyond the 730 day retention; only the one that
        // stopped reporting leaves either index.
        expect(array_keys($publisher->hostsSeen(0)))->toBe(['web-long'])
            ->and($redis->execute('ZSCORE', $prefix.'metrics:hosts:first', 'web-gone'))->toBeNull()
            ->and((int) $redis->execute('ZSCORE', $prefix.'metrics:hosts:first', 'web-long'))->toBe($long);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupHosts($publisher, $prefix);
    }
});

it('retries a first sighting whose write failed, with the earliest epoch', function () {
    $prefix = 'torque-host-first-retry-test:';
    $publisher = createPublisher($prefix);
    $now = time();
    $earlier = $now - 7200;

    try {
        cleanupGaugesAndJobs($publisher, $prefix);
        cleanupHosts($publisher, $prefix);

        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);

        // A string where the sorted set belongs makes the first-seen ZADD fail.
        $redis->execute('SET', $prefix.'metrics:hosts:first', 'blocked');

        expect(fn () => $publisher->recordGauges([MetricsPublisher::GAUGE_CONCURRENT => 4], $earlier, 'web-01'))
            ->toThrow(RedisException::class);

        $redis->execute('DEL', $prefix.'metrics:hosts:first');

        // The same process publishes again, then a later process starts on the
        // same host. The retry must carry the earlier epoch, or the later
        // process's first sighting would hide the earliest bucket.
        $publisher->recordGauges([MetricsPublisher::GAUGE_CONCURRENT => 4], $now, 'web-01');
        createPublisher($prefix)->recordGauges([MetricsPublisher::GAUGE_CONCURRENT => 4], $now + 1, 'web-01');

        $series = createPublisher($prefix)->gaugeSeries(MetricsPublisher::GAUGE_CONCURRENT, MetricsPublisher::TIER_HOUR, 3, $now);

        expect((int) $redis->execute('ZSCORE', $prefix.'metrics:hosts:first', 'web-01'))->toBe($earlier)
            ->and($series[intdiv($earlier, 3600) * 3600])->toBe(['avg' => 4.0, 'max' => 4.0]);
    } finally {
        cleanupGaugesAndJobs($publisher, $prefix);
        cleanupHosts($publisher, $prefix);
    }
});

/**
 * Autoscaled pods get a new hostname per deploy, and the host index keeps a
 * name for the day tier's retention. Asking every indexed host for every
 * bucket of the window would cost metrics x buckets x hosts fields per poll;
 * a host is only asked for the buckets between its first and last sighting.
 */
it('reads cluster gauges only for the buckets each host was live in', function () {
    $prefix = 'torque-gauge-churn-test:';
    $publisher = createPublisher($prefix);
    $now = time();
    $hosts = 200;
    $buckets = 168;
    $metrics = [
        MetricsPublisher::GAUGE_CONCURRENT,
        MetricsPublisher::GAUGE_MEMORY,
        MetricsPublisher::GAUGE_WORKER_MEMORY_PEAK,
        MetricsPublisher::GAUGE_LATENCY,
        MetricsPublisher::GAUGE_PENDING,
    ];

    try {
        cleanupGaugesAndJobs($publisher, $prefix);
        cleanupHosts($publisher, $prefix);

        // One short-lived pod every 50 minutes across the week, oldest first,
        // each publishing a single tick. Two pods can share an hour bucket.
        $expected = [];

        for ($i = $hosts - 1; $i >= 0; $i--) {
            $at = $now - $i * 3000;
            $host = 'pod-'.$i;

            $publisher->recordHostOutcomes([$host => [2, 0]], $at);
            $publisher->recordGauges([
                MetricsPublisher::GAUGE_CONCURRENT => $i + 1,
                MetricsPublisher::GAUGE_MEMORY => 10.0,
                MetricsPublisher::GAUGE_WORKER_MEMORY_PEAK => (float) $i,
                MetricsPublisher::GAUGE_LATENCY => 100.0,
                MetricsPublisher::GAUGE_PENDING => 5,
            ], $at, $host);

            $bucket = intdiv($at, 3600) * 3600;
            $expected[$bucket] = ($expected[$bucket] ?? 0) + $i + 1;
        }

        $reader = createPublisher($prefix);
        $series = $reader->gaugeSeriesMulti($metrics, MetricsPublisher::TIER_HOUR, $buckets, $now);
        $fields = $reader->fieldsRequested();

        // A second identical call reports the same count, not a running total.
        expect($reader->gaugeSeriesMulti($metrics, MetricsPublisher::TIER_HOUR, $buckets, $now))->toBe($series)
            ->and($reader->fieldsRequested())->toBe($fields);

        // Unscoped fallback per metric and bucket, plus per host the four
        // host-scoped gauges and the latency weight for its one live bucket.
        $bound = count($metrics) * $buckets + (4 + 1) * $hosts;

        expect($fields)->toBeLessThanOrEqual($bound);

        // The combined series is what the hosts published: slots add per
        // bucket, queue depth is the shared sample, quiet buckets read zero.
        foreach ($series[MetricsPublisher::GAUGE_CONCURRENT] as $bucket => $sample) {
            expect($sample['avg'])->toBe((float) ($expected[$bucket] ?? 0))
                ->and($series[MetricsPublisher::GAUGE_PENDING][$bucket]['avg'])->toBe(isset($expected[$bucket]) ? 5.0 : 0.0);
        }

        expect(array_sum(array_column($series[MetricsPublisher::GAUGE_CONCURRENT], 'avg')))->toBe((float) array_sum($expected))
            ->and(end($series[MetricsPublisher::GAUGE_LATENCY])['avg'])->toBe(100.0);

        // A host without a first sighting (written before the index existed)
        // is read from the window's start up to its last sighting, and reads
        // the same. The unbounded read asked every host for every bucket.
        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);
        $redis->execute('DEL', $prefix.'metrics:hosts:first');

        // Same reader: the count covers its last call only, not its lifetime.
        expect($reader->gaugeSeriesMulti($metrics, MetricsPublisher::TIER_HOUR, $buckets, $now))->toBe($series)
            ->and($reader->fieldsRequested())->toBeGreaterThan($bound)
            ->and($reader->fieldsRequested())->toBeLessThan(count($metrics) * $buckets + (4 + 1) * $buckets * $hosts);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupGaugesAndJobs($publisher, $prefix);
        cleanupHosts($publisher, $prefix);
    }
});

/**
 * A publish tick records outcomes and then gauges for the same fleet, so the
 * index touch would run twice for one tick's worth of information. The guard
 * keys on the host set, not on the clock, so a host the first call did not
 * carry still reaches the index.
 */
it('touches the host index once for a tick that records both outcomes and gauges', function () {
    $prefix = 'torque-host-touch-test:';
    $publisher = createPublisher($prefix);
    $now = time();

    try {
        cleanupHosts($publisher, $prefix);

        $redis = (new ReflectionClass($publisher))
            ->getMethod('getRedis')
            ->invoke($publisher);

        $publisher->recordHostOutcomes(['web-01' => [1, 0]], $now);

        expect(array_keys($publisher->hostsSeen(0)))->toBe(['web-01']);

        // Drop the index, so a second ZADD would be visible as the key coming
        // back. Same fleet, same second: the recorder must not issue one.
        $redis->execute('DEL', $prefix.'metrics:hosts');

        $publisher->recordHostGauges([
            'web-01' => [MetricsPublisher::GAUGE_HOST_BUSY_SLOTS => 2],
        ], $now);

        expect($publisher->hostsSeen(0))->toBe([]);

        // A host the outcomes call never carried is a different set, so the
        // touch runs and both hosts land in the index.
        $publisher->recordHostGauges([
            'web-01' => [MetricsPublisher::GAUGE_HOST_BUSY_SLOTS => 2],
            'web-02' => [MetricsPublisher::GAUGE_HOST_BUSY_SLOTS => 5],
        ], $now);

        expect(array_keys($publisher->hostsSeen(0)))->toBe(['web-01', 'web-02']);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanupHosts($publisher, $prefix);
    }
});

it('makes a hostname safe to use inside a colon-delimited field', function () {
    // A colon would silently invent a field segment, and glob characters would
    // break the SCAN patterns housekeeping matches on.
    expect(MetricsPublisher::normaliseHost('web-01'))->toBe('web-01')
        ->and(MetricsPublisher::normaliseHost('web:01'))->not->toContain(':')
        ->and(MetricsPublisher::normaliseHost('web*01'))->not->toContain('*')
        // Two machines whose names clean to the same string stay distinct.
        ->and(MetricsPublisher::normaliseHost('web:01'))->not->toBe(MetricsPublisher::normaliseHost('web*01'))
        ->and(MetricsPublisher::normaliseHost(''))->toBe('unknown')
        ->and(MetricsPublisher::normaliseHost('   '))->toBe('unknown')
        ->and(strlen(MetricsPublisher::normaliseHost(str_repeat('h', 200))))->toBeLessThanOrEqual(56);
});

it('leaves an already normalised hostname unchanged', function (string $host) {
    // Index members are stored normalised and read back through the series
    // readers, which normalise again; a second pass must not mint a new name.
    $once = MetricsPublisher::normaliseHost($host);

    expect(MetricsPublisher::normaliseHost($once))->toBe($once);
})->with([
    'colon' => 'web:01',
    'space' => 'web 01',
    'tilde' => 'web~01',
    'too long' => str_repeat('h', 200),
    'clean' => 'web-01',
]);

it('prunes a shared hash once per bucket however many masters write it', function () {
    $prefix = 'torque-prune-lock-test:';
    $first = createPublisher($prefix);
    $second = createPublisher($prefix);
    $now = time();
    $bucket = intdiv($now, 60) * 60;
    $stale = (string) ($bucket - 3 * 86400);

    try {
        cleanupRollups($first, $prefix);

        $redis = (new ReflectionClass($first))->getMethod('getRedis')->invoke($first);

        $first->recordOutcomes(1, 0, [], $now);
        $redis->execute('HSET', $prefix.'metrics:rollup:minute', $stale, '5,0');

        // A second master in the same bucket finds the bucket already pruned.
        $second->recordOutcomes(1, 0, [], $now);

        expect($redis->execute('HEXISTS', $prefix.'metrics:rollup:minute', $stale))->toBe(1);

        // The next bucket is pruned again, by whichever master gets there first.
        $second->recordOutcomes(1, 0, [], $bucket + 60);

        expect($redis->execute('HEXISTS', $prefix.'metrics:rollup:minute', $stale))->toBe(0)
            ->and($first->series('minute', 2, null, $bucket + 60))
            ->toBe([$bucket => ['processed' => 2, 'failed' => 0], $bucket + 60 => ['processed' => 1, 'failed' => 0]]);

        cleanupRollups($first, $prefix);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('keeps one aggregate per host and sums them on read', function () {
    $prefix = 'torque-live-test:';
    $publisher = createPublisher($prefix);

    try {
        cleanupRedisKeys($publisher, $prefix, []);

        $publisher->publishAggregate(['throughput' => 1.25, 'concurrent' => 3, 'total_slots' => 20, 'avg_latency' => 10.0, 'jobs_processed' => 30, 'jobs_failed' => 1, 'memory_mb' => 64.5, 'workers' => 2], 'web-01');
        $publisher->publishAggregate(['throughput' => 0.75, 'concurrent' => 1, 'total_slots' => 10, 'avg_latency' => 40.0, 'jobs_processed' => 10, 'jobs_failed' => 0, 'memory_mb' => 32.0, 'workers' => 1], 'web-02');

        $redis = (new ReflectionClass($publisher))->getMethod('getRedis')->invoke($publisher);

        expect($redis->execute('EXISTS', $prefix.'metrics'))->toBe(0)
            ->and((int) $redis->execute('TTL', $prefix.'metrics:live:web-01'))->toBeGreaterThan(0)->toBeLessThanOrEqual(30)
            ->and($redis->execute('HGET', $prefix.'metrics:live:web-02', 'workers'))->toBe('1');

        $aggregate = $publisher->getAggregatedMetrics();

        expect($aggregate['throughput'])->toBe('2')
            ->and($aggregate['concurrent'])->toBe('4')
            ->and($aggregate['total_slots'])->toBe('30')
            ->and($aggregate['jobs_processed'])->toBe('40')
            ->and($aggregate['jobs_failed'])->toBe('1')
            ->and($aggregate['memory_mb'])->toBe('96.5')
            ->and($aggregate['workers'])->toBe('3')
            // (30 x 10 + 10 x 40) / 40 processed jobs.
            ->and($aggregate['avg_latency'])->toBe('17.5');

        cleanupRedisKeys($publisher, $prefix, []);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('times out a read on a socket that never answers', function () {
    // Accepted by the kernel's backlog and never read: the command goes out
    // and no reply ever comes, as on a half-open socket.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = (string) stream_socket_get_name($server, false);

    try {
        $publisher = new MetricsPublisher(redisUri: 'redis://'.$address, readTimeout: 0.3);
        $started = microtime(true);

        expect(fn () => $publisher->getAggregatedMetrics())
            ->toThrow(RedisTimeoutException::class)
            ->and(microtime(true) - $started)->toBeLessThan(5.0);
    } finally {
        fclose($server);
    }
});
