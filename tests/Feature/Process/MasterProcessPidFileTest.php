<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;

/*
 * Pin the contract of the three PID-file helpers in MasterProcess.
 *
 *   - readPid(): public static, returns the master PID if alive, else null.
 *     Refuses to dereference a symlink at the path (TOCTOU guard) and
 *     unlinks stale entries.
 *
 *   - writePidFile(): private, atomic tmp + rename, refuses to overwrite a
 *     symlink at the path.
 *
 *   - removePidFile(): private, only unlinks when the file points at our
 *     own PID. The "only mine" rule is the regression guard for the reload
 *     swap: a draining old master must not delete the new master's PID
 *     file after the new master has rewritten it.
 *
 * Skip on Windows; `posix_kill` is required by readPid.
 */

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
        $this->markTestSkipped('PID file handling is posix-only.');
    }

    @mkdir(dirname(MasterProcess::pidFilePath()), 0755, true);
    masterCleanupPidFile();
});

afterEach(function () {
    masterCleanupPidFile();
});

function masterCleanupPidFile(): void
{
    $path = MasterProcess::pidFilePath();

    if (is_link($path)) {
        @unlink($path);

        return;
    }

    if (file_exists($path)) {
        @unlink($path);
    }

    @unlink($path.'.'.getmypid().'.tmp');
}

function invokeMasterPidMethod(string $method, ?MasterProcess $master = null): mixed
{
    $master ??= new MasterProcess([], fn () => null);

    return (new ReflectionMethod($master, $method))->invoke($master);
}

it('readPid returns null when the pid file is missing', function () {
    expect(MasterProcess::readPid())->toBeNull();
});

it('readPid returns null and unlinks the file when the pid is stale', function () {
    $child = pcntl_fork();
    if ($child === 0) {
        usleep(50_000);
        exit(0);
    }

    posix_kill($child, SIGKILL);
    pcntl_waitpid($child, $status);

    file_put_contents(MasterProcess::pidFilePath(), (string) $child);

    expect(MasterProcess::readPid())->toBeNull()
        ->and(file_exists(MasterProcess::pidFilePath()))->toBeFalse();
});

it('readPid returns the live pid when the file points at a running torque master', function () {
    // Fork a child that titles itself like a torque master so the
    // /proc/<pid>/cmdline check recognises it on Linux. On platforms
    // without /proc the liveness check alone carries the test.
    $child = pcntl_fork();
    if ($child === 0) {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title('torque:start');
        }
        usleep(800_000);
        exit(0);
    }

    file_put_contents(MasterProcess::pidFilePath(), (string) $child);
    usleep(150_000); // let the child apply its process title

    try {
        expect(MasterProcess::readPid())->toBe($child);
    } finally {
        posix_kill($child, SIGKILL);
        pcntl_waitpid($child, $status);
    }
});

it('readPid treats a recycled PID running an unrelated process as stale', function () {
    // Regression guard: a stale PID file surviving a container restart
    // on a bind mount can collide with a recycled PID assigned to an
    // unrelated process. posix_kill alone would mistake it for a live
    // master; the command-line check must reject it.
    if (! is_dir('/proc')) {
        $this->markTestSkipped('Requires /proc to inspect the process command line.');
    }

    $child = pcntl_fork();
    if ($child === 0) {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title('recycled-not-torque');
        }
        usleep(800_000);
        exit(0);
    }

    file_put_contents(MasterProcess::pidFilePath(), (string) $child);
    usleep(150_000);

    try {
        expect(MasterProcess::readPid())->toBeNull()
            ->and(file_exists(MasterProcess::pidFilePath()))->toBeFalse();
    } finally {
        posix_kill($child, SIGKILL);
        pcntl_waitpid($child, $status);
    }
});

it('readPid returns null when the path is a symlink', function () {
    symlink('/dev/null', MasterProcess::pidFilePath());

    expect(MasterProcess::readPid())->toBeNull()
        ->and(is_link(MasterProcess::pidFilePath()))->toBeTrue();
});

it('writePidFile writes our own PID atomically', function () {
    invokeMasterPidMethod('writePidFile');

    expect(file_exists(MasterProcess::pidFilePath()))->toBeTrue()
        ->and((int) file_get_contents(MasterProcess::pidFilePath()))->toBe(getmypid())
        ->and(file_exists(MasterProcess::pidFilePath().'.'.getmypid().'.tmp'))->toBeFalse();
});

it('writePidFile refuses to start when the path is a symlink', function () {
    symlink('/dev/null', MasterProcess::pidFilePath());

    expect(fn () => invokeMasterPidMethod('writePidFile'))
        ->toThrow(RuntimeException::class, 'is a symlink');
});

it('removePidFile unlinks the file when it points at our own PID', function () {
    file_put_contents(MasterProcess::pidFilePath(), (string) getmypid());

    invokeMasterPidMethod('removePidFile');

    expect(file_exists(MasterProcess::pidFilePath()))->toBeFalse();
});

it('removePidFile leaves the file alone when it points at a different PID', function () {
    // Regression guard for the reload swap: a draining old master must not
    // delete the new master's PID file after the new master has overwritten
    // it.
    $otherPid = getmypid() + 1;
    file_put_contents(MasterProcess::pidFilePath(), (string) $otherPid);

    invokeMasterPidMethod('removePidFile');

    expect(file_exists(MasterProcess::pidFilePath()))->toBeTrue()
        ->and((int) file_get_contents(MasterProcess::pidFilePath()))->toBe($otherPid);
});

it('removePidFile is a no-op when the file is missing', function () {
    invokeMasterPidMethod('removePidFile');

    expect(file_exists(MasterProcess::pidFilePath()))->toBeFalse();
});

it('removePidFile leaves a symlink alone', function () {
    symlink('/dev/null', MasterProcess::pidFilePath());

    invokeMasterPidMethod('removePidFile');

    expect(is_link(MasterProcess::pidFilePath()))->toBeTrue();
});
