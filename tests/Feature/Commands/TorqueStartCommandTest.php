<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\NodeName;

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

/**
 * Run with FLEDGE_EVENT_LOOP set to this value in the process environment, or unset for null.
 */
function torque_with_event_loop_env(?string $value, Closure $test): void
{
    $original = getenv('FLEDGE_EVENT_LOOP');
    putenv($value === null ? 'FLEDGE_EVENT_LOOP' : "FLEDGE_EVENT_LOOP={$value}");

    try {
        $test();
    } finally {
        putenv($original === false ? 'FLEDGE_EVENT_LOOP' : "FLEDGE_EVENT_LOOP={$original}");
    }
}

it('refuses an event loop driver the workers could not start on', function () {
    config(['torque.event_loop' => 'uv']);

    torque_with_event_loop_env(null, fn () => $this->artisan('torque:start', ['--queues' => 'default'])
        ->assertFailed()
        ->expectsOutputToContain('Unusable event loop driver from torque.event_loop'));
});

it('judges FLEDGE_EVENT_LOOP from the process environment over the configured driver', function () {
    config(['torque.event_loop' => 'select']);

    torque_with_event_loop_env('uv', fn () => $this->artisan('torque:start', ['--queues' => 'default'])
        ->assertFailed()
        ->expectsOutputToContain('Unusable event loop driver from FLEDGE_EVENT_LOOP from the process environment'));
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

it('rejects --replace combined with --takeover', function () {
    $exit = Artisan::call('torque:start', ['--replace' => true, '--takeover' => 12345]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('mutually exclusive');
});

/*
 * Node block tests. An unusable event loop driver makes the command exit
 * after it printed the resolved fleet shape and before any fork.
 */

function torque_start_node_config(array $nodes, array $extra = []): void
{
    NodeName::resetForTesting();
    config([
        'torque.event_loop' => 'uv',
        'torque.workers' => 4,
        'torque.slots_per_worker' => 50,
        'torque.streams' => [
            'default' => ['priority' => 0],
            'emails' => ['priority' => 0],
            'imports' => ['priority' => 0],
        ],
        'torque.nodes' => $nodes,
        ...$extra,
    ]);
}

function torque_start_without_loop_env(array $options): string
{
    $output = '';

    torque_with_event_loop_env(null, function () use ($options, &$output) {
        Artisan::call('torque:start', $options);
        $output = Artisan::output();
    });

    return $output;
}

afterEach(function () {
    NodeName::resetForTesting();
});

it('applies the node block over the flat keys', function () {
    torque_start_node_config(['web-1' => ['queues' => ['emails'], 'workers' => 2, 'slots_per_worker' => 7]]);

    $output = torque_start_without_loop_env(['--node' => 'web-1']);

    expect($output)->toContain('starting with 2 workers x 7 coroutines')
        ->and($output)->toContain('Queues: emails');
});

it('lets CLI options win over the node block', function () {
    torque_start_node_config(['web-1' => ['queues' => ['emails'], 'workers' => 2, 'slots_per_worker' => 7]]);

    $output = torque_start_without_loop_env([
        '--node' => 'web-1', '--workers' => 3, '--concurrency' => 9, '--queues' => 'imports',
    ]);

    expect($output)->toContain('starting with 3 workers x 9 coroutines')
        ->and($output)->toContain('Queues: imports');
});

it('keeps the flat keys without a matching node block', function () {
    torque_start_node_config(['web-1' => ['workers' => 2]]);

    $output = torque_start_without_loop_env(['--node' => 'other']);

    expect($output)->toContain('starting with 4 workers x 50 coroutines');
});

it('matches a node block by pattern', function () {
    torque_start_node_config(['web-*' => ['workers' => 2, 'queues' => ['default', 'emails']]]);

    $output = torque_start_without_loop_env(['--node' => 'web-42']);

    expect($output)->toContain('starting with 2 workers')
        ->and($output)->toContain('Queues: default, emails');
});

it('refuses an invalid node name before anything starts', function () {
    torque_start_node_config([]);

    $exit = Artisan::call('torque:start', ['--node' => 'bad node']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->not->toContain('starting with');
});

it('refuses node block queues that are not streams', function () {
    torque_start_node_config(['web-1' => ['queues' => ['nope']]]);

    $exit = Artisan::call('torque:start', ['--node' => 'web-1']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->not->toContain('starting with');
});

it('accepts a well-formed --queues value that is not a streams key', function () {
    torque_start_node_config([]);
    config(['torque.streams' => ['default' => ['priority' => 0]]]);

    $output = torque_start_without_loop_env(['--queues' => 'emails,notifications']);

    expect($output)->not->toContain('Unknown queue')
        ->and($output)->not->toContain('Invalid queue name')
        ->and($output)->toContain('Queues: emails, notifications');
});

it('still refuses a malformed --queues name', function () {
    torque_start_node_config([]);

    $exit = Artisan::call('torque:start', ['--queues' => 'emails,bad name']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Invalid queue name')
        ->and(Artisan::output())->not->toContain('starting with');
});

function torque_start_two_groups(): array
{
    return ['web-1' => ['groups' => [
        'imports' => ['queues' => ['imports'], 'workers' => 2, 'slots_per_worker' => 10],
        'mail' => ['queues' => ['emails', 'default'], 'workers' => 3, 'slots_per_worker' => 20],
    ]]];
}

it('replaces configured groups with one default group on --queues', function () {
    torque_start_node_config(torque_start_two_groups());

    $output = torque_start_without_loop_env(['--node' => 'web-1', '--queues' => 'emails']);

    expect($output)->toContain('replaces the configured worker groups (imports, mail)')
        ->and($output)->toContain('Queues: emails')
        ->and($output)->not->toContain('Group imports');
});

it('refuses --workers with several groups', function () {
    torque_start_node_config(torque_start_two_groups());

    $exit = Artisan::call('torque:start', ['--node' => 'web-1', '--workers' => 5]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('cannot be used with several worker groups (imports, mail)')
        ->and($output)->not->toContain('starting with');
});

it('refuses --concurrency with several groups', function () {
    torque_start_node_config(torque_start_two_groups());

    $exit = Artisan::call('torque:start', ['--node' => 'web-1', '--concurrency' => 5]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('cannot be used with several worker groups (imports, mail)')
        ->and($output)->not->toContain('starting with');
});

it('applies --workers to the only configured group', function () {
    torque_start_node_config(['web-1' => ['groups' => [
        'mail' => ['queues' => ['emails'], 'workers' => 3, 'slots_per_worker' => 20],
    ]]]);

    $output = torque_start_without_loop_env(['--node' => 'web-1', '--workers' => 6]);

    expect($output)->toContain('Group mail: queues emails; 6 workers x 20 coroutines (120 slots)');
});

it('lists the groups in the fleet shape output', function () {
    torque_start_node_config(torque_start_two_groups());

    $output = torque_start_without_loop_env(['--node' => 'web-1']);

    expect($output)->toContain('Group imports: queues imports; 2 workers x 10 coroutines (20 slots)')
        ->and($output)->toContain('Group mail: queues emails, default; 3 workers x 20 coroutines (60 slots)')
        ->and($output)->toContain('starting with 5 workers x 20 coroutines');
});

it('keeps the fleet shape output unchanged without groups', function () {
    torque_start_node_config(['web-1' => ['workers' => 2]]);

    $output = torque_start_without_loop_env(['--node' => 'web-1']);

    expect($output)->toContain('starting with 2 workers x 50 coroutines')
        ->and($output)->not->toContain('Group ')
        ->and($output)->not->toContain('replaces the configured');
});

/*
 * The --replace proceed-paths are pinned via the queue-name validation that
 * runs after the replace/takeover gate but before any fork: an invalid
 * queue makes the command exit early while the output proves which branch
 * the gate took.
 */

it('absorbs a live master with --replace instead of refusing', function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Requires posix.');
    }

    $master = spawnFakeTorqueMaster();
    file_put_contents(MasterProcess::pidFilePath(), (string) $master);
    usleep(150_000);

    try {
        $exit = Artisan::call('torque:start', ['--replace' => true, '--queues' => 'in valid']);
        $output = Artisan::output();

        expect($output)->toContain('absorbing it via takeover handshake')
            ->and($output)->not->toContain('already running')
            ->and($output)->toContain('Invalid queue name')
            ->and($exit)->toBe(1)
            // The gate must not have touched the live master.
            ->and(posix_kill($master, 0))->toBeTrue();
    } finally {
        posix_kill($master, SIGKILL);
        pcntl_waitpid($master, $status);
        @unlink(MasterProcess::pidFilePath());
    }
});

it('starts normally with --replace when the PID file is stale', function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Requires posix.');
    }

    // A dead PID fails readPid's identity check: --replace must fall through
    // to a normal start, not a takeover.
    $master = spawnFakeTorqueMaster(1);
    posix_kill($master, SIGKILL);
    pcntl_waitpid($master, $status);
    file_put_contents(MasterProcess::pidFilePath(), (string) $master);

    try {
        $exit = Artisan::call('torque:start', ['--replace' => true, '--queues' => 'in valid']);
        $output = Artisan::output();

        expect($output)->not->toContain('absorbing it via takeover handshake')
            ->and($output)->not->toContain('already running')
            ->and($output)->toContain('Invalid queue name')
            ->and($exit)->toBe(1);
    } finally {
        @unlink(MasterProcess::pidFilePath());
    }
});
