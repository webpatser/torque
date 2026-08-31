<?php

declare(strict_types=1);

use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Metrics\WorkerSnapshot;
use Webpatser\Torque\Process\MasterProcess;

/*
 * `MasterProcess::handleDrainTick()` is the half-step the monitor loop runs
 * each tick: if a SIGUSR2 came in, set the Redis paused key and start the
 * grace timer; if the grace has elapsed, SIGTERM workers and set
 * $shouldStop so the loop exits.
 *
 * The drain state is private; tests reach through ReflectionProperty rather
 * than entering the real monitor loop (which would block on
 * pcntl_sigtimedwait and spawn workers). We assert the contract:
 *
 *   - First tick after SIGUSR2 promotes drainRequested → draining + start time.
 *   - A tick before the grace expires leaves $shouldStop alone.
 *   - A tick after the grace expires sets $shouldStop.
 *   - A second drainRequested while already draining is a no-op.
 */

function makeMasterUnderTest(array $config = []): MasterProcess
{
    return new MasterProcess(
        array_merge([
            'redis' => [
                'uri' => 'redis://127.0.0.1:6379/15',
                'prefix' => 'torque-drain-test:',
            ],
            'drain_grace_seconds' => 2,
        ], $config),
        fn () => null,
    );
}

function setMasterPrivate(MasterProcess $m, string $name, mixed $value): void
{
    (new ReflectionProperty($m, $name))->setValue($m, $value);
}

function getMasterPrivate(MasterProcess $m, string $name): mixed
{
    return (new ReflectionProperty($m, $name))->getValue($m);
}

it('promotes drainRequested into an active drain on the next tick', function () {
    $master = makeMasterUnderTest();
    setMasterPrivate($master, 'drainRequested', true);

    $master->handleDrainTick();

    expect(getMasterPrivate($master, 'draining'))->toBeTrue()
        ->and(getMasterPrivate($master, 'drainStartedAt'))->not->toBeNull()
        ->and(getMasterPrivate($master, 'shouldStop'))->toBeFalse();
});

it('leaves shouldStop alone while the grace period is still running', function () {
    $master = makeMasterUnderTest(['drain_grace_seconds' => 5]);
    setMasterPrivate($master, 'draining', true);
    setMasterPrivate($master, 'drainStartedAt', microtime(true));

    $master->handleDrainTick();

    expect(getMasterPrivate($master, 'shouldStop'))->toBeFalse()
        ->and(getMasterPrivate($master, 'draining'))->toBeTrue();
});

it('sets shouldStop once the drain grace has elapsed', function () {
    $master = makeMasterUnderTest(['drain_grace_seconds' => 1]);
    setMasterPrivate($master, 'draining', true);
    setMasterPrivate($master, 'drainStartedAt', microtime(true) - 5);

    $master->handleDrainTick();

    expect(getMasterPrivate($master, 'shouldStop'))->toBeTrue()
        ->and(getMasterPrivate($master, 'draining'))->toBeFalse();
});

it('is a no-op when no drain has been requested', function () {
    $master = makeMasterUnderTest();

    $master->handleDrainTick();

    expect(getMasterPrivate($master, 'draining'))->toBeFalse()
        ->and(getMasterPrivate($master, 'drainStartedAt'))->toBeNull()
        ->and(getMasterPrivate($master, 'shouldStop'))->toBeFalse();
});

it('does not re-promote drainRequested while a drain is already in progress', function () {
    $master = makeMasterUnderTest();
    $startedAt = microtime(true) - 0.5;

    setMasterPrivate($master, 'draining', true);
    setMasterPrivate($master, 'drainStartedAt', $startedAt);
    setMasterPrivate($master, 'drainRequested', true);

    $master->handleDrainTick();

    // drainStartedAt must not be reset by the second request.
    expect(getMasterPrivate($master, 'drainStartedAt'))->toBe($startedAt);
});

it('sets the paused key with an expiry so a drained-away master cannot leave the queue paused forever', function () {
    $master = makeMasterUnderTest(['drain_grace_seconds' => 2]);
    setMasterPrivate($master, 'drainRequested', true);

    $master->handleDrainTick();

    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $ttl = (int) $redis->execute('TTL', 'torque-drain-test:paused');
    $value = (string) $redis->execute('GET', 'torque-drain-test:paused');

    // grace (2s) + 60s buffer; TTL counts down, so anything in (0, 62] is correct.
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(62)
        // The value scopes the pause to this master's own fleet: workers
        // compare the embedded PID against their parent and ignore a drain
        // that is not theirs (see WorkerProcess::shouldPauseFor()).
        ->and($value)->toBe('drain:'.getmypid());

    $redis->execute('DEL', 'torque-drain-test:paused');
});

/*
 * The grace is a ceiling, not a wait. Before this, a draining master kept
 * pickup paused for the whole drain_grace_seconds even with an idle fleet, so
 * on an installation sizing the grace for long jobs (7200s on scrpr) every
 * `torque:reload` parked the queue for two hours.
 */

it('reports the fleet drain complete when every worker is idle', function () {
    expect(MasterProcess::fleetDrainComplete([
        'host-1-aa' => ['active_slots' => '0', 'total_slots' => '4'],
        'host-2-bb' => ['active_slots' => '0', 'total_slots' => '4'],
    ]))->toBeTrue();
});

it('keeps draining while any worker still has a slot busy', function () {
    expect(MasterProcess::fleetDrainComplete([
        'host-1-aa' => ['active_slots' => '0', 'total_slots' => '4'],
        'host-2-bb' => ['active_slots' => '2', 'total_slots' => '4'],
    ]))->toBeFalse();
});

it('treats an unreadable fleet snapshot as still busy', function () {
    // Empty means the heartbeats could not be read, not that work is done.
    // Erring towards "busy" costs one grace window; erring the other way
    // SIGTERMs jobs that were still running.
    expect(MasterProcess::fleetDrainComplete([]))->toBeFalse();
});

it('counts a heartbeat without a slot count as idle', function () {
    // publishWorkerMetrics() has always written active_slots, so a row missing
    // it is a partial hash mid-write rather than a busy worker.
    expect(MasterProcess::fleetDrainComplete(['host-1-aa' => []]))->toBeTrue();
});

it('falls back to the grace timer when there is no metrics publisher', function () {
    $master = makeMasterUnderTest(['drain_grace_seconds' => 300]);
    setMasterPrivate($master, 'draining', true);
    setMasterPrivate($master, 'drainStartedAt', microtime(true));

    $master->handleDrainTick();

    expect(getMasterPrivate($master, 'shouldStop'))->toBeFalse()
        ->and(getMasterPrivate($master, 'draining'))->toBeTrue();
});

it('sees an idle fleet through the metrics publisher', function () {
    $prefix = 'torque-drain-idle-test:';
    $workerId = 'testhost-4242-abcdef01';

    $publisher = new MetricsPublisher(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: $prefix,
    );

    try {
        $publisher->publishWorkerMetrics($workerId, new WorkerSnapshot(
            jobsProcessed: 100,
            jobsFailed: 0,
            activeSlots: 0,
            totalSlots: 4,
            averageLatencyMs: 12.0,
            slotUsageRatio: 0.0,
            memoryBytes: 1024,
            timestamp: time(),
        ));
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }

    $master = makeMasterUnderTest([
        'redis' => ['uri' => 'redis://127.0.0.1:6379/15', 'prefix' => $prefix],
        'drain_grace_seconds' => 7200,
    ]);
    setMasterPrivate($master, 'metricsPublisher', $publisher);
    setMasterPrivate($master, 'workerPids', [4242 => true]);

    $idle = (new ReflectionMethod($master, 'fleetIsIdle'))->invoke($master);

    // Same fleet, one busy slot: the drain must keep waiting.
    $publisher->publishWorkerMetrics($workerId, new WorkerSnapshot(
        jobsProcessed: 100,
        jobsFailed: 0,
        activeSlots: 3,
        totalSlots: 4,
        averageLatencyMs: 12.0,
        slotUsageRatio: 0.75,
        memoryBytes: 1024,
        timestamp: time(),
    ));

    $busy = (new ReflectionMethod($master, 'fleetIsIdle'))->invoke($master);

    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $redis->execute('DEL', $prefix.'worker:'.$workerId);

    expect($idle)->toBeTrue()
        ->and($busy)->toBeFalse();
});

it('ignores heartbeats from workers that are not its own children', function () {
    $prefix = 'torque-drain-foreign-test:';
    $workerId = 'testhost-5151-beefcafe';

    $publisher = new MetricsPublisher(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: $prefix,
    );

    try {
        $publisher->publishWorkerMetrics($workerId, new WorkerSnapshot(
            jobsProcessed: 1,
            jobsFailed: 0,
            activeSlots: 0,
            totalSlots: 4,
            averageLatencyMs: 1.0,
            slotUsageRatio: 0.0,
            memoryBytes: 1024,
            timestamp: time(),
        ));
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }

    $master = makeMasterUnderTest([
        'redis' => ['uri' => 'redis://127.0.0.1:6379/15', 'prefix' => $prefix],
    ]);
    setMasterPrivate($master, 'metricsPublisher', $publisher);
    // A foreign fleet's idle worker must not read as our drain being done.
    setMasterPrivate($master, 'workerPids', [9999 => true]);

    $result = (new ReflectionMethod($master, 'fleetIsIdle'))->invoke($master);

    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $redis->execute('DEL', $prefix.'worker:'.$workerId);

    expect($result)->toBeFalse();
});
