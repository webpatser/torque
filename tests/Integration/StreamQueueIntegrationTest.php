<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;

/*
|--------------------------------------------------------------------------
| StreamQueue Integration Tests
|--------------------------------------------------------------------------
|
| These tests require a running Redis instance. Each test run uses a unique
| key prefix to avoid collisions. All keys are cleaned up in afterEach.
|
*/

$testPrefix = 'torque-test-' . bin2hex(random_bytes(4)) . ':';

beforeEach(function () use ($testPrefix) {
    $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');

    $this->testPrefix = $testPrefix;

    try {
        $this->streamQueue = new StreamQueue(
            redisUri: $redisUri,
            default: 'default',
            retryAfter: 90,
            blockFor: 100,
            prefix: $testPrefix,
            consumerGroup: 'test-group',
        );

        // Set the container and connection name so StreamJob can be constructed.
        $this->streamQueue->setContainer(app());
        $this->streamQueue->setConnectionName('torque');

        // Verify Redis is reachable.
        $this->streamQueue->size();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

afterEach(function () use ($testPrefix) {
    if (! isset($this->streamQueue)) {
        return;
    }

    try {
        $redis = $this->streamQueue->getRedisClient();

        // Scan for all keys matching our test prefix and delete them.
        $cursor = '0';
        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $testPrefix . '*', 'COUNT', '100');
            $cursor = (string) $result[0];
            $keys = $result[1] ?? [];

            foreach ($keys as $key) {
                $redis->execute('DEL', (string) $key);
            }
        } while ($cursor !== '0');
    } catch (\Fledge\Async\Redis\RedisException) {
        // Cleanup best-effort; don't fail the test on cleanup issues.
    }
});

// -------------------------------------------------------------------------
//  1. pushRaw and size
// -------------------------------------------------------------------------

it('tracks size correctly after pushing multiple messages', function () {
    try {
        (void) $this->streamQueue->pushRaw(json_encode(['uuid' => 'msg-1', 'attempts' => 0]));
        (void) $this->streamQueue->pushRaw(json_encode(['uuid' => 'msg-2', 'attempts' => 0]));
        (void) $this->streamQueue->pushRaw(json_encode(['uuid' => 'msg-3', 'attempts' => 0]));

        expect($this->streamQueue->size())->toBe(3);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  2. ensureConsumerGroup
// -------------------------------------------------------------------------

it('creates a consumer group idempotently', function () {
    try {
        $streamKey = $this->streamQueue->getStreamKey();

        // First call creates the group.
        $this->streamQueue->ensureConsumerGroup($streamKey, 'idempotent-group');

        // Second call should not throw.
        $this->streamQueue->ensureConsumerGroup($streamKey, 'idempotent-group');

        expect(true)->toBeTrue();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  3. pop returns a StreamJob with correct payload and messageId
// -------------------------------------------------------------------------

it('pops a job with correct payload and messageId', function () {
    try {
        $payload = json_encode([
            'uuid' => 'pop-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        $messageId = $this->streamQueue->pushRaw($payload);

        $job = $this->streamQueue->pop();

        expect($job)->toBeInstanceOf(StreamJob::class);
        expect($job->getMessageId())->toBe($messageId);
        expect($job->getRawBody())->toBe($payload);
        expect($job->getJobId())->toBe('pop-test-1');
        expect($job->attempts())->toBe(1);

        $job->delete();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  4. pop returns null on empty queue
// -------------------------------------------------------------------------

it('returns null when popping from an empty queue', function () {
    try {
        $job = $this->streamQueue->pop();

        expect($job)->toBeNull();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  5. deleteAndAcknowledge
// -------------------------------------------------------------------------

it('decreases stream size after deleteAndAcknowledge', function () {
    try {
        $messageId = $this->streamQueue->pushRaw(json_encode([
            'uuid' => 'del-test-1',
            'attempts' => 0,
        ]));

        expect($this->streamQueue->size())->toBe(1);

        $this->streamQueue->deleteAndAcknowledge('default', $messageId);

        expect($this->streamQueue->size())->toBe(0);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  6. release with no delay
// -------------------------------------------------------------------------

it('re-enqueues a released job with incremented attempts', function () {
    try {
        $payload = json_encode([
            'uuid' => 'release-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        (void) $this->streamQueue->pushRaw($payload);
        $job = $this->streamQueue->pop();

        expect($job)->not->toBeNull();
        expect($job->attempts())->toBe(1);

        // Release with no delay re-enqueues immediately.
        $job->release(0);

        $reEnqueued = $this->streamQueue->pop();

        expect($reEnqueued)->not->toBeNull();
        expect($reEnqueued->attempts())->toBe(2);

        $reEnqueued->delete();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  7. release with delay
// -------------------------------------------------------------------------

it('moves a released job to the delayed set when delay is specified', function () {
    try {
        $payload = json_encode([
            'uuid' => 'release-delay-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        (void) $this->streamQueue->pushRaw($payload);
        $job = $this->streamQueue->pop();

        expect($job)->not->toBeNull();

        $delayedBefore = $this->streamQueue->delayedSize();

        $job->release(3600);

        $delayedAfter = $this->streamQueue->delayedSize();

        expect($delayedAfter)->toBe($delayedBefore + 1);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  8. later / delayedSize
// -------------------------------------------------------------------------

it('pushes a delayed job and increases delayedSize', function () {
    try {
        $delayedBefore = $this->streamQueue->delayedSize();

        $payload = json_encode([
            'uuid' => 'later-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        // laterRaw is private, so we replicate its behaviour via ZADD directly.
        $score = time() + 3600;
        $this->streamQueue->getRedisClient()->execute(
            'ZADD',
            $this->streamQueue->getStreamKey() . ':delayed',
            (string) $score,
            $payload,
        );

        $delayedAfter = $this->streamQueue->delayedSize();

        expect($delayedAfter)->toBe($delayedBefore + 1);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  9. pendingSize
// -------------------------------------------------------------------------

it('reports pending messages that are read but not acknowledged', function () {
    try {
        (void) $this->streamQueue->pushRaw(json_encode([
            'uuid' => 'pending-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]));

        // Pop but don't acknowledge or delete.
        $job = $this->streamQueue->pop();
        expect($job)->not->toBeNull();

        $pending = $this->streamQueue->pendingSize();
        expect($pending)->toBe(1);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  10. reservedSize equals pendingSize
// -------------------------------------------------------------------------

it('returns the same value for reservedSize and pendingSize', function () {
    try {
        (void) $this->streamQueue->pushRaw(json_encode([
            'uuid' => 'reserved-test-1',
            'attempts' => 0,
        ]));
        (void) $this->streamQueue->pushRaw(json_encode([
            'uuid' => 'reserved-test-2',
            'attempts' => 0,
        ]));

        // Pop both but don't acknowledge.
        $this->streamQueue->pop();
        $this->streamQueue->pop();

        $pending = $this->streamQueue->pendingSize();
        $reserved = $this->streamQueue->reservedSize();

        expect($reserved)->toBe($pending);
        expect($reserved)->toBe(2);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  11. creationTimeOfOldestPendingJob
// -------------------------------------------------------------------------

it('returns the creation timestamp of the oldest pending job', function () {
    try {
        $before = microtime(true);

        (void) $this->streamQueue->pushRaw(json_encode([
            'uuid' => 'oldest-pending-1',
            'attempts' => 0,
        ]));

        // Pop but don't acknowledge — now it's pending.
        $job = $this->streamQueue->pop();
        expect($job)->not->toBeNull();

        $timestamp = $this->streamQueue->creationTimeOfOldestPendingJob();

        expect($timestamp)->not->toBeNull();
        expect($timestamp)->toBeGreaterThanOrEqual($before - 1);
        expect($timestamp)->toBeLessThanOrEqual(microtime(true) + 1);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  12. creationTimeOfOldestPendingJob returns null when no pending
// -------------------------------------------------------------------------

it('returns null for oldest pending job when no messages are pending', function () {
    try {
        // Ensure the consumer group exists on an empty stream.
        $this->streamQueue->ensureConsumerGroup(
            $this->streamQueue->getStreamKey(),
            $this->streamQueue->getConsumerGroup(),
        );

        $timestamp = $this->streamQueue->creationTimeOfOldestPendingJob();

        expect($timestamp)->toBeNull();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  13. multiple queues are independent
// -------------------------------------------------------------------------

it('maintains independent sizes across different queue names', function () {
    try {
        (void) $this->streamQueue->pushRaw(json_encode(['uuid' => 'q1-1', 'attempts' => 0]), 'queue-alpha');
        (void) $this->streamQueue->pushRaw(json_encode(['uuid' => 'q1-2', 'attempts' => 0]), 'queue-alpha');

        (void) $this->streamQueue->pushRaw(json_encode(['uuid' => 'q2-1', 'attempts' => 0]), 'queue-beta');

        expect($this->streamQueue->size('queue-alpha'))->toBe(2);
        expect($this->streamQueue->size('queue-beta'))->toBe(1);
        expect($this->streamQueue->size('queue-gamma'))->toBe(0);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  14. consumer group isolation
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
//  15. Cluster-safe push and pop
// -------------------------------------------------------------------------

it('pushes and pops with cluster-safe keys', function () {
    try {
        $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');

        $clusterQueue = new StreamQueue(
            redisUri: $redisUri,
            default: 'default',
            retryAfter: 90,
            blockFor: 100,
            prefix: $this->testPrefix,
            consumerGroup: 'test-cluster-group',
            cluster: true,
        );
        $clusterQueue->setContainer(app());
        $clusterQueue->setConnectionName('torque');

        $payload = json_encode([
            'uuid' => 'cluster-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        $messageId = $clusterQueue->pushRaw($payload);
        expect($messageId)->toBeString()->not->toBeEmpty();

        // Stream key should contain hash tag.
        expect($clusterQueue->getStreamKey())->toContain('{default}');

        $job = $clusterQueue->pop();
        expect($job)->not->toBeNull();
        expect($job->getRawBody())->toBe($payload);
        $job->delete();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  16. Cluster-safe delayed keys
// -------------------------------------------------------------------------

it('stores delayed jobs with cluster-safe keys', function () {
    try {
        $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');

        $clusterQueue = new StreamQueue(
            redisUri: $redisUri,
            default: 'default',
            retryAfter: 90,
            blockFor: 100,
            prefix: $this->testPrefix,
            consumerGroup: 'test-cluster-delayed',
            cluster: true,
        );

        $delayedKey = $clusterQueue->getStreamKey() . ':delayed';
        expect($delayedKey)->toContain('{default}:delayed');

        $redis = $clusterQueue->getRedisClient();
        $redis->execute('ZADD', $delayedKey, (string) (time() + 3600), '{"uuid":"cluster-delayed-1"}');

        expect($clusterQueue->delayedSize())->toBe(1);

        $redis->execute('DEL', $delayedKey);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  17. Non-blocking XREADGROUP returns null on empty stream
// -------------------------------------------------------------------------

it('returns null immediately with non-blocking XREADGROUP on empty stream', function () {
    try {
        $streamKey = $this->streamQueue->getStreamKey('nonblock-test');
        $this->streamQueue->ensureConsumerGroup($streamKey, 'test-group');

        $redis = $this->streamQueue->getRedisClient();
        $start = microtime(true);

        $result = $redis->execute(
            'XREADGROUP', 'GROUP', 'test-group', 'test-consumer',
            'COUNT', '1', 'STREAMS', $streamKey, '>',
        );

        $elapsed = microtime(true) - $start;

        expect($result)->toBeNull();
        expect($elapsed)->toBeLessThan(0.5);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  18. Non-blocking XREADGROUP returns message when available
// -------------------------------------------------------------------------

it('returns message with non-blocking XREADGROUP when message exists', function () {
    try {
        $streamKey = $this->streamQueue->getStreamKey('nonblock-msg');
        $redis = $this->streamQueue->getRedisClient();

        $this->streamQueue->ensureConsumerGroup($streamKey, 'test-group');

        $redis->execute('XADD', $streamKey, '*', 'payload', '{"uuid":"nb-1"}');

        $result = $redis->execute(
            'XREADGROUP', 'GROUP', 'test-group', 'test-consumer',
            'COUNT', '1', 'STREAMS', $streamKey, '>',
        );

        expect($result)->not->toBeNull();
        expect($result)->toBeArray();

        $streamData = $result[0];
        $messages = $streamData[1];
        expect($messages)->toHaveCount(1);

        $fields = $messages[0][1];
        $payload = null;
        for ($i = 0, $c = count($fields); $i < $c; $i += 2) {
            if ((string) $fields[$i] === 'payload') {
                $payload = (string) $fields[$i + 1];
            }
        }
        expect($payload)->toBe('{"uuid":"nb-1"}');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  19. Delayed job migration from sorted set to stream
// -------------------------------------------------------------------------

it('migrates matured delayed jobs from sorted set to stream', function () {
    try {
        $streamKey = $this->streamQueue->getStreamKey();
        $delayedKey = $streamKey . ':delayed';
        $redis = $this->streamQueue->getRedisClient();

        $payload = json_encode([
            'uuid' => 'migrate-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        // Add a matured delayed job (score in the past).
        $redis->execute('ZADD', $delayedKey, (string) (time() - 10), $payload);
        expect($this->streamQueue->delayedSize())->toBe(1);

        // Simulate migration: ZRANGEBYSCORE + ZREM + XADD.
        $now = (string) time();
        $entries = $redis->execute('ZRANGEBYSCORE', $delayedKey, '-inf', $now, 'LIMIT', '0', '100');

        expect($entries)->toBeArray()->toHaveCount(1);

        foreach ($entries as $entry) {
            $redis->execute('ZREM', $delayedKey, $entry);
            $redis->execute('XADD', $streamKey, '*', 'payload', $entry);
        }

        // Delayed set should be empty, stream should have the job.
        expect($this->streamQueue->delayedSize())->toBe(0);
        expect($this->streamQueue->size())->toBe(1);

        // Pop and verify the migrated payload.
        $job = $this->streamQueue->pop();
        expect($job)->not->toBeNull();
        expect($job->getRawBody())->toBe($payload);
        $job->delete();
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  20. Consumer group isolation
// -------------------------------------------------------------------------

it('allows different consumer groups to independently read the same stream', function () {
    try {
        $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');

        // Create a second StreamQueue with a different consumer group but same prefix and queue.
        $secondQueue = new StreamQueue(
            redisUri: $redisUri,
            default: 'default',
            retryAfter: 90,
            blockFor: 100,
            prefix: $this->testPrefix,
            consumerGroup: 'test-group-two',
        );
        $secondQueue->setContainer(app());
        $secondQueue->setConnectionName('torque');

        // Push a message via the first queue.
        $payload = json_encode([
            'uuid' => 'isolation-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        $messageId = $this->streamQueue->pushRaw($payload);

        // Both consumer groups should be able to read the same message.
        $jobFromFirst = $this->streamQueue->pop();
        $jobFromSecond = $secondQueue->pop();

        expect($jobFromFirst)->not->toBeNull();
        expect($jobFromSecond)->not->toBeNull();

        expect($jobFromFirst->getMessageId())->toBe($messageId);
        expect($jobFromSecond->getMessageId())->toBe($messageId);

        // Each group has its own pending entry.
        expect($this->streamQueue->pendingSize())->toBe(1);
        expect($secondQueue->pendingSize())->toBe(1);

        // Delete from first group doesn't affect second group's pending.
        $jobFromFirst->delete();

        // The stream message is now deleted, but second group still has a pending entry.
        // After XDEL the pending entry remains in the PEL until acknowledged.
        expect($secondQueue->pendingSize())->toBe(1);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
