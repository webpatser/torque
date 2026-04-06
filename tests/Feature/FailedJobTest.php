<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;

it('increments attempts on release', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    try {
        // Use a unique queue to avoid interference from other tests.
        $testQueue = 'retry-test-' . bin2hex(random_bytes(4));

        $payload = json_encode([
            'uuid' => 'retry-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]);

        $messageId = $queue->pushRaw($payload, $testQueue);

        $job = new StreamJob(
            container: app(),
            streamQueue: $queue,
            rawBody: $payload,
            messageId: $messageId,
            connectionName: 'torque',
            queue: $testQueue,
        );

        expect($job->attempts())->toBe(1); // 0 + 1

        // Release the job (re-enqueues with attempts incremented).
        $job->release(0);

        // Pop the re-enqueued job from the same queue.
        $reEnqueued = $queue->pop($testQueue);

        expect($reEnqueued)->not->toBeNull();
        expect($reEnqueued->attempts())->toBe(2); // 1 + 1

        $reEnqueued->delete();
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('can acknowledge and delete a job', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    try {
        $testQueue = 'delete-test-' . bin2hex(random_bytes(4));

        $messageId = $queue->pushRaw(json_encode([
            'uuid' => 'delete-test-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 0,
        ]), $testQueue);

        $sizeBefore = $queue->size($testQueue);

        $queue->deleteAndAcknowledge($testQueue, $messageId);

        $sizeAfter = $queue->size($testQueue);
        expect($sizeAfter)->toBeLessThan($sizeBefore);
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
