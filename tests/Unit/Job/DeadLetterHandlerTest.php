<?php

declare(strict_types=1);

use Webpatser\Torque\Job\DeadLetterHandler;

it('stores failed jobs in dead letter stream', function () {
    try {
        $uniquePrefix = 'torque-test-dl-store-' . bin2hex(random_bytes(4)) . ':';
        $handler = new DeadLetterHandler(
            redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
            prefix: $uniquePrefix,
        );

        $handler->handle(
            queue: 'default',
            payload: '{"uuid":"dead-1","job":"TestJob"}',
            messageId: '1680000000000-0',
            exception: new RuntimeException('Test failure'),
        );

        $entries = $handler->list(count: 10);
        expect($entries)->not->toBeEmpty();

        $last = end($entries);
        expect($last['original_queue'])->toBe('default');
        expect($last['exception_class'])->toBe('RuntimeException');
        expect($last['exception_message'])->toBe('Test failure');

        // Clean up.
        $handler->purge($last['id']);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('retries a dead-lettered job', function () {
    try {
        $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
        $uniquePrefix = 'torque-test-dl-retry-' . bin2hex(random_bytes(4)) . ':';
        $handler = new DeadLetterHandler(
            redisUri: $redisUri,
            prefix: $uniquePrefix,
        );

        $handler->handle(
            queue: 'torque-test:retry-queue',
            payload: '{"uuid":"retry-1","job":"TestJob"}',
            messageId: '1680000000000-1',
            exception: new RuntimeException('Retry test'),
        );

        $entries = $handler->list(count: 10);
        $last = end($entries);

        $handler->retry($last['id']);

        // Entry should be removed from dead letter.
        $remaining = $handler->list(count: 100);
        $ids = array_column($remaining, 'id');
        expect($ids)->not->toContain($last['id']);

        // Clean up the retried job from the target stream.
        $redis = \Fledge\Async\Redis\createRedisClient($redisUri);
        $redis->execute('DEL', 'torque-test:retry-queue');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('purges a dead-lettered entry', function () {
    try {
        $uniquePrefix = 'torque-test-dl-purge-' . bin2hex(random_bytes(4)) . ':';
        $handler = new DeadLetterHandler(
            redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
            prefix: $uniquePrefix,
        );

        $handler->handle(
            queue: 'default',
            payload: '{"uuid":"purge-1"}',
            messageId: '1680000000000-2',
            exception: new RuntimeException('Purge test'),
        );

        $entries = $handler->list(count: 10);
        $last = end($entries);

        $handler->purge($last['id']);

        $remaining = $handler->list(count: 100);
        $ids = array_column($remaining, 'id');
        expect($ids)->not->toContain($last['id']);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns empty list when stream is empty', function () {
    try {
        $uniquePrefix = 'torque-test-empty-' . bin2hex(random_bytes(4)) . ':';
        $handler = new DeadLetterHandler(
            redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
            prefix: $uniquePrefix,
        );

        $entries = $handler->list();
        expect($entries)->toBe([]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('trim removes old entries based on TTL', function () {
    try {
        $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
        $uniquePrefix = 'torque-test-trim-' . bin2hex(random_bytes(4)) . ':';

        // Use a TTL of 1 second so entries become "old" quickly.
        $handler = new DeadLetterHandler(
            redisUri: $redisUri,
            ttl: 1,
            prefix: $uniquePrefix,
        );

        $handler->handle(
            queue: 'default',
            payload: '{"uuid":"trim-1"}',
            messageId: '1680000000000-0',
            exception: new RuntimeException('Trim test'),
        );

        $entries = $handler->list();
        expect($entries)->not->toBeEmpty();

        // Wait for the entry to exceed the 1-second TTL.
        sleep(2);

        $handler->trim();

        $remaining = $handler->list();
        expect($remaining)->toBe([]);

        // Clean up.
        $redis = \Fledge\Async\Redis\createRedisClient($redisUri);
        $redis->execute('DEL', $uniquePrefix . 'dead-letter');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('retry rejects invalid queue names', function () {
    try {
        $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
        $uniquePrefix = 'torque-test-dl-invalid-' . bin2hex(random_bytes(4)) . ':';

        $handler = new DeadLetterHandler(
            redisUri: $redisUri,
            prefix: $uniquePrefix,
        );

        $handler->handle(
            queue: 'default',
            payload: '{"uuid":"invalid-q-1"}',
            messageId: '1680000000000-0',
            exception: new RuntimeException('Invalid queue test'),
        );

        $entries = $handler->list();
        $last = end($entries);

        // Attempt to retry to a queue name with illegal characters.
        $handler->retry($last['id'], targetQueue: 'queue with spaces & symbols!');

        // Should not reach here.
        expect(false)->toBeTrue();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Invalid queue name');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('retry rejects queues not in the allowed whitelist', function () {
    try {
        $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
        $uniquePrefix = 'torque-test-dl-whitelist-' . bin2hex(random_bytes(4)) . ':';

        $handler = new DeadLetterHandler(
            redisUri: $redisUri,
            prefix: $uniquePrefix,
            allowedQueues: ['default', 'emails'],
        );

        $handler->handle(
            queue: 'default',
            payload: '{"uuid":"wl-1"}',
            messageId: '1680000000000-0',
            exception: new RuntimeException('Whitelist test'),
        );

        $entries = $handler->list();
        $last = end($entries);

        $handler->retry($last['id'], targetQueue: 'dead-letter');

        expect(false)->toBeTrue();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('is not a configured Torque stream');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('handle truncates exception messages to 1000 chars', function () {
    try {
        $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
        $uniquePrefix = 'torque-test-trunc-' . bin2hex(random_bytes(4)) . ':';

        $handler = new DeadLetterHandler(
            redisUri: $redisUri,
            prefix: $uniquePrefix,
        );

        $longMessage = str_repeat('X', 2000);

        $handler->handle(
            queue: 'default',
            payload: '{"uuid":"truncate-1"}',
            messageId: '1680000000000-0',
            exception: new RuntimeException($longMessage),
        );

        $entries = $handler->list();
        $last = end($entries);

        expect(strlen($last['exception_message']))->toBeLessThanOrEqual(1000);

        // Clean up.
        $handler->purge($last['id']);
        $redis = \Fledge\Async\Redis\createRedisClient($redisUri);
        $redis->execute('DEL', $uniquePrefix . 'dead-letter');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
