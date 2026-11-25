<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Console\TorqueReloadCommand;
use Webpatser\Torque\Control\ControlChannel;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\NodeName;

/*
 * `torque:reload` orchestrates the zero-downtime swap: it reads the PID
 * written by the master, optionally spawns a replacement, watches for the
 * PID file to flip, then signals the old PID via SIGUSR2 to drain. The
 * master-side handling of SIGUSR2 has its own coverage; here we pin the
 * orchestrator.
 *
 * `posix_kill` and `pcntl_waitpid` are required; tests skip cleanly on
 * Windows.
 */

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill') || ! function_exists('pcntl_waitpid')) {
        $this->markTestSkipped('torque:reload requires posix + pcntl extensions.');
    }

    $pidFile = MasterProcess::pidFilePath();

    if (file_exists($pidFile) && ! is_link($pidFile)) {
        @unlink($pidFile);
    }

    @mkdir(dirname($pidFile), 0755, true);

    TorqueReloadCommand::$spawner = null;
    TorqueReloadCommand::$readinessChecker = null;

    // An explicit --timeout waits this much longer; the 5s default adds nothing to what is tested.
    config()->set('torque.intervals.reload_signal_slack_seconds', 1);
});

afterEach(function () {
    TorqueReloadCommand::$spawner = null;
    TorqueReloadCommand::$readinessChecker = null;

    $pidFile = MasterProcess::pidFilePath();

    if (file_exists($pidFile) && ! is_link($pidFile)) {
        @unlink($pidFile);
    }

    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
        // drain zombies
    }
});

function spawnSleep(int $seconds = 30): int
{
    $pipes = [];

    // The helper must pass MasterProcess::readPid()'s identity check, which
    // requires "torque:start" in /proc/<pid>/cmdline on Linux. The -r source
    // below carries the marker in argv from exec time (no title-set race).
    // It deliberately does NOT contain "artisan torque:start", so the pgrep
    // orphan sweeps in torque:start/torque:stop never target these helpers.
    $proc = proc_open(
        [PHP_BINARY, '-r', 'cli_set_process_title("torque:start"); sleep((int) $argv[1]);', (string) $seconds],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ],
        $pipes,
    );

    if (! is_resource($proc)) {
        throw new RuntimeException('Failed to spawn helper sleep process.');
    }

    $status = proc_get_status($proc);

    return (int) $status['pid'];
}

function writeMasterPidFile(int $pid): void
{
    file_put_contents(MasterProcess::pidFilePath(), (string) $pid);
}

function torqueProcessIsAlive(int $pid): bool
{
    $reaped = pcntl_waitpid($pid, $status, WNOHANG);

    if ($reaped === $pid) {
        return false;
    }

    if ($reaped === -1) {
        return @posix_kill($pid, 0);
    }

    return true;
}

function waitForTorqueExit(int $pid, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if (! torqueProcessIsAlive($pid)) {
            return true;
        }

        usleep(100_000);
    }

    return false;
}

it('returns failure when no PID file is present', function () {
    $exit = Artisan::call('torque:reload', ['--drain' => true]);

    expect($exit)->not->toBe(0);
});

it('signals SIGUSR2 to the running PID in drain-only mode', function () {
    $childPid = spawnSleep(30);
    writeMasterPidFile($childPid);

    try {
        $exit = Artisan::call('torque:reload', [
            '--drain' => true,
            '--timeout' => 0,
        ]);

        expect($exit)->toBe(0)
            ->and(waitForTorqueExit($childPid, 2))->toBeTrue();
    } finally {
        if (torqueProcessIsAlive($childPid)) {
            posix_kill($childPid, SIGKILL);
            pcntl_waitpid($childPid, $status);
        }
    }
});

it('leaves a master that is still draining alone when the timeout is shorter than the drain window', function () {
    // A deploy tool with its own run timeout passes a short --timeout so the
    // deploy does not block. That must mean "stop watching", not "cut the
    // drain short": the master keeps the drain ceiling it already enforces,
    // so no SIGTERM may be sent.
    config()->set('torque.drain_grace_seconds', 30);

    $childPid = spawnDeafTorqueMaster(30, [SIGUSR2]);
    writeMasterPidFile($childPid);

    try {
        $exit = Artisan::call('torque:reload', [
            '--drain' => true,
            '--timeout' => 0,
        ]);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('still draining')
            ->and(torqueProcessIsAlive($childPid))->toBeTrue();
    } finally {
        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
    }
});

it('escalates to SIGTERM once the master is past its own drain ceiling', function () {
    // With the pause and the grace at 0 the worst case is the slack alone, so
    // a master still alive after it is a wedged one. This branch had no
    // coverage while the deadline was a fixed 35s.
    config()->set('torque.reload_pause_seconds', 0);
    config()->set('torque.drain_grace_seconds', 0);
    config()->set('torque.intervals.drain_slack_seconds', 1);

    $childPid = spawnDeafTorqueMaster(60, [SIGUSR2]);
    writeMasterPidFile($childPid);

    try {
        $exit = Artisan::call('torque:reload', ['--drain' => true]);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('sending SIGTERM')
            ->and(waitForTorqueExit($childPid, 2))->toBeTrue();
    } finally {
        if (torqueProcessIsAlive($childPid)) {
            posix_kill($childPid, SIGKILL);
            pcntl_waitpid($childPid, $status);
        }
    }
});

it('returns failure when the spawner reports a failure to spawn', function () {
    $childPid = spawnSleep(30);
    writeMasterPidFile($childPid);

    TorqueReloadCommand::$spawner = fn () => null;

    try {
        $exit = Artisan::call('torque:reload', [
            '--force' => true,
            '--health-timeout' => 1,
            '--timeout' => 1,
        ]);

        expect($exit)->not->toBe(0)
            // The old master must NOT have been signalled when the spawn failed.
            ->and(torqueProcessIsAlive($childPid))->toBeTrue();
    } finally {
        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
    }
});

it('fails when readiness never reports OK within the health timeout', function () {
    $oldPid = spawnSleep(30);
    $newPid = spawnSleep(30);

    writeMasterPidFile($oldPid);

    TorqueReloadCommand::$spawner = fn () => $newPid;
    TorqueReloadCommand::$readinessChecker = fn () => false;

    try {
        $exit = Artisan::call('torque:reload', [
            '--force' => true,
            '--health-timeout' => 1,
            '--timeout' => 1,
        ]);

        expect($exit)->not->toBe(0)
            ->and(waitForTorqueExit($newPid, 2))->toBeTrue()
            // The old master must NOT have been signalled when readiness failed.
            ->and(torqueProcessIsAlive($oldPid))->toBeTrue();
    } finally {
        foreach ([$newPid, $oldPid] as $pid) {
            if (torqueProcessIsAlive($pid)) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }
});

it('completes the full reload when readiness reports OK and the old PID drains', function () {
    $oldPid = spawnSleep(30);
    $newPid = spawnSleep(30);

    writeMasterPidFile($oldPid);

    TorqueReloadCommand::$spawner = fn () => $newPid;
    TorqueReloadCommand::$readinessChecker = fn () => true;

    try {
        $exit = Artisan::call('torque:reload', [
            '--force' => true,
            '--health-timeout' => 5,
            '--timeout' => 0,
        ]);

        expect($exit)->toBe(0)
            ->and(waitForTorqueExit($oldPid, 2))->toBeTrue()
            ->and(torqueProcessIsAlive($newPid))->toBeTrue();
    } finally {
        foreach ([$newPid, $oldPid] as $pid) {
            if (torqueProcessIsAlive($pid)) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }
});

it('passes the old master pid to the spawner for the takeover flag', function () {
    $master = spawnSleep(10);
    writeMasterPidFile($master);

    $received = null;

    TorqueReloadCommand::$spawner = function (int $oldPid) use (&$received): ?int {
        $received = $oldPid;

        return null;
    };

    try {
        $exit = Artisan::call('torque:reload', ['--force' => true]);

        expect($exit)->toBe(1)
            ->and($received)->toBe($master);
    } finally {
        posix_kill($master, SIGKILL);
    }
});

it('treats an old master that already exited at drain time as success', function () {
    $master = spawnSleep(10);
    writeMasterPidFile($master);

    TorqueReloadCommand::$spawner = fn (): ?int => 999999;

    // Readiness "succeeds" and the old master dies in the same window, as
    // happens when the takeover master signalled the drain itself and the
    // old master exited before this command got to its own signal.
    TorqueReloadCommand::$readinessChecker = function () use ($master): bool {
        posix_kill($master, SIGKILL);
        pcntl_waitpid($master, $status);

        return true;
    };

    $exit = Artisan::call('torque:reload', ['--force' => true]);

    expect($exit)->toBe(0);
});

it('refuses the default mode against a supervised master', function () {
    // The helper master is a child of this test process, so its parent PID
    // is not 1: exactly the shape of a supervisord/systemd child.
    $master = spawnSleep(10);
    writeMasterPidFile($master);

    $spawnerConsulted = false;
    TorqueReloadCommand::$spawner = function () use (&$spawnerConsulted): ?int {
        $spawnerConsulted = true;

        return null;
    };

    try {
        $exit = Artisan::call('torque:reload');

        expect($exit)->not->toBe(0)
            ->and(Artisan::output())->toContain('process supervisor')
            // Refusal must happen before any takeover master is spawned.
            ->and($spawnerConsulted)->toBeFalse()
            ->and(torqueProcessIsAlive($master))->toBeTrue();
    } finally {
        posix_kill($master, SIGKILL);
        pcntl_waitpid($master, $status);
    }
});

it('succeeds quietly with --if-running when no PID file is present', function () {
    $exit = Artisan::call('torque:reload', ['--drain' => true, '--if-running' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('nothing to reload');
});

/*
 * Remote reload: --node and --all send the fixed `drain` action through the
 * control channel against real Redis under a random prefix and wait for the
 * ack. The test plays the master's part between two polls through the
 * sleeper seam.
 */

describe('remote reload', function () {
    beforeEach(function () {
        $this->redis = torqueRedis();
        $this->prefix = 'torque-test-remote-reload-'.bin2hex(random_bytes(4)).':';

        config()->set('torque.redis.uri', torqueRedisUri());
        config()->set('torque.redis.prefix', $this->prefix);
        config()->set('torque.control.enabled', true);
        config()->set('torque.control.command_ttl_seconds', 60);

        $this->registry = new MasterRegistry($this->redis, $this->prefix);
        $this->channel = new ControlChannel($this->redis, $this->prefix);

        $this->publish = function (string $node, bool $supervised = true): string {
            $id = sprintf('%s-%d-%s', $node, 4321, bin2hex(random_bytes(4)));

            $this->registry->publish($id, [
                'node' => $node,
                'pid' => 4321,
                'started_at' => time(),
                'version' => '2.5.0',
                'supervised' => $supervised,
                'queues' => ['default'],
                'workers' => 1,
                'slots' => 10,
                'state' => 'running',
            ]);

            return $id;
        };

        $this->queued = fn (string $id): array => array_map(
            static fn (string $raw): array => json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
            (array) $this->redis->execute('LRANGE', $this->channel->listKey($id), '0', '-1'),
        );

        // Plays the master: pop and ack with the given verdict.
        $this->playMaster = fn (array $ids, bool $accept = true): Closure => function () use ($ids, $accept): void {
            foreach ($ids as $id) {
                foreach ($this->channel->receive($id) as $received) {
                    $this->channel->ack((string) $received['id'], $id, $received['command']['action'] ?? null, $accept);
                }
            }

            usleep(20_000);
        };
    });

    afterEach(function () {
        TorqueReloadCommand::$remoteSleeper = null;

        $cursor = '0';

        do {
            [$cursor, $keys] = $this->redis->execute('SCAN', $cursor, 'MATCH', $this->prefix.'*', 'COUNT', '1000');
            $cursor = (string) $cursor;

            if ($keys !== []) {
                $this->redis->execute('DEL', ...$keys);
            }
        } while ($cursor !== '0');
    });

    it('sends drain only to the master on the named node and waits for the ack', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');

        $seen = [];
        TorqueReloadCommand::$remoteSleeper = function () use ($a, &$seen): void {
            foreach ($this->channel->receive($a) as $received) {
                $seen[] = $received['command'];
                $this->channel->ack((string) $received['id'], $a, 'drain', true);
            }

            usleep(20_000);
        };

        $this->artisan('torque:reload', ['--node' => ['host-a'], '--timeout' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain("Sent drain to master {$a}")
            ->expectsOutputToContain("Master {$a} acknowledged the drain");

        expect($seen)->toHaveCount(1)
            ->and($seen[0]['action'])->toBe('drain')
            ->and($seen[0]['issuer'])->toBe('cli@'.NodeName::current())
            ->and(($this->queued)($b))->toBe([]);
    });

    it('accepts repeated options and comma lists', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');
        $c = ($this->publish)('host-c');

        $this->artisan('torque:reload', ['--node' => ['host-a, host-b'], '--timeout' => 0])
            ->assertFailed();

        expect(($this->queued)($a))->toHaveCount(1)
            ->and(($this->queued)($b))->toHaveCount(1)
            ->and(($this->queued)($c))->toBe([]);
    });

    it('refuses an unsupervised master while a supervised one on another node is sent', function () {
        $a = ($this->publish)('host-a', supervised: false);
        $b = ($this->publish)('host-b');

        $this->artisan('torque:reload', ['--node' => ['host-a,host-b'], '--timeout' => 0])
            ->assertFailed()
            ->expectsOutputToContain('is not supervised')
            ->expectsOutputToContain('torque:stop --node=host-a')
            ->expectsOutputToContain("Sent drain to master {$b}");

        expect(($this->queued)($a))->toBe([])
            ->and(($this->queued)($b))->toHaveCount(1)
            ->and(($this->queued)($b)[0]['action'])->toBe('drain');
    });

    it('times out when no ack arrives', function () {
        $a = ($this->publish)('host-a');

        $this->artisan('torque:reload', ['--node' => ['host-a'], '--timeout' => 0])
            ->assertFailed()
            ->expectsOutputToContain("Master {$a} timed out");
    });

    it('fails when the master rejects the command', function () {
        $a = ($this->publish)('host-a');
        TorqueReloadCommand::$remoteSleeper = ($this->playMaster)([$a], accept: false);

        $this->artisan('torque:reload', ['--node' => ['host-a'], '--timeout' => 5])
            ->assertFailed()
            ->expectsOutputToContain("Master {$a} rejected the drain command");
    });

    it('drains every supervised master with --all', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');
        TorqueReloadCommand::$remoteSleeper = ($this->playMaster)([$a, $b]);

        $this->artisan('torque:reload', ['--all' => true, '--timeout' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain("Master {$a} acknowledged the drain")
            ->expectsOutputToContain("Master {$b} acknowledged the drain");
    });

    it('fails for a node without a live master, unless --if-running', function () {
        $this->artisan('torque:reload', ['--node' => ['host-zz'], '--timeout' => 0])
            ->assertFailed()
            ->expectsOutputToContain('No live Torque master on node [host-zz]');

        $this->artisan('torque:reload', ['--node' => ['host-zz'], '--if-running' => true, '--timeout' => 0])
            ->assertSuccessful()
            ->expectsOutputToContain('nothing to reload there');
    });

    it('refuses an invalid node name before sending anything', function () {
        $a = ($this->publish)('host-a');

        $this->artisan('torque:reload', ['--node' => ['host-a,bad name!']])
            ->assertFailed()
            ->expectsOutputToContain('Nothing was sent');

        expect(($this->queued)($a))->toBe([]);
    });

    it('refuses --force with the remote forms', function () {
        $a = ($this->publish)('host-a');

        $this->artisan('torque:reload', ['--node' => ['host-a'], '--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('--force cannot be combined');

        expect(($this->queued)($a))->toBe([]);
    });

    it('refuses the remote forms when remote control is disabled', function () {
        config()->set('torque.control.enabled', false);
        $a = ($this->publish)('host-a');

        $this->artisan('torque:reload', ['--node' => ['host-a']])
            ->assertFailed()
            ->expectsOutputToContain('Remote control is disabled');

        $this->artisan('torque:reload', ['--all' => true])
            ->assertFailed()
            ->expectsOutputToContain('Remote control is disabled');

        expect(($this->queued)($a))->toBe([]);
    });
});

it('succeeds quietly with --if-running when the PID file is stale', function () {
    // A PID whose process is gone fails readPid's identity check the same
    // way a recycled PID does; --if-running must treat that as "not running".
    $dead = spawnSleep(1);
    if (! waitForTorqueExit($dead, 3)) {
        posix_kill($dead, SIGKILL);
        pcntl_waitpid($dead, $status);
    }
    writeMasterPidFile($dead);

    $exit = Artisan::call('torque:reload', ['--drain' => true, '--if-running' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('nothing to reload');
});
