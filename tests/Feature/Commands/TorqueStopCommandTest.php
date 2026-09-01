<?php

declare(strict_types=1);

afterEach(function () {
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
 * The graceful window before SIGKILL is derived from `drain_grace_seconds`.
 * This is the one shutdown path that ends in SIGKILL on the whole process
 * group, so a window shorter than a full drain kills in-flight jobs. The
 * fake master ignores SIGTERM so the command always reaches its deadline;
 * the assertion is on the reported window, not on the kill, because the
 * helper is not a process-group leader and the group signal never reaches it.
 */

it('derives the graceful window from drain_grace_seconds', function () {
    config()->set('torque.drain_grace_seconds', 0);

    $pid = spawnDeafTorqueMaster(60, [SIGTERM]);
    file_put_contents(storage_path('torque.pid'), (string) $pid);

    try {
        $this->artisan('torque:stop')
            ->assertSuccessful()
            ->expectsOutputToContain('timed out after 15 seconds');
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
