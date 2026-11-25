<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisClient;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Process\WorkerGroup;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeName;

/*
 * A master's worker groups: each group spawns, respawns, backs off and
 * autoscales on its own, and the registry record describes every group.
 * Driven through the master's methods against real Redis with a fake fork,
 * rather than start(), which would fork a fleet.
 */

/** First PID the fake fork hands out: above every kernel's pid_max, so a posix_kill only ever gets ESRCH. */
const WG_PID = 2147482000;

function wgForgetKeys(RedisClient $redis, string $prefix): void
{
    $cursor = '0';

    do {
        $reply = Narrow::list($redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '500'));
        $cursor = Narrow::string($reply[0] ?? '0', '0');

        foreach (Narrow::list($reply[1] ?? []) as $key) {
            $redis->execute('DEL', Narrow::string($key));
        }
    } while ($cursor !== '0');
}

/** Wait status of a worker that exited with this code. */
function wgExit(int $code): int
{
    return $code << 8;
}

function wgCall(MasterProcess $master, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($master, $method))->invoke($master, ...$arguments);
}

/** @return array<string, WorkerGroup> */
function wgGroups(MasterProcess $master): array
{
    return wgCall($master, 'groups');
}

/** Replace the fork with one that hands out WG_PID, WG_PID + 1, ... */
function wgFork(MasterProcess $master): void
{
    $next = WG_PID;

    (new ReflectionProperty($master, 'fork'))->setValue($master, function () use (&$next): int {
        return $next++;
    });
}

/**
 * Spawn workers into one group through the master's own spawn.
 *
 * @return list<int> The new PIDs.
 */
function wgSpawn(MasterProcess $master, string $group, int $count = 1): array
{
    $before = array_keys($master->workerPids);

    for ($i = 0; $i < $count; $i++) {
        wgCall($master, 'spawnWorker', wgGroups($master)[$group]);
    }

    return array_values(array_diff(array_keys($master->workerPids), $before));
}

/**
 * Give the named groups an autoscaler with these bounds and no cooldown, and
 * the master its metrics publisher, as startFleet() does.
 *
 * @param  array<string, array{0: int, 1: int}>  $bounds  Group name => [min, max].
 */
function wgAutoscaled(MasterProcess $master, array $bounds): void
{
    $groups = [];

    foreach (wgGroups($master) as $name => $group) {
        $scaler = isset($bounds[$name])
            ? new AutoScaler(redisUri: 'unused', minWorkers: $bounds[$name][0], maxWorkers: $bounds[$name][1], cooldownSeconds: 0)
            : null;

        $groups[$name] = new WorkerGroup($group->name, $group->queues, $group->target, $group->slots, $scaler);
    }

    (new ReflectionProperty($master, 'groups'))->setValue($master, $groups);
    (new ReflectionProperty($master, 'metricsPublisher'))->setValue($master, wgCall($master, 'makeMetricsPublisher'));
}

/** Workers per group, from the master's PID map. */
function wgCounts(MasterProcess $master): array
{
    $counts = array_count_values($master->workerGroup);
    ksort($counts);

    return $counts;
}

beforeEach(function () {
    NodeName::resetForTesting();

    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-groups-'.bin2hex(random_bytes(4)).':';
    $this->logged = [];

    $this->master = fn (array $config = []): MasterProcess => new MasterProcess(array_replace([
        'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
        'consumer_group' => 'torque-test',
        'streams' => ['imports' => [], 'emails' => [], 'default' => []],
        'node' => 'host-a',
        'groups' => [
            ['name' => 'imports', 'queues' => ['imports'], 'workers' => 2, 'slots_per_worker' => 10, 'autoscale' => []],
            ['name' => 'mail', 'queues' => ['emails', 'default'], 'workers' => 1, 'slots_per_worker' => 100, 'autoscale' => []],
            ['name' => 'spare', 'queues' => ['default'], 'workers' => 0, 'slots_per_worker' => 5, 'autoscale' => []],
        ],
    ], $config), function (string $message) {
        $this->logged[] = $message;
    });

    // A worker hash as a worker of this master publishes it.
    $this->heartbeat = function (MasterProcess $master, int $pid, int $active = 0, int $total = 50): void {
        $this->redis->execute(
            'HSET', $this->prefix.'worker:host-a-'.$pid.'-aaaaaaaa',
            'pid', (string) $pid,
            'host', 'host-a',
            'master', $master->masterId(),
            'active_slots', (string) $active,
            'total_slots', (string) $total,
        );
    };

    $this->log = fn (): string => implode("\n", $this->logged);
});

afterEach(function () {
    wgForgetKeys($this->redis, $this->prefix);
    NodeName::resetForTesting();
});

it('respawns an exited worker into its own group with that group\'s queues and slots', function () {
    $master = ($this->master)();
    wgFork($master);

    [$import] = wgSpawn($master, 'imports');
    [$mail] = wgSpawn($master, 'mail');

    wgCall($master, 'handleWorkerExit', $mail, wgExit(0), (float) time() + 1);

    $replacement = WG_PID + 2;

    expect($master->workerGroup)->toBe([$import => 'imports', $replacement => 'mail'])
        ->and(($this->log)())->toContain("[group mail] Worker PID {$mail} exited (code 0)")
        ->toContain('[group mail] Respawning replacement worker...')
        ->toContain("[group mail] Worker spawned with PID {$replacement}");

    expect(array_slice(wgCall($master, 'workerArguments', wgGroups($master)['mail']), 1))->toBe([
        'torque:worker',
        '--queues=emails,default',
        '--concurrency=100',
        '--node=host-a',
        '--master-id='.$master->masterId(),
        '--group=mail',
    ]);
});

it('keeps a fast death in one group from delaying another group', function () {
    $master = ($this->master)();
    wgFork($master);
    $groups = wgGroups($master);

    [$import1, $import2] = wgSpawn($master, 'imports', 2);
    [$mail] = wgSpawn($master, 'mail');
    $now = (float) time();

    // imports crashes twice at boot: its wait doubles to 2s.
    wgCall($master, 'handleWorkerExit', $import1, wgExit(1), $now);
    wgCall($master, 'handleWorkerExit', $import2, wgExit(1), $now);

    expect($groups['imports']->pendingRespawns)->toBe(2)
        ->and($groups['imports']->respawnRetryAt)->toBe($now + 2.0)
        ->and($groups['mail']->hasPendingRespawns())->toBeFalse();

    // An orderly mail exit meanwhile is replaced at once, not queued behind imports.
    wgCall($master, 'handleWorkerExit', $mail, wgExit(0), $now);
    expect(wgCounts($master))->toBe(['mail' => 1]);

    // A fast death in mail starts mail's own backoff at 1s, not at imports' 2s.
    $mailReplacement = array_key_first($master->workerPids);
    wgCall($master, 'handleWorkerExit', $mailReplacement, wgExit(1), $now);

    expect($groups['mail']->respawnRetryAt)->toBe($now + 1.0);

    // The owed-respawn field is the sum over groups.
    wgCall($master, 'publishPendingRespawns');
    expect($this->redis->execute('HGET', $this->prefix.'metrics:live:host-a', MasterProcess::PENDING_RESPAWNS_FIELD))->toBe('3');

    // After 1s only mail is due.
    wgCall($master, 'retryPendingRespawns', $now + 1.0);
    expect(wgCounts($master))->toBe(['mail' => 1])
        ->and($groups['imports']->pendingRespawns)->toBe(2);

    // After 2s imports gets one fork per tick.
    wgCall($master, 'retryPendingRespawns', $now + 2.0);
    expect(wgCounts($master))->toBe(['imports' => 1, 'mail' => 1]);

    wgCall($master, 'retryPendingRespawns', $now + 2.0);
    expect(wgCounts($master))->toBe(['imports' => 2, 'mail' => 1])
        ->and(wgCall($master, 'pendingRespawns'))->toBe(0);
});

it('scales a group down only among its own workers', function () {
    $master = ($this->master)();
    wgFork($master);
    wgAutoscaled($master, ['imports' => [1, 8], 'mail' => [1, 8]]);

    $imports = wgSpawn($master, 'imports', 2);
    $mail = wgSpawn($master, 'mail', 2);

    // imports is idle and scales down; mail is half busy and stays.
    foreach ($imports as $pid) {
        ($this->heartbeat)($master, $pid, active: 0);
    }

    foreach ($mail as $pid) {
        ($this->heartbeat)($master, $pid, active: 25);
    }

    $scalingDown = new ReflectionProperty($master, 'scalingDownPids');

    wgCall($master, 'evaluateAutoscale');

    expect(array_keys($scalingDown->getValue($master)))->toHaveCount(1)
        ->and(in_array(array_key_first($scalingDown->getValue($master)), $imports, true))->toBeTrue()
        ->and(($this->log)())->toContain('[group imports] Autoscaler: scaling down (workers: 2 -> 1)');

    // Even when a mail worker is the quietest of all, an imports scale-down
    // picks the imports worker that is left.
    wgCall($master, 'scaleDown', [$mail[0] => ['active' => 0, 'total' => 50], $mail[1] => ['active' => 0, 'total' => 50]], wgGroups($master)['imports']);

    expect(array_keys($scalingDown->getValue($master)))->toEqualCanonicalizing($imports);
});

it('does not grow a group whose queues are all paused', function () {
    $master = ($this->master)();
    wgFork($master);
    wgAutoscaled($master, ['imports' => [2, 8], 'mail' => [2, 8]]);

    wgSpawn($master, 'imports');
    wgSpawn($master, 'mail');

    // Below their floors both want a worker. imports' only queue is paused;
    // mail still has `default`.
    $scopes = new PauseScopes($this->redis, $this->prefix);
    $scopes->pauseQueue('imports');
    $scopes->pauseQueue('emails');

    wgCall($master, 'evaluateAutoscale');

    expect(wgCounts($master))->toBe(['imports' => 1, 'mail' => 2])
        ->and(($this->log)())->toContain('[group mail] Autoscaler: scaling up (workers: 1 -> 2)')
        ->not->toContain('[group imports] Autoscaler: scaling up');

    $scopes->resumeQueue('imports');
    wgCall($master, 'evaluateAutoscale');

    expect(wgCounts($master))->toBe(['imports' => 2, 'mail' => 2]);
});

it('is ready only once every group with workers has a heartbeat', function () {
    $master = ($this->master)(['takeover_ready_timeout_seconds' => 0.5]);
    wgFork($master);

    [$import] = wgSpawn($master, 'imports');
    [$mail] = wgSpawn($master, 'mail');

    // mail never reports; `spare` has no workers and is not waited for.
    ($this->heartbeat)($master, $import);

    expect(wgCall($master, 'waitForOwnFleetReady'))->toBeFalse();

    ($this->heartbeat)($master, $mail);

    expect(wgCall($master, 'waitForOwnFleetReady'))->toBeTrue();
});

it('publishes every group in its registry record', function () {
    $master = ($this->master)();
    wgFork($master);

    wgSpawn($master, 'imports', 2);
    wgSpawn($master, 'mail');

    $master->handleRegistryTick();
    $record = (new MasterRegistry($this->redis, $this->prefix))->find($master->masterId());

    expect($record)->not->toBeNull()
        ->and($record['queues'])->toBe(['imports', 'emails', 'default'])
        ->and($record['workers'])->toBe(3)
        ->and($record['slots'])->toBe(100)
        ->and($record['groups'])->toBe([
            'imports' => ['queues' => ['imports'], 'workers' => 2, 'target' => 2, 'min' => 2, 'max' => 2, 'slots' => 10],
            'mail' => ['queues' => ['emails', 'default'], 'workers' => 1, 'target' => 1, 'min' => 1, 'max' => 1, 'slots' => 100],
            'spare' => ['queues' => ['default'], 'workers' => 0, 'target' => 0, 'min' => 0, 'max' => 0, 'slots' => 5],
        ]);
});

it('runs a master without groups as one implicit default group, as before', function () {
    $master = ($this->master)([
        'groups' => null,
        'workers' => 2,
        'queues' => ['default', 'emails'],
        'slots_per_worker' => 20,
        'autoscale' => ['enabled' => true, 'min_workers' => 1, 'max_workers' => 3],
    ]);
    wgFork($master);

    $groups = wgGroups($master);

    expect(array_keys($groups))->toBe(['default'])
        ->and($groups['default']->queues)->toBe(['default', 'emails'])
        ->and($groups['default']->target)->toBe(2)
        ->and($groups['default']->slots)->toBe(20)
        ->and($groups['default']->minWorkers)->toBe(1)
        ->and($groups['default']->maxWorkers)->toBe(3);

    [$first, $second] = wgSpawn($master, 'default', 2);
    wgCall($master, 'handleWorkerExit', $first, wgExit(0), (float) time() + 1);

    // Untagged, exactly the lines a master logged before groups existed.
    expect($this->logged)->toBe([
        "Worker spawned with PID {$first}",
        "Worker spawned with PID {$second}",
        "Worker PID {$first} exited (code 0)",
        'Respawning replacement worker...',
        'Worker spawned with PID '.(WG_PID + 2),
    ]);

    $master->handleRegistryTick();
    $record = (new MasterRegistry($this->redis, $this->prefix))->find($master->masterId());

    expect($record['queues'])->toBe(['default', 'emails'])
        ->and($record['workers'])->toBe(2)
        ->and($record['slots'])->toBe(20)
        ->and($record['groups'])->toBe([
            'default' => ['queues' => ['default', 'emails'], 'workers' => 2, 'target' => 2, 'min' => 1, 'max' => 3, 'slots' => 20],
        ]);
});
