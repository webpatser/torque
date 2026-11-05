<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\WorkerStopReason;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\MaintenanceProbeJob;

/*
|--------------------------------------------------------------------------
| Memory limit and maintenance mode Integration Tests
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/maintenance-worker.php) keeps maintenance
| mode in a file cache store; this process switches it the way `artisan down`
| and `artisan up` do with the cache driver, and watches what the worker does.
|
| Requires a running Redis instance.
|
*/

const TORQUE_MAINT_KEY = 'illuminate:foundation:down';

/**
 * Start a worker, run the scenario against it, and clean up.
 *
 * @param  array<string, mixed>  $options
 * @param  Closure(array{process: resource, cache: Repository, output: string, log: string, redis: mixed, streamKey: string, group: string, queue: StreamQueue}): mixed  $scenario
 */
function torque_with_maintenance_worker(array $options, Closure $scenario, bool $startDown = false): mixed
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-maint-{$token}:";
    $group = 'maint-test';
    $queueName = 'maint';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-maint-{$token}.jsonl";
    $outputFile = "{$directory}/torque-maint-{$token}.out";
    $cacheDir = "{$directory}/torque-maint-{$token}-cache";
    mkdir($cacheDir);

    $cache = new Repository(new FileStore(new Filesystem, $cacheDir));

    if ($startDown) {
        $cache->forever(TORQUE_MAINT_KEY, ['time' => time()]);
    }

    $queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: $queueName,
        retryAfter: 90,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: $group,
    );
    $queue->setContainer(app());
    $queue->setConnectionName('torque');
    $queue->ensureConsumerGroup($queue->getStreamKey(), $group);

    $process = null;

    try {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/maintenance-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, $cacheDir, json_encode($options, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        torque_maint_wait_until(static fn (): bool => str_contains((string) @file_get_contents($outputFile), 'Setup complete'), 20, 'the worker to start', $outputFile);

        return $scenario([
            'process' => $process,
            'cache' => $cache,
            'output' => $outputFile,
            'log' => $logFile,
            'redis' => $redis,
            'streamKey' => $queue->getStreamKey(),
            'group' => $group,
            'queue' => $queue,
        ]);
    } finally {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }

            proc_close($process);
        }

        (new Filesystem)->deleteDirectory($cacheDir);
        @unlink($logFile);
        @unlink($outputFile);

        $cursor = '0';

        do {
            [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');
            $cursor = (string) $cursor;

            foreach ($keys ?? [] as $key) {
                $redis->execute('DEL', (string) $key);
            }
        } while ($cursor !== '0');
    }
}

function torque_maint_wait_until(Closure $condition, int $seconds, string $what, ?string $outputFile = null): void
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if ($condition()) {
            return;
        }

        usleep(100_000);
    }

    throw new RuntimeException("Timed out waiting for {$what}.".($outputFile !== null ? "\n".@file_get_contents($outputFile) : ''));
}

/**
 * @param  resource  $process
 * @return array{exited: bool, code: int}
 */
function torque_maint_exit_of($process, int $seconds): array
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);

        if (! $status['running']) {
            return ['exited' => true, 'code' => $status['exitcode']];
        }

        usleep(100_000);
    }

    return ['exited' => false, 'code' => -1];
}

/**
 * @return list<array<string, mixed>>
 */
function torque_maint_log(string $logFile): array
{
    $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
}

/**
 * @return list<string>
 */
function torque_maint_events(string $logFile): array
{
    return array_column(torque_maint_log($logFile), 'event');
}

it('exits with status 12 after finishing its job once the memory limit is exceeded', function () {
    torque_with_maintenance_worker(['memory_headroom_mb' => 20], function (array $run): void {
        (void) $run['queue']->push(new MaintenanceProbeJob(retainMegabytes: 64), '', 'maint');

        $result = torque_maint_exit_of($run['process'], 30);
        $log = torque_maint_log($run['log']);

        expect($result['exited'])->toBeTrue()
            ->and($result['code'])->toBe(12)
            ->and(array_column($log, 'event'))->toBe(['start', 'done', 'WorkerStopping'])
            ->and($log[2]['status'])->toBe(12)
            ->and($log[2]['reason'])->toBe(WorkerStopReason::MaxMemoryExceeded->value);

        $pending = $run['redis']->execute('XPENDING', $run['streamKey'], $run['group']);

        expect((int) $pending[0])->toBe(0);
    });
});

it('waits past the grace for a running job over the memory limit, and claims no other', function () {
    torque_with_maintenance_worker(['memory_headroom_mb' => 20, 'grace' => 1, 'concurrency' => 2], function (array $run): void {
        (void) $run['queue']->push(new MaintenanceProbeJob(retainMegabytes: 64, holdSeconds: 3.5), '', 'maint');

        torque_maint_wait_until(static fn (): bool => str_contains((string) file_get_contents($run['output']), 'Memory limit of'), 20, 'the memory stop', $run['output']);

        // Past any read that was already on the wire: the idle slot must not take this one.
        usleep(300_000);
        (void) $run['queue']->push(new MaintenanceProbeJob, '', 'maint');

        $result = torque_maint_exit_of($run['process'], 20);
        $log = torque_maint_log($run['log']);
        $pending = $run['redis']->execute('XPENDING', $run['streamKey'], $run['group']);

        expect($result)->toBe(['exited' => true, 'code' => 12])
            ->and(array_column($log, 'event'))->toBe(['start', 'done', 'WorkerStopping'])
            ->and((string) file_get_contents($run['output']))->not->toContain('forcing exit')
            ->and((int) $pending[0])->toBe(0)
            ->and((int) $run['redis']->execute('XLEN', $run['streamKey']))->toBe(1);
    });
});

it('applies the grace to another stop reason arriving after a memory stop', function (Closure $stop) {
    torque_with_maintenance_worker(['memory_headroom_mb' => 20, 'grace' => 1], function (array $run) use ($stop): void {
        (void) $run['queue']->push(new MaintenanceProbeJob(retainMegabytes: 64, holdSeconds: 60), '', 'maint');

        torque_maint_wait_until(static fn (): bool => str_contains((string) file_get_contents($run['output']), 'Memory limit of'), 20, 'the memory stop', $run['output']);

        // Past the grace, which does not apply yet.
        sleep(2);
        expect(proc_get_status($run['process'])['running'])->toBeTrue();

        $stop($run);

        $result = torque_maint_exit_of($run['process'], 10);
        $log = torque_maint_log($run['log']);

        expect($result)->toBe(['exited' => true, 'code' => 12])
            ->and(array_column($log, 'event'))->toBe(['start', 'WorkerStopping'])
            ->and($log[1]['reason'])->toBe(WorkerStopReason::MaxMemoryExceeded->value)
            ->and((string) file_get_contents($run['output']))->toContain('forcing exit');
    });
})->with([
    'signal' => [static fn (array $run) => posix_kill(proc_get_status($run['process'])['pid'], SIGTERM)],
    'queue:restart' => [static fn (array $run) => $run['cache']->forever('illuminate:queue:restart', time())],
]);

it('applies the grace when the lifetime ends during a memory stop', function () {
    torque_with_maintenance_worker(['memory_headroom_mb' => 20, 'grace' => 1, 'lifetime' => 6], function (array $run): void {
        (void) $run['queue']->push(new MaintenanceProbeJob(retainMegabytes: 64, holdSeconds: 60), '', 'maint');

        torque_maint_wait_until(static fn (): bool => str_contains((string) file_get_contents($run['output']), 'Memory limit of'), 5, 'the memory stop', $run['output']);

        sleep(2);
        expect(proc_get_status($run['process'])['running'])->toBeTrue();

        $result = torque_maint_exit_of($run['process'], 10);

        expect($result)->toBe(['exited' => true, 'code' => 12])
            ->and(array_column(torque_maint_log($run['log']), 'event'))->toBe(['start', 'WorkerStopping'])
            ->and((string) file_get_contents($run['output']))->toContain('forcing exit');
    });
});

it('does not poll or claim while the application is down, then resumes after it is up', function () {
    torque_with_maintenance_worker([], function (array $run): void {
        (void) $run['queue']->push(new MaintenanceProbeJob, '', 'maint');

        // Six check intervals at the fixture's 0.1s: a worker that ignores maintenance mode has run the job well within that.
        usleep(600_000);

        $pending = $run['redis']->execute('XPENDING', $run['streamKey'], $run['group']);

        expect(torque_maint_events($run['log']))->toBe([])
            ->and(proc_get_status($run['process'])['running'])->toBeTrue()
            ->and((int) $run['redis']->execute('XLEN', $run['streamKey']))->toBe(1)
            ->and((int) $pending[0])->toBe(0);

        $run['cache']->forget(TORQUE_MAINT_KEY);

        torque_maint_wait_until(static fn (): bool => torque_maint_events($run['log']) === ['start', 'done'], 15, 'the job to run after artisan up', $run['output']);
    }, startDown: true);
});

it('runs jobs while down when force is set', function () {
    torque_with_maintenance_worker(['force' => true], function (array $run): void {
        (void) $run['queue']->push(new MaintenanceProbeJob, '', 'maint');

        torque_maint_wait_until(static fn (): bool => torque_maint_events($run['log']) === ['start', 'done'], 15, 'the job to run despite maintenance mode', $run['output']);

        expect(torque_maint_events($run['log']))->toBe(['start', 'done']);
    }, startDown: true);
});

it('lets an in-flight job finish when the application goes down, and holds back the next one', function () {
    torque_with_maintenance_worker([], function (array $run): void {
        (void) $run['queue']->push(new MaintenanceProbeJob(seconds: 1.0), '', 'maint');

        torque_maint_wait_until(static fn (): bool => torque_maint_events($run['log']) === ['start'], 20, 'the job to start', $run['output']);

        $run['cache']->forever(TORQUE_MAINT_KEY, ['time' => time()]);
        (void) $run['queue']->push(new MaintenanceProbeJob, '', 'maint');

        torque_maint_wait_until(static fn (): bool => torque_maint_events($run['log']) === ['start', 'done'], 20, 'the in-flight job to finish', $run['output']);

        // Six check intervals at the fixture's 0.1s: the second job must not start while down.
        usleep(600_000);

        expect(torque_maint_events($run['log']))->toBe(['start', 'done'])
            ->and(proc_get_status($run['process'])['running'])->toBeTrue();

        $run['cache']->forget(TORQUE_MAINT_KEY);

        torque_maint_wait_until(static fn (): bool => torque_maint_events($run['log']) === ['start', 'done', 'start', 'done'], 15, 'the second job to run after artisan up', $run['output']);
    });
});
