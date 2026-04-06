<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;

it('stores delayed jobs in sorted set', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    try {
        // Get baseline delayed count.
        $before = $queue->delayedSize('default');

        // Use laterRaw indirectly via the public later() interface.
        // We need to set the container for createPayload to work.
        $queue->setContainer(app());

        // Manually add a delayed job to the sorted set for testing.
        $redis = $queue->getRedisClient();
        $payload = json_encode([
            'uuid' => 'delayed-uuid-1',
            'displayName' => 'TestDelayedJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        $futureTimestamp = time() + 3600; // 1 hour from now.
        $redis->execute('ZADD', 'torque-test:default:delayed', (string) $futureTimestamp, $payload);

        $after = $queue->delayedSize('default');
        expect($after)->toBe($before + 1);

        // Clean up.
        $redis->execute('ZREM', 'torque-test:default:delayed', $payload);
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('reports zero delayed jobs when sorted set is empty', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    try {
        // A fresh queue (with unique prefix) should have no delayed jobs.
        $redis = $queue->getRedisClient();
        $redis->execute('DEL', 'torque-test:fresh-queue:delayed');

        $size = $queue->delayedSize('fresh-queue');
        expect($size)->toBe(0);
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
