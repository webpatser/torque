<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Worker\WorkerProcess;

/*
 * A tripped breaker does not get its own pause mechanism: it is folded into
 * the same paused-queue set `queue:pause <name>` produces, which the reader
 * fibers already consult through eligibleQueues() and shouldFullyPause().
 * This test drives that exact path with a real, really-open breaker.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-cbpause-'.bin2hex(random_bytes(4)).':';

    $this->breaker = new CircuitBreaker(
        redisUri: torqueRedisUri(),
        prefix: $this->prefix,
        config: ['enabled' => true, 'min_samples' => 2, 'threshold' => 0.9, 'cooldown' => 60],
        events: new Dispatcher,
        logger: fn () => null,
    );
});

afterEach(function () {
    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('merges framework pauses and open breakers into one set', function () {
    expect(WorkerProcess::mergePausedQueues(['reports'], ['exports']))->toBe(['reports', 'exports'])
        ->and(WorkerProcess::mergePausedQueues(['reports'], ['reports']))->toBe(['reports'])
        ->and(WorkerProcess::mergePausedQueues([], []))->toBe([]);
});

it('stops polling only the stream whose breaker is open', function () {
    $queues = ['reports', 'default'];

    $this->breaker->recordFailure('reports');
    $this->breaker->recordFailure('reports');

    $paused = WorkerProcess::mergePausedQueues([], $this->breaker->openQueues($queues));

    expect($paused)->toBe(['reports'])
        ->and(WorkerProcess::eligibleQueues($queues, [], [], $paused))->toBe(['default'])
        ->and(WorkerProcess::shouldFullyPause(false, $paused, $queues))->toBeFalse();
});

it('fully pauses a worker that serves nothing but the tripped stream', function () {
    $this->breaker->recordFailure('reports');
    $this->breaker->recordFailure('reports');

    $paused = WorkerProcess::mergePausedQueues([], $this->breaker->openQueues(['reports']));

    expect(WorkerProcess::shouldFullyPause(false, $paused, ['reports']))->toBeTrue()
        ->and(WorkerProcess::eligibleQueues(['reports'], [], [], $paused))->toBe([]);
});

it('resumes polling as soon as the breaker is closed', function () {
    $queues = ['reports'];

    $this->breaker->recordFailure('reports');
    $this->breaker->recordFailure('reports');
    $this->breaker->forceClose('reports');

    $paused = WorkerProcess::mergePausedQueues([], $this->breaker->openQueues($queues));

    expect($paused)->toBe([])
        ->and(WorkerProcess::eligibleQueues($queues, [], [], $paused))->toBe(['reports']);
});
