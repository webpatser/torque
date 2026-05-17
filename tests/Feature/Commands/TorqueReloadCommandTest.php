<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Console\TorqueReloadCommand;
use Webpatser\Torque\Process\MasterProcess;

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

    $proc = proc_open(
        ['sleep', (string) $seconds],
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
            '--timeout' => 3,
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

it('returns failure when the spawner reports a failure to spawn', function () {
    $childPid = spawnSleep(30);
    writeMasterPidFile($childPid);

    TorqueReloadCommand::$spawner = fn () => null;

    try {
        $exit = Artisan::call('torque:reload', [
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
            '--health-timeout' => 5,
            '--timeout' => 3,
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
