<?php

declare(strict_types=1);

use Webpatser\Torque\Console\TorqueWorkerCommand;
use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Process\MasterProcess;

it('pidFilePath returns storage path', function () {
    $path = MasterProcess::pidFilePath();

    expect($path)->toContain('storage')
        ->and($path)->toEndWith('torque.pid');
});

it('readPid returns null when no pid file exists', function () {
    // Ensure no PID file exists.
    $path = MasterProcess::pidFilePath();

    if (file_exists($path)) {
        unlink($path);
    }

    expect(MasterProcess::readPid())->toBeNull();
});

it('readPid returns null for symlinks', function () {
    $path = MasterProcess::pidFilePath();
    $tmpFile = $path.'.symlink-test';

    // Clean up any previous test artifacts.
    if (is_link($path)) {
        unlink($path);
    }
    if (file_exists($path)) {
        unlink($path);
    }

    // Create a temp file and symlink the PID path to it.
    file_put_contents($tmpFile, (string) getmypid());
    symlink($tmpFile, $path);

    expect(MasterProcess::readPid())->toBeNull();

    // Clean up.
    if (is_link($path)) {
        unlink($path);
    }
    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

it('readPid returns null for invalid pid content', function () {
    $path = MasterProcess::pidFilePath();

    // Clean up symlinks first.
    if (is_link($path)) {
        unlink($path);
    }

    file_put_contents($path, '0');

    expect(MasterProcess::readPid())->toBeNull();

    // Clean up.
    if (file_exists($path) && ! is_link($path)) {
        unlink($path);
    }
});

it('writePidFile refuses to start when the PID path is a symlink', function () {
    $path = MasterProcess::pidFilePath();
    $decoy = $path.'.decoy';

    foreach ([$path, $decoy] as $p) {
        if (is_link($p) || file_exists($p)) {
            unlink($p);
        }
    }

    file_put_contents($decoy, '0');
    symlink($decoy, $path);

    try {
        $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);

        $reflection = new ReflectionMethod($master, 'writePidFile');
        $reflection->invoke($master);

        expect(false)->toBeTrue('writePidFile should have thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('symlink');
    } finally {
        if (is_link($path)) {
            unlink($path);
        }
        if (file_exists($decoy)) {
            unlink($decoy);
        }
    }
});

it('writePidFile writes the current pid atomically', function () {
    $path = MasterProcess::pidFilePath();

    foreach ([$path] as $p) {
        if (is_link($p) || file_exists($p)) {
            unlink($p);
        }
    }

    $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);

    $reflection = new ReflectionMethod($master, 'writePidFile');
    $reflection->invoke($master);

    expect(file_exists($path))->toBeTrue()
        ->and((int) file_get_contents($path))->toBe(getmypid());

    unlink($path);
});

it('writePidFile never writes through a symlink planted at the temp path', function () {
    $path = MasterProcess::pidFilePath();
    $tmpPath = $path.'.'.getmypid().'.tmp';
    $victim = $path.'.victim';

    foreach ([$path, $tmpPath, $victim] as $p) {
        if (is_link($p) || file_exists($p)) {
            unlink($p);
        }
    }

    file_put_contents($victim, 'precious');
    symlink($victim, $tmpPath);

    try {
        $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);

        $reflection = new ReflectionMethod($master, 'writePidFile');
        $reflection->invoke($master);

        expect(file_get_contents($victim))->toBe('precious')
            ->and(is_link($path))->toBeFalse()
            ->and((int) file_get_contents($path))->toBe(getmypid())
            ->and(file_exists($tmpPath) || is_link($tmpPath))->toBeFalse();
    } finally {
        foreach ([$path, $tmpPath, $victim] as $p) {
            if (is_link($p) || file_exists($p)) {
                unlink($p);
            }
        }
    }
});

it('writePidFile replaces a stale temp file and keeps the PID file group-writable', function () {
    $path = MasterProcess::pidFilePath();
    $tmpPath = $path.'.'.getmypid().'.tmp';

    foreach ([$path, $tmpPath] as $p) {
        if (is_link($p) || file_exists($p)) {
            unlink($p);
        }
    }

    file_put_contents($tmpPath, 'stale');

    try {
        $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);

        $reflection = new ReflectionMethod($master, 'writePidFile');
        $reflection->invoke($master);

        expect((int) file_get_contents($path))->toBe(getmypid())
            ->and(fileperms($path) & 0777)->toBe(0664);
    } finally {
        foreach ([$path, $tmpPath] as $p) {
            if (is_link($p) || file_exists($p)) {
                unlink($p);
            }
        }
    }
});

it('refuses to use a master lock file that is a symlink', function () {
    $path = MasterProcess::lockFilePath();
    $target = $path.'.dangling-target';
    $logged = [];

    foreach ([$path, $target] as $p) {
        if (is_link($p) || file_exists($p)) {
            unlink($p);
        }
    }

    symlink($target, $path);

    try {
        $master = new MasterProcess(config: ['workers' => 1], logger: function (string $m) use (&$logged) {
            $logged[] = $m;
        });

        $reflection = new ReflectionMethod($master, 'tryAcquireMasterLock');

        expect($reflection->invoke($master))->toBeFalse()
            ->and(file_exists($target))->toBeFalse()
            ->and(implode("\n", $logged))->toContain('symlink');
    } finally {
        foreach ([$path, $target] as $p) {
            if (is_link($p) || file_exists($p)) {
                unlink($p);
            }
        }
    }
});

it('constructor accepts config and logger', function () {
    $logMessages = [];
    $logger = function (string $message) use (&$logMessages) {
        $logMessages[] = $message;
    };

    $master = new MasterProcess(
        config: ['workers' => 2],
        logger: $logger,
    );

    expect($master)->toBeInstanceOf(MasterProcess::class)
        ->and($master->workerPids)->toBe([])
        ->and($master->workerCount)->toBe(0);
});

// Fake child PIDs above every kernel's pid_max (Linux caps at 2^22), so a
// posix_kill from the code under test can only ever return ESRCH.
const FAKE_CHILD_PID = 2147483000;

it('keeps supervising when a respawn fork fails and retries it after a backoff', function () {
    $logs = [];
    $master = new MasterProcess(config: ['workers' => 1], logger: function (string $m) use (&$logs) {
        $logs[] = $m;
    });

    $fork = new ReflectionProperty($master, 'fork');
    $pending = new ReflectionProperty($master, 'pendingRespawns');
    $retry = new ReflectionMethod($master, 'retryPendingRespawns');

    // The OS refuses the fork (EAGAIN, ENOMEM): no throw out of the monitor loop.
    $fork->setValue($master, fn (): int => -1);
    (new ReflectionMethod($master, 'respawnWorker'))->invoke($master);

    expect($pending->getValue($master))->toBe(1)
        ->and($master->workerPids)->toBe([])
        ->and(implode("\n", $logs))->toContain('fork failed');

    // Still failing at the retry: it stays owed.
    $now = microtime(true);
    $retry->invoke($master, $now + 3600);
    expect($pending->getValue($master))->toBe(1);

    // Fork works again, but the backoff of that last failure has not passed.
    $fork->setValue($master, fn (): int => FAKE_CHILD_PID);
    $retry->invoke($master, $now + 3600.5);
    expect($master->workerPids)->toBe([]);

    $retry->invoke($master, $now + 7200);
    expect($pending->getValue($master))->toBe(0)
        ->and($master->workerPids)->toBe([FAKE_CHILD_PID => true])
        ->and($master->workerCount)->toBe(1);
});

// Wait statuses as waitpid() reports them on Linux and macOS.
function torque_exit_status(int $code): int
{
    return $code << 8;
}

it('respawns a worker that died young on a doubling backoff and logs an error after a few', function () {
    $logs = [];
    $errors = [];
    $master = new MasterProcess(
        config: ['workers' => 2],
        logger: function (string $m) use (&$logs) {
            $logs[] = $m;
        },
        errorLogger: function (string $m) use (&$errors) {
            $errors[] = $m;
        },
    );
    $forks = 0;
    (new ReflectionProperty($master, 'fork'))->setValue($master, function () use (&$forks): int {
        return FAKE_CHILD_PID + $forks++;
    });
    $exit = new ReflectionMethod($master, 'handleWorkerExit');
    $retry = new ReflectionMethod($master, 'retryPendingRespawns');
    $pending = new ReflectionProperty($master, 'pendingRespawns');
    $retryAt = new ReflectionProperty($master, 'respawnRetryAt');
    $spawnedAt = new ReflectionProperty($master, 'workerSpawnedAt');
    $pids = new ReflectionProperty($master, 'workerPids');

    $now = (float) time();
    $pids->setValue($master, [1001 => true, 1002 => true]);
    $spawnedAt->setValue($master, [1001 => (int) $now - 1, 1002 => (int) $now - 1]);

    // A failed exec: SIGKILL right after the spawn. Owed, not forked.
    $exit->invoke($master, 1001, SIGKILL, $now);

    expect($forks)->toBe(0)
        ->and($pending->getValue($master))->toBe(1)
        ->and($retryAt->getValue($master))->toBe($now + 1.0)
        ->and(implode("\n", $logs))->toContain('Worker PID 1001 killed by signal 9 (SIGKILL)')
        ->toContain('respawning in 1s');

    // A boot exception: the wait doubles.
    $exit->invoke($master, 1002, torque_exit_status(1), $now);

    expect($pending->getValue($master))->toBe(2)
        ->and($retryAt->getValue($master))->toBe($now + 2.0)
        ->and($errors)->toBe([]);

    // Not before the wait has passed.
    $retry->invoke($master, $now + 1.5);
    expect($forks)->toBe(0);

    $retry->invoke($master, $now + 2.0);
    $retry->invoke($master, $now + 2.1);
    expect($forks)->toBe(2)
        ->and($pending->getValue($master))->toBe(0);

    // The third in a row: the error names the status and what it usually means.
    $spawnedAt->setValue($master, [FAKE_CHILD_PID => (int) $now + 2, FAKE_CHILD_PID + 1 => (int) $now + 2]);
    $exit->invoke($master, FAKE_CHILD_PID, torque_exit_status(TorqueWorkerCommand::EXIT_CONFIG), $now + 3);

    expect($retryAt->getValue($master))->toBe($now + 3 + 4.0)
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('3 workers in a row died within 10s')
        ->toContain('exited (code 78)')
        ->toContain('FLEDGE_EVENT_LOOP');

    // A worker spawned during the run that lives 10 seconds ends it.
    (new ReflectionMethod($master, 'resetFastDeathsOnSurvivor'))->invoke($master, $now + 2 + MasterProcess::FAST_DEATH_SECONDS);

    expect((new ReflectionProperty($master, 'fastDeaths'))->getValue($master))->toBe(0)
        ->and((new ReflectionProperty($master, 'fastDeathBackoff'))->getValue($master))->toBe(0.0)
        ->and(end($logs))->toContain('respawns are immediate');
});

it('respawns at once after an orderly stop, a memory limit or a watchdog kill, however young', function (int $status, bool $watchdog) {
    $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);
    $forks = 0;
    (new ReflectionProperty($master, 'fork'))->setValue($master, function () use (&$forks): int {
        return FAKE_CHILD_PID + $forks++;
    });

    $now = (float) time();
    (new ReflectionProperty($master, 'workerPids'))->setValue($master, [1001 => true]);
    (new ReflectionProperty($master, 'workerSpawnedAt'))->setValue($master, [1001 => (int) $now]);

    if ($watchdog) {
        (new ReflectionProperty($master, 'stallKilledPids'))->setValue($master, [1001 => true]);
    }

    (new ReflectionMethod($master, 'handleWorkerExit'))->invoke($master, 1001, $status, $now + 1);

    expect($forks)->toBe(1)
        ->and((new ReflectionProperty($master, 'pendingRespawns'))->getValue($master))->toBe(0)
        ->and((new ReflectionProperty($master, 'fastDeaths'))->getValue($master))->toBe(0);
})->with([
    'exit 0' => [torque_exit_status(0), false],
    'memory limit' => [torque_exit_status(12), false],
    'watchdog SIGKILL' => [SIGKILL, true],
]);

it('does not let a worker from before a run of fast deaths end it', function () {
    $master = new MasterProcess(config: ['workers' => 2], logger: fn (string $m) => null);
    (new ReflectionProperty($master, 'fork'))->setValue($master, fn (): int => FAKE_CHILD_PID);

    $now = (float) time();
    (new ReflectionProperty($master, 'workerPids'))->setValue($master, [1001 => true, 1002 => true]);
    // 1001 has run for an hour; 1002 dies one second after its spawn.
    (new ReflectionProperty($master, 'workerSpawnedAt'))->setValue($master, [1001 => (int) $now - 3600, 1002 => (int) $now - 1]);

    (new ReflectionMethod($master, 'handleWorkerExit'))->invoke($master, 1002, torque_exit_status(1), $now);
    (new ReflectionMethod($master, 'resetFastDeathsOnSurvivor'))->invoke($master, $now + 60);

    expect((new ReflectionProperty($master, 'fastDeaths'))->getValue($master))->toBe(1);
});

it('drops owed respawns once the master is stopping', function () {
    $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);

    (new ReflectionProperty($master, 'fork'))->setValue($master, fn (): int => FAKE_CHILD_PID);
    (new ReflectionProperty($master, 'pendingRespawns'))->setValue($master, 2);
    (new ReflectionProperty($master, 'shouldStop'))->setValue($master, true);

    (new ReflectionMethod($master, 'retryPendingRespawns'))->invoke($master, microtime(true) + 3600);

    expect((new ReflectionProperty($master, 'pendingRespawns'))->getValue($master))->toBe(0)
        ->and($master->workerPids)->toBe([]);
});

it('puts a failed scale-up fork on the owed-respawn backoff', function () {
    $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);
    $scaler = new AutoScaler(redisUri: 'redis://127.0.0.1:6379', minWorkers: 2, cooldownSeconds: 0);
    $forks = 0;

    (new ReflectionProperty($master, 'autoScaler'))->setValue($master, $scaler);
    (new ReflectionProperty($master, 'fork'))->setValue($master, function () use (&$forks): int {
        $forks++;

        return -1;
    });

    (new ReflectionMethod($master, 'scaleUp'))->invoke($master);

    // The worker is owed, so it counts toward the fleet and the scaler does
    // not ask again; the retry waits out the backoff instead of forking each tick.
    expect($forks)->toBe(1)
        ->and((new ReflectionProperty($master, 'pendingRespawns'))->getValue($master))->toBe(1)
        ->and((new ReflectionMethod($master, 'fleetSize'))->invoke($master))->toBe(1)
        ->and((new ReflectionProperty($master, 'respawnRetryAt'))->getValue($master))->toBeGreaterThan(microtime(true));

    (new ReflectionMethod($master, 'retryPendingRespawns'))->invoke($master, microtime(true));

    expect($forks)->toBe(1);
});

it('owes no respawn into a fleet that is draining', function () {
    $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);
    $retry = new ReflectionMethod($master, 'retryPendingRespawns');
    $pending = new ReflectionProperty($master, 'pendingRespawns');

    (new ReflectionProperty($master, 'fork'))->setValue($master, fn (): int => FAKE_CHILD_PID);

    foreach (['drainRequested', 'draining'] as $flag) {
        $pending->setValue($master, 1);
        (new ReflectionProperty($master, $flag))->setValue($master, true);

        $retry->invoke($master, microtime(true) + 3600);

        expect($master->workerPids)->toBe([])
            ->and($pending->getValue($master))->toBe(1);

        (new ReflectionProperty($master, $flag))->setValue($master, false);
    }
});

/**
 * A TCP listener that accepts the handshake (the kernel backlog does) and
 * never answers: a silent Redis. Closed by the caller.
 *
 * @return array{0: resource, 1: int}
 */
function silentRedisServer(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($server === false) {
        test()->markTestSkipped("Cannot listen: {$errstr}");
    }

    $name = (string) stream_socket_get_name($server, false);

    return [$server, (int) substr($name, strrpos($name, ':') + 1)];
}

it('survives a silent Redis in the autoscale and drain reads and starts the next tick on a new client', function () {
    [$server, $port] = silentRedisServer();
    $logs = [];

    try {
        $master = new MasterProcess(
            config: ['workers' => 1, 'redis' => ['uri' => "redis://127.0.0.1:{$port}", 'read_timeout_seconds' => 0.3]],
            logger: function (string $m) use (&$logs) {
                $logs[] = $m;
            },
        );

        $publisher = new ReflectionProperty($master, 'metricsPublisher');
        $publisher->setValue($master, (new ReflectionMethod($master, 'makeMetricsPublisher'))->invoke($master));
        (new ReflectionProperty($master, 'autoScaler'))->setValue($master, new AutoScaler(redisUri: 'unused', cooldownSeconds: 0));
        (new ReflectionProperty($master, 'fork'))->setValue($master, fn (): int => FAKE_CHILD_PID);

        $before = $publisher->getValue($master);
        (new ReflectionMethod($master, 'evaluateAutoscale'))->invoke($master);
        $afterAutoscale = $publisher->getValue($master);

        expect($afterAutoscale)->not->toBe($before)
            ->and($master->workerPids)->toBe([])
            ->and(implode("\n", $logs))->toContain('Autoscale skipped');

        expect((new ReflectionMethod($master, 'fleetIsIdle'))->invoke($master))->toBeFalse()
            ->and($publisher->getValue($master))->not->toBe($afterAutoscale);
    } finally {
        fclose($server);
    }
});

it('does not scale up while the fleet is paused', function () {
    $redis = torqueRedis();
    $prefix = 'torque-test-'.bin2hex(random_bytes(4)).':';

    $master = new MasterProcess(
        config: ['workers' => 1, 'redis' => ['uri' => torqueRedisUri(), 'prefix' => $prefix]],
        logger: fn (string $m) => null,
    );

    (new ReflectionProperty($master, 'metricsPublisher'))->setValue($master, (new ReflectionMethod($master, 'makeMetricsPublisher'))->invoke($master));
    (new ReflectionProperty($master, 'autoScaler'))->setValue($master, new AutoScaler(redisUri: 'unused', minWorkers: 2, cooldownSeconds: 0));
    (new ReflectionProperty($master, 'fork'))->setValue($master, fn (): int => FAKE_CHILD_PID);
    $evaluate = new ReflectionMethod($master, 'evaluateAutoscale');

    try {
        // A deliberate torque:pause holds the below-min rule back.
        $redis->execute('SET', $prefix.'paused', (string) time());
        $evaluate->invoke($master);
        expect($master->workerPids)->toBe([]);

        // Another master's drain does not pause this fleet.
        $redis->execute('SET', $prefix.'paused', 'drain:'.(FAKE_CHILD_PID + 9), 'EX', '60');
        $evaluate->invoke($master);
        expect($master->workerPids)->toBe([FAKE_CHILD_PID => true]);
    } finally {
        $redis->execute('DEL', $prefix.'paused');
    }
});

/**
 * A master on a fresh Redis prefix whose fake children report the given
 * active slots out of 50, with the autoscaler and publisher wired as run() does.
 *
 * @param  array<int, int>  $activeByPid
 * @return array{0: MasterProcess, 1: string}
 */
function autoscalingMaster(array $activeByPid, int $minWorkers, int $maxWorkers, Closure $logger): array
{
    $redis = torqueRedis();
    $prefix = 'torque-test-'.bin2hex(random_bytes(4)).':';

    $master = new MasterProcess(
        config: ['workers' => count($activeByPid), 'redis' => ['uri' => torqueRedisUri(), 'prefix' => $prefix]],
        logger: $logger,
    );

    (new ReflectionProperty($master, 'metricsPublisher'))->setValue($master, (new ReflectionMethod($master, 'makeMetricsPublisher'))->invoke($master));
    (new ReflectionProperty($master, 'autoScaler'))->setValue($master, new AutoScaler(
        redisUri: 'unused',
        minWorkers: $minWorkers,
        maxWorkers: $maxWorkers,
        cooldownSeconds: 0,
    ));
    (new ReflectionProperty($master, 'fork'))->setValue($master, fn (): int => -1);
    (new ReflectionProperty($master, 'workerPids'))->setValue($master, array_fill_keys(array_keys($activeByPid), true));

    foreach ($activeByPid as $pid => $active) {
        $redis->execute('HSET', $prefix.'worker:test-'.$pid, 'pid', (string) $pid, 'active_slots', (string) $active, 'total_slots', '50');
    }

    return [$master, $prefix];
}

function forgetAutoscalingMaster(string $prefix, array $pids): void
{
    foreach ($pids as $pid) {
        torqueRedis()->execute('DEL', $prefix.'worker:test-'.$pid);
    }
}

it('cancels owed respawns before it terminates a live worker on scale-down', function () {
    $pids = [FAKE_CHILD_PID, FAKE_CHILD_PID + 1, FAKE_CHILD_PID + 2, FAKE_CHILD_PID + 3];
    [$master, $prefix] = autoscalingMaster(array_fill_keys($pids, 0), minWorkers: 2, maxWorkers: 8, logger: fn (string $m) => null);
    $pending = new ReflectionProperty($master, 'pendingRespawns');
    $scalingDown = new ReflectionProperty($master, 'scalingDownPids');
    $evaluate = new ReflectionMethod($master, 'evaluateAutoscale');

    try {
        // 4 live and 2 owed after ENOMEM, then the load drops.
        $pending->setValue($master, 2);

        $evaluate->invoke($master);
        $evaluate->invoke($master);

        expect($pending->getValue($master))->toBe(0)
            ->and($scalingDown->getValue($master))->toBe([])
            ->and(count($master->workerPids))->toBe(4)
            ->and((new ReflectionProperty($master, 'respawnBackoff'))->getValue($master))->toBe(0.0);

        // Nothing owed any more: the quietest live worker goes, as before.
        $evaluate->invoke($master);

        expect(count($scalingDown->getValue($master)))->toBe(1)
            ->and($pending->getValue($master))->toBe(0);
    } finally {
        forgetAutoscalingMaster($prefix, $pids);
    }
});

it('does not scale up past the cap while respawns are owed', function () {
    $pids = [FAKE_CHILD_PID, FAKE_CHILD_PID + 1];
    [$master, $prefix] = autoscalingMaster(array_fill_keys($pids, 50), minWorkers: 1, maxWorkers: 4, logger: fn (string $m) => null);
    $pending = new ReflectionProperty($master, 'pendingRespawns');

    try {
        // 2 live and 2 owed under full load: the owed ones already fill the cap.
        $pending->setValue($master, 2);

        (new ReflectionMethod($master, 'evaluateAutoscale'))->invoke($master);

        expect($pending->getValue($master))->toBe(2)
            ->and(count($master->workerPids))->toBe(2);
    } finally {
        forgetAutoscalingMaster($prefix, $pids);
    }
});

it('keeps its clients on a non-Redis autoscale error and logs it again while it lasts', function () {
    $logs = [];
    // A bug in the pass itself, not Redis: the scale-up log line throws.
    [$master, $prefix] = autoscalingMaster([], minWorkers: 2, maxWorkers: 8, logger: function (string $m) use (&$logs): void {
        if (str_contains($m, 'scaling up')) {
            throw new LogicException('scaler bug');
        }

        $logs[] = $m;
    });
    $publisher = new ReflectionProperty($master, 'metricsPublisher');
    $client = new ReflectionProperty($master, 'masterRedis');
    $evaluate = new ReflectionMethod($master, 'evaluateAutoscale');
    $now = microtime(true);

    $evaluate->invoke($master, $now);
    $publisherBefore = $publisher->getValue($master);
    $clientBefore = $client->getValue($master);

    $evaluate->invoke($master, $now + 60);

    expect($publisher->getValue($master))->toBe($publisherBefore)
        ->and($client->getValue($master))->not->toBeNull()
        ->and($client->getValue($master))->toBe($clientBefore)
        ->and($logs)->toHaveCount(1)
        ->and($logs[0])->toContain('Autoscale skipped (LogicException: scaler bug)');

    $evaluate->invoke($master, $now + 300);

    expect($logs)->toHaveCount(2);
});

it('removes the PID file when the monitor loop ends by exception', function () {
    $master = new MasterProcess(config: ['workers' => 1], logger: function (string $m): void {
        throw new LogicException('logger down');
    });

    (new ReflectionMethod($master, 'writePidFile'))->invoke($master);
    (new ReflectionProperty($master, 'fork'))->setValue($master, fn (): int => -1);
    (new ReflectionProperty($master, 'pendingRespawns'))->setValue($master, 1);

    $async = pcntl_async_signals();

    try {
        expect(fn () => (new ReflectionMethod($master, 'runMonitor'))->invoke($master))
            ->toThrow(LogicException::class, 'logger down');

        expect(file_exists(MasterProcess::pidFilePath()))->toBeFalse();
    } finally {
        // monitor() blocks these for sigtimedwait where it exists.
        pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM, SIGINT, SIGCHLD, SIGUSR2]);
        pcntl_async_signals($async);
        @unlink(MasterProcess::pidFilePath());
    }
});

it('publishes owed respawns into its own host hash and clears them once', function () {
    $redis = torqueRedis();
    $prefix = 'torque-test-'.bin2hex(random_bytes(4)).':';
    $key = $prefix.'metrics:live:'.MetricsPublisher::normaliseHost(MetricsPublisher::localHost());

    $master = new MasterProcess(
        config: ['workers' => 1, 'redis' => ['uri' => torqueRedisUri(), 'prefix' => $prefix]],
        logger: fn (string $m) => null,
    );
    $pending = new ReflectionProperty($master, 'pendingRespawns');
    $publish = new ReflectionMethod($master, 'publishPendingRespawns');

    try {
        $publish->invoke($master);
        expect($redis->execute('EXISTS', $key))->toBe(0);

        $pending->setValue($master, 2);
        $publish->invoke($master);
        expect($redis->execute('HGET', $key, MasterProcess::PENDING_RESPAWNS_FIELD))->toBe('2');

        $pending->setValue($master, 0);
        $publish->invoke($master);
        expect($redis->execute('HGET', $key, MasterProcess::PENDING_RESPAWNS_FIELD))->toBe('0');

        // Nothing owed and nothing left to clear: no write at all.
        $redis->execute('DEL', $key);
        $publish->invoke($master);
        expect($redis->execute('EXISTS', $key))->toBe(0);
    } finally {
        $redis->execute('DEL', $key);
    }
});

it('never picks a draining worker for scale-down and counts it out of the fleet', function () {
    $master = new MasterProcess(config: ['workers' => 3], logger: fn (string $m) => null);
    [$draining, $busy, $quiet] = [FAKE_CHILD_PID, FAKE_CHILD_PID + 1, FAKE_CHILD_PID + 2];

    (new ReflectionProperty($master, 'workerPids'))->setValue($master, [$draining => true, $busy => true, $quiet => true]);
    $scalingDown = new ReflectionProperty($master, 'scalingDownPids');
    $scalingDown->setValue($master, [$draining => true]);
    $fleetSize = new ReflectionMethod($master, 'fleetSize');

    expect($fleetSize->invoke($master))->toBe(2);

    // The draining worker reports the fewest active slots, yet the next
    // victim is the quietest worker that is still in service.
    (new ReflectionMethod($master, 'scaleDown'))->invoke($master, [
        $draining => ['active' => 0, 'total' => 50],
        $busy => ['active' => 5, 'total' => 50],
        $quiet => ['active' => 3, 'total' => 50],
    ]);

    expect($scalingDown->getValue($master))->toBe([$draining => true, $quiet => true])
        ->and($fleetSize->invoke($master))->toBe(1);

    // Everyone draining: nothing left to pick.
    (new ReflectionProperty($master, 'workerPids'))->setValue($master, [$draining => true]);
    $scalingDown->setValue($master, [$draining => true]);
    (new ReflectionMethod($master, 'scaleDown'))->invoke($master, [$draining => ['active' => 0, 'total' => 50]]);

    expect($scalingDown->getValue($master))->toBe([$draining => true]);
});

it('turns cumulative per-class counters into per-tick deltas', function () {
    $master = new MasterProcess(config: ['workers' => 1], logger: fn (string $m) => null);

    $deltas = new ReflectionMethod($master, 'perJobDeltas');
    $state = new ReflectionProperty($master, 'lastAggregatePerJob');

    // With no previous sample the whole cumulative figure reads as the delta.
    // The caller only reaches this helper from the second publish onwards, so
    // in practice the first tick is what seeds the state below.
    expect($deltas->invoke($master, ['App\Jobs\A' => [10, 2, 1000.0, 240.0]]))
        ->toBe(['App\Jobs\A' => [10, 2, 1000.0, 240.0]]);

    $state->setValue($master, ['App\Jobs\A' => [10, 2, 1000.0, 240.0]]);

    expect($deltas->invoke($master, [
        'App\Jobs\A' => [14, 3, 1400.0, 90.0],
        // A class that has not moved contributes nothing at all.
        'App\Jobs\B' => [0, 0, 0.0, 0.0],
    ]))->toBe([
        // Counters and the runtime sum are deltas; the peak is whatever the
        // largest run reported this tick was, so it passes through unchanged
        // even when it is lower than the previous tick's.
        'App\Jobs\A' => [4, 1, 400.0, 90.0],
    ]);

    // A worker restart resets its counters, so the cumulative total falls. The
    // clamp turns that into no delta at all rather than negative history: one
    // tick is lost and the next resynchronises, which is the same trade the
    // cluster-wide throughput delta makes.
    $state->setValue($master, ['App\Jobs\A' => [999, 999, 99999.0, 500.0]]);

    expect($deltas->invoke($master, ['App\Jobs\A' => [3, 1, 30.0, 12.0]]))->toBe([]);
});
