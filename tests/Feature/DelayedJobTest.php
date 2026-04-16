<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;

it('stores delayed jobs in sorted set', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    try {
        $redis = $queue->getRedisClient();
        $delayedKey = 'torque-test:default:delayed';

        // Clean up any leftover data from previous test runs.
        $redis->execute('DEL', $delayedKey);

        // Get baseline delayed count (should be 0 after cleanup).
        $before = $queue->delayedSize('default');

        $payload = json_encode([
            'uuid' => 'delayed-uuid-1',
            'displayName' => 'TestDelayedJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        $futureTimestamp = time() + 3600; // 1 hour from now.
        $redis->execute('ZADD', $delayedKey, (string) $futureTimestamp, $payload);

        $after = $queue->delayedSize('default');
        expect($after)->toBe($before + 1);

        // Clean up.
        $redis->execute('ZREM', $delayedKey, $payload);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('reports zero delayed jobs when sorted set is empty', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    try {
        $redis = $queue->getRedisClient();
        $redis->execute('DEL', 'torque-test:fresh-queue:delayed');

        $size = $queue->delayedSize('fresh-queue');
        expect($size)->toBe(0);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
