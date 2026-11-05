<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\RestartProbeJob;

/*
|--------------------------------------------------------------------------
| queue:restart Integration Tests
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/restart-worker.php) runs on a file cache
| store; this process writes the `illuminate:queue:restart` key the way
| `php artisan queue:restart` does and watches what the worker does.
|
| Requires a running Redis instance.
|
*/

/**
 * Start a worker, run the scenario against it, and clean up.
 *
 * @param  list<object>  $jobs
 * @param  Closure(array{process: resource, cache: Repository, output: string, log: string, redis: mixed, streamKey: string, group: string}): mixed  $scenario
 */
function torque_with_restart_worker(array $jobs, bool $restartable, Closure $scenario): mixed
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-restart-{$token}:";
    $group = 'restart-test';
    $queueName = 'restart';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-restart-{$token}.jsonl";
    $outputFile = "{$directory}/torque-restart-{$token}.out";
    $cacheDir = "{$directory}/torque-restart-{$token}-cache";
    mkdir($cacheDir);

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

    foreach ($jobs as $job) {
        (void) $queue->push($job, '', $queueName);
    }

    $process = null;

    try {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/restart-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, $cacheDir, $restartable ? '1' : '0'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        // The worker records the restart timestamp before this line is printed.
        torque_wait_until(static fn (): bool => str_contains((string) @file_get_contents($outputFile), 'Setup complete'), 20, 'the worker to start', $outputFile);

        return $scenario([
            'process' => $process,
            'cache' => new Repository(new FileStore(new Filesystem, $cacheDir)),
            'output' => $outputFile,
            'log' => $logFile,
            'redis' => $redis,
            'streamKey' => $queue->getStreamKey(),
            'group' => $group,
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

function torque_wait_until(Closure $condition, int $seconds, string $what, ?string $outputFile = null): void
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
 * Whether the worker process exited within the given seconds, and its exit code.
 *
 * @param  resource  $process
 * @return array{exited: bool, code: int}
 */
function torque_exit_of($process, int $seconds): array
{
    $deadline = microtime(true) + $seconds;
    $code = -1;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);

        if (! $status['running']) {
            // proc_get_status reports the real exit code once, on the first call after exit.
            $code = $status['exitcode'];

            return ['exited' => true, 'code' => $code];
        }

        usleep(100_000);
    }

    return ['exited' => false, 'code' => $code];
}

/**
 * @return list<string>
 */
function torque_restart_events(string $logFile): array
{
    $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_map(static fn (string $line): string => json_decode($line, true, flags: JSON_THROW_ON_ERROR)['event'], $lines);
}

it('exits an idle worker after the queue:restart key changes', function () {
    torque_with_restart_worker([], restartable: true, scenario: function (array $run): void {
        $run['cache']->forever('illuminate:queue:restart', time());

        $result = torque_exit_of($run['process'], 15);

        expect($result['exited'])->toBeTrue()
            ->and($result['code'])->toBe(0)
            ->and((string) file_get_contents($run['output']))->toContain('queue:restart');
    });
});

it('lets an in-flight job finish and acknowledge before the worker exits', function () {
    torque_with_restart_worker([new RestartProbeJob(1.0)], restartable: true, scenario: function (array $run): void {
        torque_wait_until(static fn (): bool => torque_restart_events($run['log']) === ['start'], 20, 'the job to start', $run['output']);

        $run['cache']->forever('illuminate:queue:restart', time());

        $result = torque_exit_of($run['process'], 20);

        expect($result['exited'])->toBeTrue()
            ->and($result['code'])->toBe(0)
            ->and(torque_restart_events($run['log']))->toBe(['start', 'done']);

        $pending = $run['redis']->execute('XPENDING', $run['streamKey'], $run['group']);

        expect((int) $pending[0])->toBe(0);
    });
});

it('keeps running when the worker is not restartable', function () {
    torque_with_restart_worker([], restartable: false, scenario: function (array $run): void {
        $run['cache']->forever('illuminate:queue:restart', time());

        // Five check intervals at the fixture's 0.2s: a restartable worker is gone well within that.
        expect(torque_exit_of($run['process'], 1)['exited'])->toBeFalse();
    });
});
