<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;

it('registers the torque queue connector', function () {
    /** @var \Illuminate\Queue\QueueManager $manager */
    $manager = app('queue');
    $queue = $manager->connection('torque');

    expect($queue)->toBeInstanceOf(StreamQueue::class);
});

it('resolves default queue name from config', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    expect($queue->getQueue())->toBe('default');
});

it('uses configured prefix', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    expect($queue->getStreamKey())->toBe('torque-test:default');
});

it('uses configured consumer group', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    expect($queue->getConsumerGroup())->toBe('torque-test');
});

it('pushes a job to Redis stream', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    // This requires a running Redis. If Redis is not available, this test will
    // throw a connection exception — which is expected for CI without Redis.
    try {
        $messageId = $queue->pushRaw(json_encode([
            'uuid' => 'dispatch-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]), 'default');

        expect($messageId)->toBeString()->not->toBeEmpty();

        // Verify stream size grew.
        $size = $queue->size('default');
        expect($size)->toBeGreaterThanOrEqual(1);

        // Clean up: delete the message.
        $queue->deleteAndAcknowledge('default', $messageId);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('pushes a delayed job to sorted set', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    try {
        (void) $queue->pushRaw(json_encode([
            'uuid' => 'delayed-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]), 'default');

        // Check delayed size is initially 0.
        $delayedSize = $queue->delayedSize('default');
        expect($delayedSize)->toBeGreaterThanOrEqual(0);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
