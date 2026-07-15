<?php

declare(strict_types=1);

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

    // grace (2s) + 60s buffer; TTL counts down, so anything in (0, 62] is correct.
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(62);

    $redis->execute('DEL', 'torque-drain-test:paused');
});
