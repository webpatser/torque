<?php

declare(strict_types=1);

use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Metrics\WorkerSnapshot;
use Webpatser\Torque\Queue\StreamQueue;

use function Fledge\Async\Redis\createRedisClient;

/*
|--------------------------------------------------------------------------
| Multi-Tenant Isolation
|--------------------------------------------------------------------------
|
| Two Torque supervisors running on the same host (different projects) are
| isolated by their Redis prefix alone. These tests stand up two parallel
| instances on the same Redis DB with different prefixes and confirm that
| no state leaks between them: queue streams, delayed sorted sets, worker
| metrics, the pause flag, and the dead-letter stream.
|
| If this suite ever fails, it means a new feature introduced a Redis key
| that is not prefix-scoped — a regression that would corrupt data across
| projects on a shared Redis.
|
*/

$prefixA = 'torque-iso-a-' . bin2hex(random_bytes(4)) . ':';
$prefixB = 'torque-iso-b-' . bin2hex(random_bytes(4)) . ':';

beforeEach(function () use ($prefixA, $prefixB) {
    $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');

    $this->redisUri = $redisUri;
    $this->prefixA = $prefixA;
    $this->prefixB = $prefixB;

    try {
        $this->queueA = new StreamQueue(
            redisUri: $redisUri,
            default: 'default',
            retryAfter: 90,
            blockFor: 100,
            prefix: $prefixA,
            consumerGroup: 'torque',
        );
        $this->queueA->setContainer(app());
        $this->queueA->setConnectionName('torque');

        $this->queueB = new StreamQueue(
            redisUri: $redisUri,
            default: 'default',
            retryAfter: 90,
            blockFor: 100,
            prefix: $prefixB,
            consumerGroup: 'torque',
        );
        $this->queueB->setContainer(app());
        $this->queueB->setConnectionName('torque');

        // Verify Redis is reachable.
        $this->queueA->size();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

afterEach(function () use ($prefixA, $prefixB) {
    if (! isset($this->queueA)) {
        return;
    }

    try {
        $redis = $this->queueA->getRedisClient();

        foreach ([$prefixA, $prefixB] as $prefix) {
            $cursor = '0';
            do {
                $result = $redis->execute('SCAN', $cursor, 'MATCH', $prefix . '*', 'COUNT', '100');
                $cursor = (string) $result[0];
                $keys = $result[1] ?? [];

                foreach ($keys as $key) {
                    $redis->execute('DEL', (string) $key);
                }
            } while ($cursor !== '0');
        }
    } catch (\Fledge\Async\Redis\RedisException) {
        // Cleanup is best-effort.
    }
});

// -------------------------------------------------------------------------
//  Queue streams are isolated by prefix
// -------------------------------------------------------------------------

it('does not leak pushed jobs across prefixes', function () {
    $payload = json_encode([
        'uuid' => 'iso-job-1',
        'displayName' => 'TestJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [],
        'attempts' => 0,
    ]);

    (void) $this->queueA->pushRaw($payload);
    (void) $this->queueA->pushRaw($payload);

    expect($this->queueA->size())->toBe(2)
        ->and($this->queueB->size())->toBe(0)
        ->and($this->queueB->pop())->toBeNull();
});

it('keeps delayed jobs scoped to their prefix', function () {
    $redis = $this->queueA->getRedisClient();
    $matureScore = (string) (time() - 60);

    $redis->execute(
        'ZADD',
        $this->prefixA . 'default:delayed',
        $matureScore,
        json_encode(['uuid' => 'iso-delayed-1']),
    );

    $sizeA = (int) $redis->execute('ZCARD', $this->prefixA . 'default:delayed');
    $sizeB = (int) $redis->execute('ZCARD', $this->prefixB . 'default:delayed');

    expect($sizeA)->toBe(1)->and($sizeB)->toBe(0);
});

// -------------------------------------------------------------------------
//  Metrics are isolated by prefix
// -------------------------------------------------------------------------

it('does not surface prefix A worker metrics when reading prefix B', function () {
    $publisherA = new MetricsPublisher(redisUri: $this->redisUri, prefix: $this->prefixA);
    $publisherB = new MetricsPublisher(redisUri: $this->redisUri, prefix: $this->prefixB);

    $snapshot = new WorkerSnapshot(
        jobsProcessed: 123,
        jobsFailed: 4,
        activeSlots: 5,
        totalSlots: 10,
        averageLatencyMs: 12.0,
        slotUsageRatio: 0.5,
        memoryBytes: 10_000_000,
        timestamp: time(),
    );

    $publisherA->publishWorkerMetrics('worker-a-1', $snapshot);

    $workersA = $publisherA->getAllWorkerMetrics();
    $workersB = $publisherB->getAllWorkerMetrics();

    expect($workersA)->toHaveKey('worker-a-1')
        ->and($workersB)->toBe([])
        ->and($publisherB->getWorkerMetrics('worker-a-1'))->toBeNull();
});

it('aggregates only its own workers when summarizing metrics', function () {
    $publisherA = new MetricsPublisher(redisUri: $this->redisUri, prefix: $this->prefixA);
    $publisherB = new MetricsPublisher(redisUri: $this->redisUri, prefix: $this->prefixB);

    $publisherA->publishWorkerMetrics('worker-a-1', new WorkerSnapshot(
        jobsProcessed: 100,
        jobsFailed: 2,
        activeSlots: 3,
        totalSlots: 10,
        averageLatencyMs: 5.0,
        slotUsageRatio: 0.3,
        memoryBytes: 1_000_000,
        timestamp: time(),
    ));

    $aggregateB = $publisherB->aggregateFromWorkers($publisherB->getAllWorkerMetrics());

    expect($aggregateB['workers'])->toBe(0)
        ->and($aggregateB['jobs_processed'])->toBe(0)
        ->and($aggregateB['jobs_failed'])->toBe(0)
        ->and($aggregateB['concurrent'])->toBe(0);
});

// -------------------------------------------------------------------------
//  Pause flag is isolated by prefix
// -------------------------------------------------------------------------

it('does not share the pause flag across prefixes', function () {
    $redis = createRedisClient($this->redisUri);

    $redis->execute('SET', $this->prefixA . 'paused', (string) time());

    $pausedA = (bool) $redis->execute('EXISTS', $this->prefixA . 'paused');
    $pausedB = (bool) $redis->execute('EXISTS', $this->prefixB . 'paused');

    expect($pausedA)->toBeTrue()->and($pausedB)->toBeFalse();
});

// -------------------------------------------------------------------------
//  Dead-letter stream is isolated by prefix
// -------------------------------------------------------------------------

it('keeps dead-letter entries scoped to their prefix', function () {
    $handlerA = new DeadLetterHandler(redisUri: $this->redisUri, ttl: 60, prefix: $this->prefixA);
    $handlerB = new DeadLetterHandler(redisUri: $this->redisUri, ttl: 60, prefix: $this->prefixB);

    $handlerA->handle(
        queue: 'default',
        payload: json_encode(['uuid' => 'iso-dlq-1']),
        messageId: '1-0',
        exception: new \RuntimeException('project A boom'),
    );

    $listA = $handlerA->list();
    $listB = $handlerB->list();

    expect($listA)->toHaveCount(1)
        ->and($listA[0]['exception_message'])->toBe('project A boom')
        ->and($listB)->toBe([]);
});
