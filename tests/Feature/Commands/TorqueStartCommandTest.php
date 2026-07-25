<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Process\MasterProcess;

it('is registered as an artisan command', function () {
    $this->artisan('list')
        ->assertSuccessful();

    $commands = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all());

    expect($commands->has('torque:start'))->toBeTrue();
});

it('rejects invalid queue names with path traversal', function () {
    $this->artisan('torque:start', ['--queues' => '../../etc'])
        ->assertFailed()
        ->expectsOutputToContain('Invalid queue name');
});

it('rejects queue names with spaces', function () {
    $this->artisan('torque:start', ['--queues' => 'valid, in valid'])
        ->assertFailed()
        ->expectsOutputToContain('Invalid queue name');
});

it('rejects queue names with special characters', function () {
    $this->artisan('torque:start', ['--queues' => 'queue;rm -rf /'])
        ->assertFailed()
        ->expectsOutputToContain('Invalid queue name');
});

/*
 * Refusal / takeover gate. These tests only exercise the early-return paths;
 * a proceeding start would fork a real fleet, which belongs to integration
 * testing in a container, not the unit suite. The helper carries the
 * "torque:start" marker in argv (readPid identity check) but deliberately
 * not "artisan torque:start" (the worker orphan sweep pattern).
 */

function spawnFakeTorqueMaster(int $seconds = 10): int
{
    $pipes = [];
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
        throw new RuntimeException('Failed to spawn fake master.');
    }

    return (int) proc_get_status($proc)['pid'];
}

it('refuses to start while a live master holds the pid file', function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Requires posix.');
    }

    $master = spawnFakeTorqueMaster();
    file_put_contents(MasterProcess::pidFilePath(), (string) $master);
    usleep(150_000);

    try {
        $exit = Artisan::call('torque:start');

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('already running');
    } finally {
        posix_kill($master, SIGKILL);
        pcntl_waitpid($master, $status);
        @unlink(MasterProcess::pidFilePath());
    }
});

it('refuses a takeover whose pid does not match the live master', function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Requires posix.');
    }

    $master = spawnFakeTorqueMaster();
    file_put_contents(MasterProcess::pidFilePath(), (string) $master);
    usleep(150_000);

    try {
        $exit = Artisan::call('torque:start', ['--takeover' => $master + 1]);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('already running');
    } finally {
        posix_kill($master, SIGKILL);
        pcntl_waitpid($master, $status);
        @unlink(MasterProcess::pidFilePath());
    }
});
