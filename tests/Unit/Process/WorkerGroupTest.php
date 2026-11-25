<?php

declare(strict_types=1);

use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Process\WorkerGroup;
use Webpatser\Torque\Support\NodeConfig;

/**
 * A group over a settable clock: `$clock->now` is the time the group reads.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: WorkerGroup, 1: stdClass}
 */
function clockedGroup(array $overrides = []): array
{
    $clock = new stdClass;
    $clock->now = 1_000.0;

    $group = WorkerGroup::fromConfig(array_replace([
        'name' => 'default',
        'queues' => ['default'],
        'workers' => 2,
        'slots_per_worker' => 50,
        'autoscale' => [],
    ], $overrides), 'redis://127.0.0.1:6379', static fn (): float => $clock->now);

    return [$group, $clock];
}

it('builds a group without an autoscaler when autoscale is off', function () {
    $group = WorkerGroup::fromConfig([
        'name' => 'mail',
        'queues' => ['emails', 'default'],
        'workers' => 3,
        'slots_per_worker' => 100,
        'autoscale' => ['enabled' => false, 'min_workers' => 1, 'max_workers' => 9],
    ], 'redis://127.0.0.1:6379');

    expect($group->name)->toBe('mail')
        ->and($group->queues)->toBe(['emails', 'default'])
        ->and($group->target)->toBe(3)
        ->and($group->slots)->toBe(100)
        ->and($group->autoScaler)->toBeNull()
        ->and($group->minWorkers)->toBe(3)
        ->and($group->maxWorkers)->toBe(3)
        ->and($group->pendingRespawns)->toBe(0);
});

it('builds its own autoscaler from the group autoscale block', function () {
    $group = WorkerGroup::fromConfig([
        'name' => 'imports',
        'queues' => ['imports'],
        'workers' => 2,
        'slots_per_worker' => 20,
        'autoscale' => [
            'enabled' => true,
            'min_workers' => 2,
            'max_workers' => 12,
            'scale_up_threshold' => 0.9,
            'scale_down_threshold' => 0.1,
            'cooldown' => 5,
        ],
    ], 'redis://127.0.0.1:6379');

    expect($group->autoScaler)->toBeInstanceOf(AutoScaler::class)
        ->and($group->autoScaler?->minWorkers)->toBe(2)
        ->and($group->autoScaler?->maxWorkers)->toBe(12)
        ->and($group->autoScaler?->scaleUpThreshold)->toBe(0.9)
        ->and($group->autoScaler?->scaleDownThreshold)->toBe(0.1)
        ->and($group->autoScaler?->cooldownSeconds)->toBe(5)
        ->and($group->target)->toBe(2)
        ->and($group->minWorkers)->toBe(2)
        ->and($group->maxWorkers)->toBe(12);
});

it('falls back to the master autoscale defaults for missing keys', function () {
    [$group] = clockedGroup(['autoscale' => ['enabled' => true]]);

    expect($group->autoScaler?->minWorkers)->toBe(2)
        ->and($group->autoScaler?->maxWorkers)->toBe(8)
        ->and($group->autoScaler?->scaleUpThreshold)->toBe(0.85)
        ->and($group->autoScaler?->scaleDownThreshold)->toBe(0.20)
        ->and($group->autoScaler?->cooldownSeconds)->toBe(30);
});

it('builds every group NodeConfig resolves, each with its own autoscaler', function () {
    $resolved = NodeConfig::resolve([
        'streams' => ['imports' => [], 'emails' => [], 'default' => []],
        'slots_per_worker' => 20,
        'autoscale' => ['enabled' => true],
        'nodes' => ['*' => ['groups' => [
            'imports' => ['queues' => ['imports'], 'workers' => 2, 'autoscale' => ['min_workers' => 2, 'max_workers' => 12]],
            'mail' => ['queues' => ['emails', 'default'], 'workers' => 2, 'slots_per_worker' => 100, 'autoscale' => ['enabled' => false]],
        ]]],
    ], 'web-1');

    $groups = array_map(
        static fn (array $entry): WorkerGroup => WorkerGroup::fromConfig($entry, 'redis://127.0.0.1:6379'),
        NodeConfig::groups($resolved),
    );

    expect($groups)->toHaveCount(2)
        ->and($groups[0]->name)->toBe('imports')
        ->and($groups[0]->slots)->toBe(20)
        ->and($groups[0]->maxWorkers)->toBe(12)
        ->and($groups[1]->name)->toBe('mail')
        ->and($groups[1]->slots)->toBe(100)
        ->and($groups[1]->autoScaler)->toBeNull();
});

it('counts live PIDs minus those being scaled down plus owed respawns', function () {
    [$group] = clockedGroup();

    expect($group->fleetSize([101 => true, 102 => true, 103 => true], [102 => true]))->toBe(2)
        ->and($group->fleetSize([], []))->toBe(0)
        ->and($group->fleetSize([101 => true], [999 => true]))->toBe(1);

    $group->oweRespawn();
    $group->oweRespawn();

    expect($group->fleetSize([101 => true, 102 => true], [102 => true]))->toBe(3);
});

it('doubles the fork backoff from 1 second up to the cap', function () {
    [$group, $clock] = clockedGroup();

    $group->oweRespawn();

    $waits = [];

    for ($i = 0; $i < 7; $i++) {
        $waits[] = $group->deferRespawn();
    }

    expect($waits)->toBe([1.0, 2.0, 4.0, 8.0, 16.0, 30.0, 30.0])
        ->and($group->respawnRetryAt)->toBe($clock->now + 30.0)
        ->and($group->pendingRespawns)->toBe(1);
});

it('makes an owed respawn due only once its backoff has passed', function () {
    [$group, $clock] = clockedGroup();

    expect($group->respawnDue())->toBeFalse();

    $group->oweRespawn();
    $group->deferRespawn();

    expect($group->respawnDue())->toBeFalse();

    $clock->now += 0.999;
    expect($group->respawnDue())->toBeFalse();

    $clock->now += 0.001;
    expect($group->respawnDue())->toBeTrue()
        ->and($group->respawnDue($clock->now - 0.5))->toBeFalse();
});

it('lowers the owed count and restarts the fork backoff on a successful respawn', function () {
    [$group] = clockedGroup();

    $group->oweRespawn();
    $group->oweRespawn();
    $group->deferRespawn();
    $group->deferRespawn();

    $group->recordRespawn();

    expect($group->pendingRespawns)->toBe(1)
        ->and($group->respawnBackoff)->toBe(0.0)
        ->and($group->deferRespawn())->toBe(1.0);

    $group->recordRespawn();
    $group->recordRespawn();

    expect($group->pendingRespawns)->toBe(0)
        ->and($group->hasPendingRespawns())->toBeFalse();
});

it('keeps the running backoff when another respawn joins the queue', function () {
    [$group] = clockedGroup();

    $group->oweRespawn();
    $group->deferRespawn();
    $group->deferRespawn();
    $retryAt = $group->respawnRetryAt;

    expect($group->hasPendingRespawns())->toBeTrue();

    $group->oweRespawn();

    expect($group->pendingRespawns)->toBe(2)
        ->and($group->respawnBackoff)->toBe(2.0)
        ->and($group->respawnRetryAt)->toBe($retryAt);
});

it('cancels an owed respawn and restarts the backoff once none is left', function () {
    [$group] = clockedGroup();

    expect($group->cancelPendingRespawn())->toBeFalse();

    $group->oweRespawn();
    $group->oweRespawn();
    $group->deferRespawn();
    $group->deferRespawn();

    expect($group->cancelPendingRespawn())->toBeTrue()
        ->and($group->pendingRespawns)->toBe(1)
        ->and($group->respawnBackoff)->toBe(2.0)
        ->and($group->cancelPendingRespawn())->toBeTrue()
        ->and($group->pendingRespawns)->toBe(0)
        ->and($group->respawnBackoff)->toBe(0.0);
});

it('drops every owed respawn on a stop but keeps the fast-death run', function () {
    [$group, $clock] = clockedGroup();

    $group->recordExit((int) $clock->now - 1, false);
    $group->oweRespawn();
    $group->deferRespawn();

    $group->dropPendingRespawns();

    expect($group->pendingRespawns)->toBe(0)
        ->and($group->respawnBackoff)->toBe(0.0)
        ->and($group->fastDeaths)->toBe(1);
});

it('respawns at once for an unknown spawn time, a long life, a watchdog kill or an orderly stop', function (?int $age, bool $exempt) {
    [$group, $clock] = clockedGroup();

    $outcome = $group->recordExit($age === null ? null : (int) $clock->now - $age, $exempt);

    expect($outcome['fastDeath'])->toBeFalse()
        ->and($outcome['resetRun'])->toBeFalse()
        ->and($group->pendingRespawns)->toBe(0)
        ->and($group->fastDeaths)->toBe(0);
})->with([
    'unknown spawn time' => [null, false],
    'lived exactly the limit' => [WorkerGroup::FAST_DEATH_SECONDS, false],
    'lived long' => [3600, false],
    'young but exempt' => [2, true],
]);

it('owes a fast death on a doubling backoff and flags the error threshold once', function () {
    [$group, $clock] = clockedGroup();

    $outcomes = [];

    for ($i = 0; $i < 7; $i++) {
        $outcomes[] = $group->recordExit((int) $clock->now - 2, false);
    }

    expect(array_column($outcomes, 'fastDeath'))->each->toBeTrue()
        ->and(array_column($outcomes, 'backoff'))->toBe([1.0, 2.0, 4.0, 8.0, 16.0, 30.0, 30.0])
        ->and(array_column($outcomes, 'backoffChanged'))->toBe([true, true, true, true, true, true, false])
        ->and(array_column($outcomes, 'reachedErrorThreshold'))->toBe([false, false, true, false, false, false, false])
        ->and($outcomes[0]['age'])->toBe(2.0)
        ->and($group->fastDeaths)->toBe(7)
        ->and($group->fastDeathsSince)->toBe(1_000)
        ->and($group->pendingRespawns)->toBe(7)
        ->and($group->respawnRetryAt)->toBe($clock->now + 30.0);
});

it('never pulls the retry time forward on a fast death', function () {
    [$group, $clock] = clockedGroup();

    $group->oweRespawn();

    for ($i = 0; $i < 5; $i++) {
        $group->deferRespawn();
    }

    $group->recordExit((int) $clock->now - 1, false);

    expect($group->respawnRetryAt)->toBe($clock->now + 16.0)
        ->and($group->respawnDue($clock->now + 1.0))->toBeFalse();
});

it('ends a fast-death run when a worker spawned during it exits after living long enough', function () {
    [$group, $clock] = clockedGroup();

    $group->recordExit((int) $clock->now - 1, false);
    $since = $group->fastDeathsSince;

    $clock->now += 20;
    $outcome = $group->recordExit($since + 5, false);

    expect($outcome['fastDeath'])->toBeFalse()
        ->and($outcome['resetRun'])->toBeTrue()
        ->and($group->fastDeaths)->toBe(0)
        ->and($group->fastDeathsSince)->toBe(0)
        ->and($group->fastDeathBackoff)->toBe(0.0);
});

it('keeps a fast-death run when a worker spawned before it exits', function () {
    [$group, $clock] = clockedGroup();

    $group->recordExit((int) $clock->now - 1, false);

    $clock->now += 20;
    $outcome = $group->recordExit(500, false);

    expect($outcome['fastDeath'])->toBeFalse()
        ->and($outcome['resetRun'])->toBeFalse()
        ->and($group->fastDeaths)->toBe(1);
});

it('ends a fast-death run on a survivor spawned during it', function () {
    [$group, $clock] = clockedGroup();

    expect($group->resetFastDeathsOnSurvivor([101 => 0]))->toBeNull();

    $group->recordExit((int) $clock->now - 1, false);
    $since = $group->fastDeathsSince;

    $clock->now = $since + 9.0;
    expect($group->resetFastDeathsOnSurvivor([101 => $since - 100, 102 => $since, 103 => null]))->toBeNull()
        ->and($group->fastDeaths)->toBe(1);

    $clock->now = $since + 10.0;
    expect($group->resetFastDeathsOnSurvivor([101 => $since - 100, 102 => $since]))->toBe(102)
        ->and($group->fastDeaths)->toBe(0)
        ->and($group->resetFastDeaths())->toBeFalse();
});

it('keeps respawn and fast-death state per group', function () {
    [$imports, $clock] = clockedGroup(['name' => 'imports']);
    $mail = WorkerGroup::fromConfig([
        'name' => 'mail',
        'queues' => ['emails'],
        'workers' => 2,
        'slots_per_worker' => 100,
        'autoscale' => ['enabled' => true],
    ], 'redis://127.0.0.1:6379', static fn (): float => $clock->now);

    for ($i = 0; $i < 4; $i++) {
        $imports->recordExit((int) $clock->now - 1, false);
    }

    $imports->deferRespawn();

    expect($imports->pendingRespawns)->toBe(4)
        ->and($imports->fastDeaths)->toBe(4)
        ->and($mail->pendingRespawns)->toBe(0)
        ->and($mail->fastDeaths)->toBe(0)
        ->and($mail->respawnBackoff)->toBe(0.0)
        ->and($mail->respawnRetryAt)->toBe(0.0);

    $mail->oweRespawn();

    expect($mail->respawnDue())->toBeTrue()
        ->and($imports->respawnDue())->toBeFalse()
        ->and($mail->recordExit((int) $clock->now - 1, false)['backoff'])->toBe(1.0)
        ->and($imports->autoScaler)->toBeNull()
        ->and($mail->autoScaler)->not->toBe($imports->autoScaler);
});
