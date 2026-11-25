<?php

declare(strict_types=1);

use Webpatser\Torque\Console\TorqueStopCommand;
use Webpatser\Torque\Control\ControlChannel;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Support\NodeName;
use Webpatser\Torque\Support\ProcessInspector;

beforeEach(function () {
    // The orphan sweep SIGKILLs any `artisan torque:worker` whose parent is
    // not a master, so a real pgrep scan could hit workers on this host.
    TorqueStopCommand::$workerPidLister = fn (): array => [];
});

afterEach(function () {
    TorqueStopCommand::$commandLineReader = null;
    TorqueStopCommand::$workerPidLister = null;

    // Clean up any PID files created during tests.
    $pidFile = storage_path('torque.pid');

    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
});

it('shows info when no PID file exists', function () {
    // Ensure no PID file exists.
    $pidFile = storage_path('torque.pid');

    if (file_exists($pidFile)) {
        unlink($pidFile);
    }

    $this->artisan('torque:stop')
        ->assertSuccessful()
        ->expectsOutputToContain('No running Torque processes found');
});

it('cleans up stale PID file when process is not running', function () {
    $pidFile = storage_path('torque.pid');

    // Write a PID that definitely does not correspond to a running process.
    // PID 2147483647 is the maximum 32-bit PID and almost certainly not in use.
    file_put_contents($pidFile, '2147483647');

    $this->artisan('torque:stop')
        ->assertSuccessful()
        ->expectsOutputToContain('not running');

    expect(file_exists($pidFile))->toBeFalse();
});

it('cleans up PID file with invalid PID', function () {
    $pidFile = storage_path('torque.pid');

    file_put_contents($pidFile, '0');

    $this->artisan('torque:stop')
        ->assertSuccessful();

    // PID file should be cleaned up.
    expect(file_exists($pidFile))->toBeFalse();
});

it('cleans up PID file with negative PID', function () {
    $pidFile = storage_path('torque.pid');

    file_put_contents($pidFile, '-1');

    $this->artisan('torque:stop')
        ->assertSuccessful();

    expect(file_exists($pidFile))->toBeFalse();
});

it('cleans up PID file with non-numeric content', function () {
    $pidFile = storage_path('torque.pid');

    file_put_contents($pidFile, 'not-a-pid');

    $this->artisan('torque:stop')
        ->assertSuccessful();

    expect(file_exists($pidFile))->toBeFalse();
});

/*
 * A stale PID file whose number was recycled by an unrelated live process
 * must never lead to a group signal. The test process itself stands in for
 * that unrelated process: before the fix the command would SIGTERM the test
 * runner's own process group.
 */

it('refuses to signal a live PID that is not a Torque master', function () {
    // If this runner's own argv mentions torque:start (a --filter value, for
    // instance) the command would rightly treat it as a master and SIGKILL
    // the test process group.
    if (str_contains((string) ProcessInspector::commandLine(getmypid()), 'torque:start')) {
        $this->markTestSkipped('The test process command line contains torque:start.');
    }

    $pidFile = storage_path('torque.pid');
    file_put_contents($pidFile, (string) getmypid());

    $swept = false;
    TorqueStopCommand::$workerPidLister = function () use (&$swept): array {
        $swept = true;

        return [];
    };

    // No master is running, so like horizon:terminate this is a success:
    // a deploy must not fail on a stale PID file.
    $this->artisan('torque:stop', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('is not a Torque master')
        ->expectsOutputToContain('No running Torque master found');

    // Reaching this line at all means no SIGKILL hit our group; the file
    // is left for its owner, as readers never unlink a live entry. The
    // orphan sweep still ran.
    expect(file_exists($pidFile))->toBeTrue()
        ->and((int) file_get_contents($pidFile))->toBe(getmypid())
        ->and($swept)->toBeTrue();
});

/*
 * An unreadable command line (a transient ps fork/exec failure, say) on a
 * live process is not proof of anything: it must neither allow a signal nor
 * count as "the master exited". The deaf master ignores SIGTERM, so it is
 * still alive when the reader starts failing after the two pre-signal checks.
 */

it('does not report a live but unreadable master as stopped', function () {
    $pid = spawnDeafTorqueMaster(60, [SIGTERM]);
    $pidFile = storage_path('torque.pid');
    file_put_contents($pidFile, (string) $pid);

    $calls = 0;
    TorqueStopCommand::$commandLineReader = function (int $target) use (&$calls): ?string {
        return ++$calls <= 2 ? ProcessInspector::commandLine($target) : null;
    };

    try {
        $this->artisan('torque:stop', ['--timeout' => 1])
            ->assertFailed()
            ->doesntExpectOutputToContain('stopped')
            ->expectsOutputToContain('could not be read');

        expect(ProcessInspector::isAlive($pid))->toBeTrue()
            ->and(file_exists($pidFile))->toBeTrue()
            ->and((int) file_get_contents($pidFile))->toBe($pid);
    } finally {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
});

it('refuses to signal a live PID whose command line cannot be read', function () {
    $pid = spawnDeafTorqueMaster(60, [SIGTERM]);
    $pidFile = storage_path('torque.pid');
    file_put_contents($pidFile, (string) $pid);

    TorqueStopCommand::$commandLineReader = fn (int $target): ?string => null;

    try {
        $this->artisan('torque:stop', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('could not be read');

        expect(ProcessInspector::isAlive($pid))->toBeTrue()
            ->and(file_exists($pidFile))->toBeTrue();
    } finally {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
});

it('refuses to follow a symlinked PID file', function () {
    $pidFile = storage_path('torque.pid');
    $target = tempnam(sys_get_temp_dir(), 'torque-pid-target-');
    file_put_contents($target, (string) getmypid());
    symlink($target, $pidFile);

    try {
        $this->artisan('torque:stop')
            ->assertFailed()
            ->expectsOutputToContain('symlink');

        expect(is_link($pidFile))->toBeTrue();
    } finally {
        @unlink($pidFile);
        @unlink($target);
    }
});

/*
 * The graceful window before SIGKILL is the drain worst case.
 * This is the one shutdown path that ends in SIGKILL on the whole process
 * group, so a window shorter than a full drain kills in-flight jobs. The
 * fake master ignores SIGTERM so the command always reaches its deadline;
 * the assertion is on the reported window, not on the kill, because the
 * helper is not a process-group leader and the group signal never reaches it.
 */

it('derives the graceful window from the pause, the grace and the slack', function () {
    // DrainSettingsTest pins the default slack.
    config()->set('torque.reload_pause_seconds', 1);
    config()->set('torque.drain_grace_seconds', 1);
    config()->set('torque.intervals.drain_slack_seconds', 1);

    $pid = spawnDeafTorqueMaster(60, [SIGTERM]);
    file_put_contents(storage_path('torque.pid'), (string) $pid);

    try {
        $this->artisan('torque:stop')
            ->assertSuccessful()
            ->expectsOutputToContain('timed out after 3 seconds');
    } finally {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
});

it('lets an explicit --timeout override the derived window', function () {
    config()->set('torque.drain_grace_seconds', 600);

    $pid = spawnDeafTorqueMaster(60, [SIGTERM]);
    file_put_contents(storage_path('torque.pid'), (string) $pid);

    try {
        $this->artisan('torque:stop', ['--timeout' => 0])
            ->assertSuccessful()
            ->expectsOutputToContain('timed out after 0 seconds');
    } finally {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
});

it('force-kills without waiting out the derived window', function () {
    config()->set('torque.drain_grace_seconds', 600);

    $pid = spawnDeafTorqueMaster(60, [SIGTERM]);
    file_put_contents(storage_path('torque.pid'), (string) $pid);

    $startedAt = microtime(true);

    try {
        $this->artisan('torque:stop', ['--force' => true])
            ->assertSuccessful();

        expect(microtime(true) - $startedAt)->toBeLessThan(5);
    } finally {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
});

/*
 * Remote stop: --node and --all send the fixed `stop` action through the
 * control channel against real Redis under a random prefix. The test plays
 * the master's part between two polls through the sleeper seam: it pops the
 * command, acks it and removes the master record as a clean exit would.
 */

describe('remote stop', function () {
    beforeEach(function () {
        $this->redis = torqueRedis();
        $this->prefix = 'torque-test-remote-stop-'.bin2hex(random_bytes(4)).':';

        config()->set('torque.redis.uri', torqueRedisUri());
        config()->set('torque.redis.prefix', $this->prefix);
        config()->set('torque.control.enabled', true);
        config()->set('torque.control.command_ttl_seconds', 60);

        $this->registry = new MasterRegistry($this->redis, $this->prefix);
        $this->channel = new ControlChannel($this->redis, $this->prefix);

        $this->publish = function (string $node, int $pid = 4321): string {
            $id = sprintf('%s-%d-%s', $node, $pid, bin2hex(random_bytes(4)));

            $this->registry->publish($id, [
                'node' => $node,
                'pid' => $pid,
                'started_at' => time(),
                'version' => '2.5.0',
                'supervised' => true,
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

        // Plays the master: pop, ack with the given verdict, optionally exit.
        $this->playMaster = fn (array $ids, bool $accept = true, bool $exit = true): Closure => function () use ($ids, $accept, $exit): void {
            foreach ($ids as $id) {
                foreach ($this->channel->receive($id) as $received) {
                    $this->channel->ack((string) $received['id'], $id, $received['command']['action'] ?? null, $accept);

                    if ($exit && $accept) {
                        $this->registry->remove($id);
                    }
                }
            }

            usleep(20_000);
        };
    });

    afterEach(function () {
        TorqueStopCommand::$remoteSleeper = null;

        $cursor = '0';

        do {
            [$cursor, $keys] = $this->redis->execute('SCAN', $cursor, 'MATCH', $this->prefix.'*', 'COUNT', '1000');
            $cursor = (string) $cursor;

            if ($keys !== []) {
                $this->redis->execute('DEL', ...$keys);
            }
        } while ($cursor !== '0');
    });

    it('sends stop only to the masters on the named node', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');

        $this->artisan('torque:stop', ['--node' => ['host-a'], '--timeout' => 0])
            ->assertFailed()
            ->expectsOutputToContain("Sent stop to master {$a}")
            ->expectsOutputToContain('timed out');

        $commands = ($this->queued)($a);

        expect($commands)->toHaveCount(1)
            ->and($commands[0]['action'])->toBe('stop')
            ->and($commands[0]['issuer'])->toBe('cli@'.NodeName::current())
            ->and(($this->queued)($b))->toBe([]);
    });

    it('accepts repeated options and comma lists', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');
        $c = ($this->publish)('host-c');
        $d = ($this->publish)('host-d');

        $this->artisan('torque:stop', ['--node' => ['host-a, host-b', 'host-c'], '--timeout' => 0])
            ->assertFailed();

        expect(($this->queued)($a))->toHaveCount(1)
            ->and(($this->queued)($b))->toHaveCount(1)
            ->and(($this->queued)($c))->toHaveCount(1)
            ->and(($this->queued)($d))->toBe([]);
    });

    it('reports acknowledged and stopped once the master exits', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');
        TorqueStopCommand::$remoteSleeper = ($this->playMaster)([$a]);

        $this->artisan('torque:stop', ['--node' => ['host-a'], '--timeout' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain("Master {$a} acknowledged the stop command")
            ->expectsOutputToContain("Master {$a} stopped");

        expect($this->registry->find($a))->toBeNull()
            ->and($this->registry->find($b))->not->toBeNull();
    });

    it('fails for a node without a live master but still stops the others', function () {
        $a = ($this->publish)('host-a');
        TorqueStopCommand::$remoteSleeper = ($this->playMaster)([$a]);

        $this->artisan('torque:stop', ['--node' => ['host-a,host-zz'], '--timeout' => 5])
            ->assertFailed()
            ->expectsOutputToContain('No live Torque master on node [host-zz]')
            ->expectsOutputToContain("Master {$a} stopped");
    });

    it('refuses an invalid node name before sending anything', function () {
        $a = ($this->publish)('host-a');

        $this->artisan('torque:stop', ['--node' => ['host-a,bad name!'], '--timeout' => 0])
            ->assertFailed()
            ->expectsOutputToContain('Nothing was sent');

        expect(($this->queued)($a))->toBe([]);
    });

    it('times out on a master that acknowledged but keeps running', function () {
        $a = ($this->publish)('host-a');
        TorqueStopCommand::$remoteSleeper = ($this->playMaster)([$a], exit: false);

        $this->artisan('torque:stop', ['--node' => ['host-a'], '--timeout' => 1])
            ->assertFailed()
            ->expectsOutputToContain("Master {$a} acknowledged the stop command")
            ->expectsOutputToContain("Master {$a} did not stop within 1s");
    });

    it('fails when the master rejects the command', function () {
        $a = ($this->publish)('host-a');
        TorqueStopCommand::$remoteSleeper = ($this->playMaster)([$a], accept: false);

        $this->artisan('torque:stop', ['--node' => ['host-a'], '--timeout' => 5])
            ->assertFailed()
            ->expectsOutputToContain("Master {$a} rejected the stop command");
    });

    it('asks for confirmation with --all and sends nothing when declined', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');

        $this->artisan('torque:stop', ['--all' => true, '--timeout' => 0])
            ->expectsConfirmation('Stop all 2 live Torque master(s) on nodes host-a, host-b?', 'no')
            ->assertFailed()
            ->expectsOutputToContain('Aborted');

        expect(($this->queued)($a))->toBe([])
            ->and(($this->queued)($b))->toBe([]);
    });

    it('stops every master with --all once confirmed', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');
        TorqueStopCommand::$remoteSleeper = ($this->playMaster)([$a, $b]);

        $this->artisan('torque:stop', ['--all' => true, '--timeout' => 5])
            ->expectsConfirmation('Stop all 2 live Torque master(s) on nodes host-a, host-b?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain("Master {$a} stopped")
            ->expectsOutputToContain("Master {$b} stopped");
    });

    it('skips the confirmation with --all --force', function () {
        $a = ($this->publish)('host-a');
        $b = ($this->publish)('host-b');

        $this->artisan('torque:stop', ['--all' => true, '--force' => true, '--timeout' => 0])
            ->assertFailed();

        expect(($this->queued)($a))->toHaveCount(1)
            ->and(($this->queued)($b))->toHaveCount(1);
    });

    it('refuses --force with --node', function () {
        $a = ($this->publish)('host-a');

        $this->artisan('torque:stop', ['--node' => ['host-a'], '--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('--force cannot be combined with --node');

        expect(($this->queued)($a))->toBe([]);
    });

    it('refuses --all together with --node', function () {
        $a = ($this->publish)('host-a');

        $this->artisan('torque:stop', ['--node' => ['host-a'], '--all' => true])
            ->assertFailed();

        expect(($this->queued)($a))->toBe([]);
    });

    it('refuses the remote forms when remote control is disabled', function () {
        config()->set('torque.control.enabled', false);
        $a = ($this->publish)('host-a');

        $this->artisan('torque:stop', ['--node' => ['host-a']])
            ->assertFailed()
            ->expectsOutputToContain('Remote control is disabled');

        $this->artisan('torque:stop', ['--all' => true, '--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('Remote control is disabled');

        expect(($this->queued)($a))->toBe([]);
    });
});
