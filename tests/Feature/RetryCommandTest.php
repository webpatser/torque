<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Webpatser\Torque\Queue\StreamQueue;

/*
| `queue:retry` re-pushes a failed job with the framework's RetryCommand,
| which calls the queue's pushRaw() in statement position and discards the
| returned stream message id. On PHP 8.5 that discard must stay legal: the
| contract of Queue::pushRaw() is "return value is optional to the caller",
| so StreamQueue::pushRaw() must not be marked #[\NoDiscard].
*/

it('does not mark pushRaw as NoDiscard', function () {
    $attributes = (new ReflectionMethod(StreamQueue::class, 'pushRaw'))->getAttributes(NoDiscard::class);

    expect($attributes)->toBeEmpty();
});

it('queue:retry pushes a failed job back onto the stream', function () {
    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');
    $testQueue = 'retry-cmd-'.bin2hex(random_bytes(4));

    $failed = (object) [
        'id' => 'failed-1',
        'connection' => 'torque',
        'queue' => $testQueue,
        'payload' => json_encode([
            'uuid' => 'retry-cmd-1',
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
            'attempts' => 3,
        ]),
        'exception' => 'boom',
        'failed_at' => now()->toDateTimeString(),
    ];

    $forgotten = [];

    app()->instance('queue.failer', new class($failed, $forgotten)
    {
        public function __construct(private object $job, private array &$forgotten) {}

        public function find(string $id): ?object
        {
            return $id === $this->job->id ? $this->job : null;
        }

        public function forget(string $id): bool
        {
            $this->forgotten[] = $id;

            return true;
        }
    });

    try {
        $sizeBefore = $queue->size($testQueue);

        $this->artisan('queue:retry', ['id' => ['failed-1']])->assertSuccessful();

        expect($queue->size($testQueue))->toBe($sizeBefore + 1)
            ->and($forgotten)->toBe(['failed-1']);

        $job = $queue->pop($testQueue);
        expect($job)->not->toBeNull();
        $job->delete();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});
