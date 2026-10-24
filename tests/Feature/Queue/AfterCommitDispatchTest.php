<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Webpatser\Torque\Queue\StreamQueue;

/*
|--------------------------------------------------------------------------
| afterCommit dispatch inside an open transaction
|--------------------------------------------------------------------------
|
| Laravel's Queue::enqueueUsing() defers an afterCommit job to a transaction
| callback and returns null while a transaction is open. push() and later()
| must accept that null instead of throwing a TypeError, enqueue nothing
| before the commit, enqueue on commit and drop the job on rollback.
|
*/

final class TorqueAfterCommitJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(): void {}
}

final class TorqueRegularJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}

beforeEach(function () {
    $this->redis = torqueRedis();

    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $this->queueName = 'after-commit-'.bin2hex(random_bytes(4));

    /** @var StreamQueue $queue */
    $queue = app('queue')->connection('torque');
    $this->streamQueue = $queue;
});

afterEach(function () {
    if (isset($this->streamQueue, $this->redis)) {
        $key = $this->streamQueue->getStreamKey($this->queueName);
        $this->redis->execute('DEL', $key, $key.':delayed');
    }
});

it('defers an afterCommit push until the transaction commits', function () {
    $queue = $this->streamQueue;
    $returned = 'unset';

    DB::transaction(function () use ($queue, &$returned) {
        $returned = $queue->push(new TorqueAfterCommitJob, '', $this->queueName);

        expect($queue->size($this->queueName))->toBe(0);
    });

    expect($returned)->toBeNull()
        ->and($queue->size($this->queueName))->toBe(1);
});

it('defers an afterCommit later until the transaction commits', function () {
    $queue = $this->streamQueue;
    $returned = 'unset';

    DB::transaction(function () use ($queue, &$returned) {
        $returned = $queue->later(60, new TorqueAfterCommitJob, '', $this->queueName);

        expect($queue->delayedSize($this->queueName))->toBe(0);
    });

    expect($returned)->toBeNull()
        ->and($queue->delayedSize($this->queueName))->toBe(1);
});

it('drops a deferred afterCommit push and later on rollback', function () {
    $queue = $this->streamQueue;

    try {
        DB::transaction(function () use ($queue) {
            $queue->push(new TorqueAfterCommitJob, '', $this->queueName);
            $queue->later(60, new TorqueAfterCommitJob, '', $this->queueName);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // Expected: the transaction rolls back.
    }

    expect($queue->size($this->queueName))->toBe(0)
        ->and($queue->delayedSize($this->queueName))->toBe(0);
});

it('enqueues an afterCommit job at once outside a transaction', function () {
    $queue = $this->streamQueue;

    // The transactions manager runs the callback immediately but discards
    // its result, so the framework contract returns null here as well.
    expect($queue->push(new TorqueAfterCommitJob, '', $this->queueName))->toBeNull()
        ->and($queue->later(60, new TorqueAfterCommitJob, '', $this->queueName))->toBeNull()
        ->and($queue->size($this->queueName))->toBe(1)
        ->and($queue->delayedSize($this->queueName))->toBe(1);
});

it('still returns ids for a regular job', function () {
    $queue = $this->streamQueue;

    expect($queue->push(new TorqueRegularJob, '', $this->queueName))->toBeString()->not->toBeEmpty()
        ->and($queue->later(60, new TorqueRegularJob, '', $this->queueName))->toBeString()->not->toBeEmpty()
        ->and($queue->size($this->queueName))->toBe(1)
        ->and($queue->delayedSize($this->queueName))->toBe(1);
});
