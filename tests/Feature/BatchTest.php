<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;

it('dispatches multiple jobs for batch processing', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');

    try {
        $ids = [];

        // Simulate what Bus::batch() does — dispatches multiple jobs individually.
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $queue->pushRaw(json_encode([
                'uuid' => "batch-job-{$i}",
                'displayName' => 'App\\Jobs\\BatchableJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['batchId' => 'test-batch-1'],
                'attempts' => 0,
            ]), 'default');
        }

        expect($ids)->toHaveCount(3);
        expect($ids)->each->toBeString()->not->toBeEmpty();

        // Verify stream has at least 3 messages.
        $size = $queue->size('default');
        expect($size)->toBeGreaterThanOrEqual(3);

        // Clean up.
        foreach ($ids as $id) {
            $queue->deleteAndAcknowledge('default', $id);
        }
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
