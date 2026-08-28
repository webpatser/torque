<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;

/*
 * `MasterProcess::handleHousekeepingTick()` is the maintenance half-step that
 * finally gives `dead_letter.ttl` an owner in production: the master prunes
 * on its first tick after start (so a restart after an incident cleans up
 * immediately) and then every `dead_letter.prune_interval` seconds.
 *
 * Cadence is observed through real Redis: a stale dead-letter entry survives
 * a tick that is not due yet and disappears on one that is.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-hk-master-'.bin2hex(random_bytes(4)).':';
    $this->deadLetterKey = $this->prefix.'dead-letter';

    $this->master = function (int $interval): MasterProcess {
        return new MasterProcess([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
            'consumer_group' => 'torque-test',
            'streams' => ['default' => []],
            'dead_letter' => ['ttl' => 60, 'prune_interval' => $interval, 'max_entries' => 0],
        ], fn () => null);
    };

    $this->addStaleEntry = function (): void {
        $this->redis->execute('XADD', $this->deadLetterKey, ((time() - 3600) * 1000).'-0', 'payload', 'stale');
    };
});

afterEach(function () {
    $this->redis->execute('DEL', $this->deadLetterKey);
});

function masterHousekeepingDue(MasterProcess $master): ?int
{
    return (new ReflectionProperty($master, 'housekeepingDueAt'))->getValue($master);
}

it('prunes on the first tick and schedules the next run', function () {
    ($this->addStaleEntry)();
    $master = ($this->master)(300);

    expect(masterHousekeepingDue($master))->toBeNull();

    $master->handleHousekeepingTick();

    expect((int) $this->redis->execute('XLEN', $this->deadLetterKey))->toBe(0)
        ->and(masterHousekeepingDue($master))->toBeGreaterThanOrEqual(time() + 299);
});

it('does nothing again until the interval has elapsed', function () {
    $master = ($this->master)(300);
    $master->handleHousekeepingTick();

    $due = masterHousekeepingDue($master);
    ($this->addStaleEntry)();

    $master->handleHousekeepingTick();

    expect((int) $this->redis->execute('XLEN', $this->deadLetterKey))->toBe(1)
        ->and(masterHousekeepingDue($master))->toBe($due);
});

it('prunes again once the interval has elapsed', function () {
    $master = ($this->master)(300);
    $master->handleHousekeepingTick();

    ($this->addStaleEntry)();
    (new ReflectionProperty($master, 'housekeepingDueAt'))->setValue($master, time() - 1);

    $master->handleHousekeepingTick();

    expect((int) $this->redis->execute('XLEN', $this->deadLetterKey))->toBe(0);
});

it('is disabled entirely by a zero interval', function () {
    ($this->addStaleEntry)();
    $master = ($this->master)(0);

    $master->handleHousekeepingTick();

    expect((int) $this->redis->execute('XLEN', $this->deadLetterKey))->toBe(1)
        ->and(masterHousekeepingDue($master))->toBeNull();
});

it('survives an unreachable Redis and re-arms the timer', function () {
    $logged = [];

    $master = new MasterProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6399', 'prefix' => 'torque-test-hk-down:'],
        'streams' => ['default' => []],
        'dead_letter' => ['ttl' => 60, 'prune_interval' => 300],
    ], function (string $message) use (&$logged) {
        $logged[] = $message;
    });

    $master->handleHousekeepingTick();

    expect($logged)->toHaveCount(1)
        ->and($logged[0])->toContain('Housekeeping failed')
        ->and(masterHousekeepingDue($master))->not->toBeNull();
});
