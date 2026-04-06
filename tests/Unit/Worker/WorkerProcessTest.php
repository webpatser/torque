<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

it('constructor sets config without errors', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
        'queues' => ['default'],
        'coroutines_per_worker' => 10,
    ]);

    expect($worker)->toBeInstanceOf(WorkerProcess::class);
});

it('consumerId contains hostname and PID', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
    ]);

    // consumerId is a private(set) property, but we can use reflection to verify.
    $reflection = new ReflectionProperty(WorkerProcess::class, 'consumerId');
    $consumerId = $reflection->getValue($worker);

    expect($consumerId)->toContain(gethostname())
        ->and($consumerId)->toContain((string) getmypid());
});

it('shouldStop is false initially', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
    ]);

    expect($worker->shouldStop)->toBeFalse();
});

it('isRunning is false initially', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
    ]);

    expect($worker->isRunning)->toBeFalse();
});

it('jobsProcessed starts at zero', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
    ]);

    expect($worker->jobsProcessed)->toBe(0);
});

it('consumerId is unique across instances', function () {
    $workerA = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);
    $workerB = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);

    $reflection = new ReflectionProperty(WorkerProcess::class, 'consumerId');

    expect($reflection->getValue($workerA))
        ->not->toBe($reflection->getValue($workerB));
});
