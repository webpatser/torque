<?php

declare(strict_types=1);

use Webpatser\Torque\Console\TorqueStopCommand;
use Webpatser\Torque\Support\ProcessInspector;

afterEach(function () {
    TorqueStopCommand::$commandLineReader = null;

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

    $this->artisan('torque:stop', ['--force' => true])
        ->assertFailed()
        ->expectsOutputToContain('is not a Torque master');

    // Reaching this line at all means no SIGKILL hit our group; the file
    // is left for its owner, as readers never unlink a live entry.
    expect(file_exists($pidFile))->toBeTrue()
        ->and((int) file_get_contents($pidFile))->toBe(getmypid());
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
