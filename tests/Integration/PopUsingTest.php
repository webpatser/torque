<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\JobEventsProbeJob;
use Webpatser\Torque\Tests\Fixtures\RedeliveryProbeJob;

/*
|--------------------------------------------------------------------------
| Queue::popUsing
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/pop-using-worker.php) with a callback
| registered under the worker name 'torque'. The test steers the callback
| through the file `pop-mode` in the cache directory.
|
| Requires a running Redis instance.
|
*/

/**
 * @param  array<string, list<object>>  $jobsByQueue
 * @param  array<string, mixed>  $options  mode, concurrency, max_concurrency, strict_attempts, retry_after, and
 *                                         dead_consumer: a consumer that died holding every entry just pushed
 * @param  Closure(array{process: resource, cacheDir: string, output: string, log: string, prefix: string}): mixed  $scenario
 */
function torque_with_pop_worker(array $jobsByQueue, array $options, Closure $scenario): mixed
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-pop-{$token}:";
    $group = 'pop-test';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-pop-{$token}.jsonl";
    $outputFile = "{$directory}/torque-pop-{$token}.out";
    $cacheDir = "{$directory}/torque-pop-{$token}-cache";
    mkdir($cacheDir);

    $process = null;

    try {
        foreach ($jobsByQueue as $queueName => $jobs) {
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
            $queue->ensureConsumerGroup($queue->getStreamKey($queueName), $group);

            foreach ($jobs as $job) {
                (void) $queue->push($job, '', $queueName);
            }

            if ($options['dead_consumer'] ?? false) {
                $redis->execute('XREADGROUP', 'GROUP', $group, 'dead-consumer', 'STREAMS', $queue->getStreamKey($queueName), '>');
            }
        }

        if ($options['dead_consumer'] ?? false) {
            usleep(((int) ($options['retry_after'] ?? 90)) * 1_000_000);
        }

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/pop-using-worker.php', torqueRedisUri(), $prefix, $group, implode(',', array_keys($jobsByQueue)), $logFile, $cacheDir, json_encode($options, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        torque_pop_wait_until(static fn (): bool => str_contains((string) @file_get_contents($outputFile), 'Setup complete'), 20, 'the worker to start', $outputFile);

        return $scenario(['process' => $process, 'cacheDir' => $cacheDir, 'output' => $outputFile, 'log' => $logFile, 'prefix' => $prefix]);
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

function torque_pop_wait_until(Closure $condition, int $seconds, string $what, ?string $outputFile = null): void
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
 * @return list<string>
 */
function torque_pop_handled(string $logFile): array
{
    $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);

    return array_values(array_column(array_filter($rows, static fn (array $row): bool => $row['event'] === 'JobHandle'), 'tag'));
}

function torque_pop_callbacks(string $logFile): int
{
    return substr_count((string) @file_get_contents($logFile), '"event":"Callback"');
}

function torque_pop_mode(array $run, string $mode): void
{
    file_put_contents($run['cacheDir'].'/pop-mode', $mode);
}

function torque_pop_runs(string $logFile): int
{
    return substr_count((string) @file_get_contents($logFile), '"type":"run"');
}

describe('under strict_attempts', function () {
    it('does not charge a job the callback held back', function () {
        torque_with_pop_worker(['events' => [new RedeliveryProbeJob(tries: 1)]], ['mode' => 'drop', 'strict_attempts' => true], function (array $run): void {
            $redis = torqueRedis();
            $polls = torque_pop_callbacks($run['log']);
            torque_pop_wait_until(fn (): bool => torque_pop_callbacks($run['log']) >= $polls + 3, 10, 'the callback to be asked again', $run['output']);

            torque_pop_mode($run, 'pass');

            torque_pop_wait_until(fn (): bool => torque_pop_runs($run['log']) === 1, 5, 'the held job to run', $run['output']);

            expect((int) $redis->execute('XLEN', $run['prefix'].'dead-letter'))->toBe(0)
                ->and((int) $redis->execute('EXISTS', $run['prefix'].'events:redeliveries'))->toBe(0);
        });
    });

    it('still charges a job a dead consumer had, when the callback held it back first', function () {
        torque_with_pop_worker(
            ['events' => [new RedeliveryProbeJob(tries: 1)]],
            ['mode' => 'drop', 'strict_attempts' => true, 'retry_after' => 1, 'dead_consumer' => true],
            function (array $run): void {
                $redis = torqueRedis();
                $polls = torque_pop_callbacks($run['log']);
                torque_pop_wait_until(fn (): bool => torque_pop_callbacks($run['log']) >= $polls + 3, 10, 'the callback to be asked again', $run['output']);

                torque_pop_mode($run, 'pass');

                torque_pop_wait_until(fn (): bool => (int) $redis->execute('XLEN', $run['prefix'].'dead-letter') === 1, 5, 'the dead letter', $run['output']);

                expect(torque_pop_runs($run['log']))->toBe(0);
            },
        );
    });

    it('returns a job it held back when the worker drains', function () {
        torque_with_pop_worker(['events' => [new RedeliveryProbeJob(tries: 1)]], ['mode' => 'drop', 'strict_attempts' => true], function (array $run): void {
            $redis = torqueRedis();
            $stream = $run['prefix'].'events';
            $original = (string) $redis->execute('XRANGE', $stream, '-', '+')[0][0];
            $polls = torque_pop_callbacks($run['log']);
            torque_pop_wait_until(fn (): bool => torque_pop_callbacks($run['log']) >= $polls + 3, 10, 'the callback to be asked again', $run['output']);

            proc_terminate($run['process'], SIGTERM);
            torque_pop_wait_until(fn (): bool => ! proc_get_status($run['process'])['running'], 10, 'the worker to exit', $run['output']);

            $entries = $redis->execute('XRANGE', $stream, '-', '+');

            expect($entries)->toHaveCount(1)
                ->and((string) $entries[0][0])->not->toBe($original)
                ->and((int) ($redis->execute('XPENDING', $stream, 'pop-test')[0] ?? 0))->toBe(0);
        });
    });
});

it('runs the job a callback returns from the closure', function () {
    torque_with_pop_worker(['events' => [new JobEventsProbeJob('a')]], ['mode' => 'pass'], function (array $run): void {
        torque_pop_wait_until(fn (): bool => torque_pop_handled($run['log']) === ['a'], 15, 'the job to run', $run['output']);

        expect(torque_pop_callbacks($run['log']))->toBeGreaterThanOrEqual(1);
    });
});

it('holds a job back while the callback returns null, then runs it', function (string $mode) {
    torque_with_pop_worker(['events' => [new JobEventsProbeJob('a')]], ['mode' => $mode], function (array $run) use ($mode): void {
        $polls = torque_pop_callbacks($run['log']);
        torque_pop_wait_until(fn (): bool => torque_pop_callbacks($run['log']) >= $polls + 5, 10, 'the callback to be asked again', $run['output']);

        expect(torque_pop_handled($run['log']))->toBe([], "mode {$mode} ran the job");

        torque_pop_mode($run, 'pass');

        torque_pop_wait_until(fn (): bool => torque_pop_handled($run['log']) === ['a'], 15, 'the held job to run', $run['output']);
    });
})->with(['without reading' => 'null', 'after reading' => 'drop']);

it('hands out the job of the queue the callback picks', function () {
    torque_with_pop_worker(
        ['first' => [new JobEventsProbeJob('from-first')], 'second' => [new JobEventsProbeJob('from-second')]],
        ['mode' => 'other:second'],
        function (array $run): void {
            torque_pop_wait_until(fn (): bool => torque_pop_handled($run['log']) === ['from-second'], 15, 'the second queue to run', $run['output']);

            usleep(1_500_000);

            expect(torque_pop_handled($run['log']))->toBe(['from-second']);

            torque_pop_mode($run, 'pass');

            torque_pop_wait_until(fn (): bool => torque_pop_handled($run['log']) === ['from-second', 'from-first'], 15, 'the first queue to run', $run['output']);
        },
    );
});

it('keeps the slot alive when the callback throws', function () {
    torque_with_pop_worker(['events' => [new JobEventsProbeJob('a')]], ['mode' => 'throw'], function (array $run): void {
        torque_pop_wait_until(fn (): bool => torque_pop_callbacks($run['log']) >= 2, 15, 'the callback to be asked again', $run['output']);

        expect(proc_get_status($run['process'])['running'])->toBeTrue()
            ->and(torque_pop_handled($run['log']))->toBe([])
            ->and(file_get_contents($run['output']))->toContain('pop callback failed');

        torque_pop_mode($run, 'pass');

        torque_pop_wait_until(fn (): bool => torque_pop_handled($run['log']) === ['a'], 15, 'the slot to run the job', $run['output']);
    });
});

it('skips a job that did not come from the closure with a warning', function () {
    torque_with_pop_worker(['events' => [new JobEventsProbeJob('a')]], ['mode' => 'foreign'], function (array $run): void {
        torque_pop_wait_until(fn (): bool => str_contains((string) @file_get_contents($run['output']), 'WARN'), 15, 'the warning', $run['output']);

        expect(proc_get_status($run['process'])['running'])->toBeTrue()
            ->and(torque_pop_handled($run['log']))->toBe([]);

        torque_pop_mode($run, 'pass');

        torque_pop_wait_until(fn (): bool => torque_pop_handled($run['log']) === ['a'], 15, 'the slot to run the job', $run['output']);
    });
});
