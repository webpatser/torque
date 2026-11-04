<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\JobEventsProbeJob;
use Webpatser\Torque\Tests\Fixtures\RestartProbeJob;

/*
|--------------------------------------------------------------------------
| Worker lifecycle events
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/worker-events-worker.php) logs every
| WorkerStarting, WorkerStopping, WorkerIdle, WorkerQueuePaused and
| WorkerQueueResumed it dispatches. The test process steers it from outside:
| signals, the shared file cache, the circuit breaker key in Redis and two
| control files for the Looping listener.
|
| Requires a running Redis instance.
|
*/

/**
 * @param  list<object>  $jobs
 * @param  array<string, mixed>  $options  lifetime, max_jobs, circuit_breaker, concurrency, max_retries, job_events, delayed_wrongtype
 * @param  Closure(array{process: resource, cache: Repository, cacheDir: string, output: string, log: string, redis: mixed, prefix: string, queue: string}): mixed  $scenario
 */
function torque_with_events_worker(array $jobs, array $options, Closure $scenario, array $preCreate = []): mixed
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-events-{$token}:";
    $group = 'events-test';
    $queueName = 'events';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-events-{$token}.jsonl";
    $outputFile = "{$directory}/torque-events-{$token}.out";
    $cacheDir = "{$directory}/torque-events-{$token}-cache";
    mkdir($cacheDir);

    foreach ($preCreate as $file) {
        touch($cacheDir.'/'.$file);
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

    foreach ($jobs as $job) {
        (void) $queue->push($job, '', $queueName);
    }

    // Every delayed release now fails with WRONGTYPE, as in StreamQueueReleaseTest.
    if ($options['delayed_wrongtype'] ?? false) {
        $redis->execute('SET', $queue->getStreamKey($queueName).':delayed', 'not-a-sorted-set');
    }

    $process = null;

    try {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/worker-events-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, $cacheDir, json_encode($options, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        torque_events_wait_until(static fn (): bool => str_contains((string) @file_get_contents($outputFile), 'Setup complete'), 20, 'the worker to start', $outputFile);

        return $scenario([
            'process' => $process,
            'cache' => new Repository(new FileStore(new Filesystem, $cacheDir)),
            'cacheDir' => $cacheDir,
            'output' => $outputFile,
            'log' => $logFile,
            'redis' => $redis,
            'prefix' => $prefix,
            'queue' => $queueName,
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

function torque_events_wait_until(Closure $condition, int $seconds, string $what, ?string $outputFile = null): void
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
 * @return list<array<string, mixed>>
 */
function torque_event_rows(string $logFile): array
{
    $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
}

/**
 * @return list<string>
 */
function torque_event_names(string $logFile): array
{
    return array_column(torque_event_rows($logFile), 'event');
}

/**
 * Wait for the worker to exit and return its code, or -1 when it did not.
 *
 * @param  resource  $process
 */
function torque_events_exit_code($process, int $seconds = 20): int
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);

        if (! $status['running']) {
            return $status['exitcode'];
        }

        usleep(100_000);
    }

    return -1;
}

/**
 * @return array<string, mixed>|null
 */
function torque_stopping_row(string $logFile): ?array
{
    foreach (torque_event_rows($logFile) as $row) {
        if ($row['event'] === 'WorkerStopping') {
            return $row;
        }
    }

    return null;
}

it('logs starting first, a throttled idle in between and one stopping last', function () {
    torque_with_events_worker([], [], function (array $run): void {
        // Slots poll every 100ms: unthrottled, four seconds of idling is ~40 events.
        sleep(4);

        posix_kill(proc_get_status($run['process'])['pid'], SIGTERM);

        expect(torque_events_exit_code($run['process']))->toBe(0);

        $names = torque_event_names($run['log']);
        $idle = count(array_filter($names, static fn (string $name): bool => $name === 'WorkerIdle'));

        expect($names[0])->toBe('WorkerStarting')
            ->and($names[array_key_last($names)])->toBe('WorkerStopping')
            ->and(array_count_values($names)['WorkerStarting'])->toBe(1)
            ->and(array_count_values($names)['WorkerStopping'])->toBe(1)
            ->and($idle)->toBeGreaterThanOrEqual(1)
            ->and($idle)->toBeLessThanOrEqual(6);

        $rows = torque_event_rows($run['log']);

        expect($rows[0]['options'])->toBeTrue()
            ->and($rows[0]['queue'])->toBe('events')
            ->and(torque_stopping_row($run['log'])['options'])->toBeTrue();
    });
});

it('stops with the reason of the signal', function () {
    torque_with_events_worker([], [], function (array $run): void {
        posix_kill(proc_get_status($run['process'])['pid'], SIGTERM);

        expect(torque_events_exit_code($run['process']))->toBe(0)
            ->and(torque_stopping_row($run['log']))->toMatchArray(['status' => 0, 'reason' => 'interrupted']);
    });
});

it('stops with the restart reason when queue:restart changes', function () {
    torque_with_events_worker([], [], function (array $run): void {
        $run['cache']->forever('illuminate:queue:restart', time());

        expect(torque_events_exit_code($run['process']))->toBe(0)
            ->and(torque_stopping_row($run['log']))->toMatchArray(['status' => 0, 'reason' => 'restart_signal']);
    });
});

it('stops with the max jobs reason after the job limit', function () {
    torque_with_events_worker([new RestartProbeJob(0.1)], ['max_jobs' => 1], function (array $run): void {
        expect(torque_events_exit_code($run['process']))->toBe(0)
            ->and(torque_stopping_row($run['log']))->toMatchArray(['status' => 0, 'reason' => 'max_jobs', 'jobs' => 1]);
    });
});

it('stops with the max time reason after the lifetime', function () {
    torque_with_events_worker([], ['lifetime' => 2], function (array $run): void {
        expect(torque_events_exit_code($run['process']))->toBe(0)
            ->and(torque_stopping_row($run['log']))->toMatchArray(['status' => 0, 'reason' => 'max_time']);
    });
});

it('announces stopping once when two stop reasons race', function () {
    // The job holds the drain open past the next restart check (every 2s) and
    // several drain ticks: none of them may repeat the event or change its reason.
    torque_with_events_worker([new RestartProbeJob(3.0)], [], function (array $run): void {
        torque_events_wait_until(static fn (): bool => in_array('start', torque_event_names($run['log']), true), 15, 'the job to start', $run['output']);

        posix_kill(proc_get_status($run['process'])['pid'], SIGTERM);
        $run['cache']->forever('illuminate:queue:restart', time());

        expect(torque_events_exit_code($run['process']))->toBe(0);

        $counts = array_count_values(torque_event_names($run['log']));

        expect($counts['done'])->toBe(1)
            ->and($counts['WorkerStopping'])->toBe(1)
            ->and(torque_stopping_row($run['log'])['reason'])->toBe('interrupted');
    });
});

it('announces stopping after the drain, never while a job runs', function () {
    torque_with_events_worker([new RestartProbeJob(2.5)], [], function (array $run): void {
        torque_events_wait_until(static fn (): bool => in_array('start', torque_event_names($run['log']), true), 15, 'the job to start', $run['output']);

        posix_kill(proc_get_status($run['process'])['pid'], SIGTERM);

        expect(torque_events_exit_code($run['process']))->toBe(0);

        $names = torque_event_names($run['log']);

        expect(array_search('WorkerStopping', $names, true))->toBeGreaterThan(array_search('done', $names, true))
            ->and(torque_stopping_row($run['log'])['jobs'])->toBe(1);
    });
});

it('announces stopping at the forced exit when a job outlasts the grace', function () {
    torque_with_events_worker([new RestartProbeJob(30.0)], ['grace' => 3], function (array $run): void {
        torque_events_wait_until(static fn (): bool => in_array('start', torque_event_names($run['log']), true), 15, 'the job to start', $run['output']);

        $signalled = microtime(true);
        posix_kill(proc_get_status($run['process'])['pid'], SIGTERM);

        expect(torque_events_exit_code($run['process']))->toBe(0);

        $counts = array_count_values(torque_event_names($run['log']));

        expect($counts['WorkerStopping'])->toBe(1)
            ->and($counts)->not->toHaveKey('done')
            ->and(torque_stopping_row($run['log'])['at'] - $signalled)->toBeGreaterThan(1.5);
    });
});

it('lets a suspending WorkerStopping listener finish, and runs it once', function () {
    // The listener outlasts several drain ticks, each of which could exit or announce again.
    torque_with_events_worker([], ['stopping_delay' => 2.5], function (array $run): void {
        posix_kill(proc_get_status($run['process'])['pid'], SIGTERM);

        expect(torque_events_exit_code($run['process']))->toBe(0);

        $counts = array_count_values(torque_event_names($run['log']));

        expect($counts['WorkerStopping'])->toBe(1)
            ->and($counts)->toHaveKey('WorkerStoppingDone');
    });
});

it('reports memoryUsage in megabytes', function () {
    torque_with_events_worker([], [], function (array $run): void {
        posix_kill(proc_get_status($run['process'])['pid'], SIGTERM);

        expect(torque_events_exit_code($run['process']))->toBe(0)
            ->and(torque_stopping_row($run['log'])['memory'])->toBeGreaterThan(1)->toBeLessThan(4096);
    });
});

it('carries the real settings in the worker options', function () {
    torque_with_events_worker([], ['memory_limit' => 2048, 'force' => true, 'max_retries' => null], function (array $run): void {
        posix_kill(proc_get_status($run['process'])['pid'], SIGTERM);

        expect(torque_events_exit_code($run['process']))->toBe(0)
            ->and(torque_event_rows($run['log'])[0])->toMatchArray(['event' => 'WorkerStarting', 'memory' => 2048, 'force' => true, 'maxTries' => 3]);
    });
});

it('dispatches one event for a paused queue and one when it resumes', function () {
    torque_with_events_worker([], [], function (array $run): void {
        $key = 'illuminate:queue:paused:torque:events';

        $run['cache']->forever($key, true);
        torque_events_wait_until(static fn (): bool => in_array('WorkerQueuePaused', torque_event_names($run['log']), true), 15, 'the pause event', $run['output']);

        // Two more timer ticks, which must not repeat the event.
        sleep(5);

        $run['cache']->forget($key);
        torque_events_wait_until(static fn (): bool => in_array('WorkerQueueResumed', torque_event_names($run['log']), true), 15, 'the resume event', $run['output']);

        sleep(5);

        $counts = array_count_values(torque_event_names($run['log']));
        $rows = array_values(array_filter(torque_event_rows($run['log']), static fn (array $row): bool => str_starts_with($row['event'], 'WorkerQueue')));

        expect($counts['WorkerQueuePaused'])->toBe(1)
            ->and($counts['WorkerQueueResumed'])->toBe(1)
            ->and(array_column($rows, 'event'))->toBe(['WorkerQueuePaused', 'WorkerQueueResumed'])
            ->and($rows[0]['queue'])->toBe('events');
    });
});

it('dispatches no pause event for a circuit breaker pause', function () {
    torque_with_events_worker([], ['circuit_breaker' => ['enabled' => true]], function (array $run): void {
        $run['redis']->execute('SET', $run['prefix'].'cb:events:state', 'open', 'EX', '60');

        sleep(5);

        expect(array_intersect(torque_event_names($run['log']), ['WorkerQueuePaused', 'WorkerQueueResumed']))->toBe([]);

        // Control: a real queue:pause on top of the open breaker is announced, once.
        $run['cache']->forever('illuminate:queue:paused:torque:events', true);
        torque_events_wait_until(static fn (): bool => in_array('WorkerQueuePaused', torque_event_names($run['log']), true), 15, 'the pause event', $run['output']);

        sleep(3);

        expect(array_count_values(torque_event_names($run['log']))['WorkerQueuePaused'])->toBe(1);
    });
});

it('skips polling while a Looping listener returns false', function () {
    torque_with_events_worker([new RestartProbeJob(0.1)], [], function (array $run): void {
        sleep(3);

        $pending = $run['redis']->execute('XPENDING', $run['prefix'].$run['queue'], 'events-test');

        expect(torque_event_names($run['log']))->not->toContain('JobProcessed')
            ->and((int) $pending[0])->toBe(0);

        unlink($run['cacheDir'].'/looping-false');

        torque_events_wait_until(static fn (): bool => in_array('JobProcessed', torque_event_names($run['log']), true), 15, 'the job after Looping stopped returning false', $run['output']);
    }, preCreate: ['looping-false']);
});

it('keeps polling when a Looping listener throws', function () {
    // The listener throws on every poll; the job must still be consumed.
    torque_with_events_worker([new RestartProbeJob(0.1)], [], function (array $run): void {
        torque_events_wait_until(static fn (): bool => in_array('JobProcessed', torque_event_names($run['log']), true), 15, 'the job despite the throwing listener', $run['output']);

        expect(torque_event_names($run['log']))->toContain('JobProcessed')
            ->and((string) file_get_contents($run['output']))->toContain('Looping dispatch failed');
    }, preCreate: ['looping-throw']);
});

/**
 * Wait for an attempt to end and return its rows, the job's own log lines included.
 *
 * @param  array<string, mixed>  $run
 * @return list<array<string, mixed>>
 */
function torque_attempt_rows(array $run, string $label, int $attempt = 1): array
{
    $rows = static fn (): array => array_values(array_filter(
        torque_event_rows($run['log']),
        static fn (array $row): bool => ($row['tag'] ?? null) === $label && ($row['attempt'] ?? null) === $attempt,
    ));

    torque_events_wait_until(static fn (): bool => in_array('JobAttempted', array_column($rows(), 'event'), true), 15, "the attempt of {$label} to end", $run['output']);

    return $rows();
}

/**
 * @param  array<string, mixed>  $run
 */
function torque_pending_count(array $run): int
{
    return (int) $run['redis']->execute('XPENDING', $run['prefix'].$run['queue'], 'events-test')[0];
}

/**
 * @param  array<string, mixed>  $run
 */
function torque_delayed_count(array $run): int
{
    return (int) $run['redis']->execute('ZCARD', $run['prefix'].$run['queue'].':delayed');
}

describe('job events', function () {
    it('fires popping, popped and the processing events in stock order', function () {
        torque_with_events_worker([new JobEventsProbeJob('a')], ['job_events' => true], function (array $run): void {
            $attempt = torque_attempt_rows($run, 'a');
            $rows = torque_event_rows($run['log']);
            $popped = array_search('JobPopped', array_column($rows, 'event'), true);

            expect(array_column($attempt, 'event'))->toBe(['JobPopped', 'JobProcessing', 'JobHandle', 'JobProcessed', 'JobAttempted'])
                ->and($popped)->toBeInt()
                ->and($rows[$popped - 1])->toMatchArray(['event' => 'JobPopping', 'connection' => 'torque', 'queue' => 'events', 'tag' => null])
                ->and($attempt[0])->toMatchArray(['connection' => 'torque', 'queue' => 'events'])
                ->and(array_unique(array_column($attempt, 'fiber')))->toHaveCount(1)
                ->and($attempt[0]['fiber'])->toBe($rows[$popped - 1]['fiber']);
        });
    });

    it('fires popping on an empty poll, and no popped', function () {
        torque_with_events_worker([], ['job_events' => true], function (array $run): void {
            torque_events_wait_until(static fn (): bool => count(torque_event_names($run['log'])) >= 4, 15, 'a few empty polls', $run['output']);

            expect(array_count_values(torque_event_names($run['log'])))->toHaveKey('JobPopping')
                ->and(torque_event_names($run['log']))->not->toContain('JobPopped');
        });
    });

    it('fires JobReleased after JobProcessed when a job releases itself', function () {
        torque_with_events_worker([new JobEventsProbeJob('a', 'release')], ['job_events' => true], function (array $run): void {
            expect(array_column(torque_attempt_rows($run, 'a'), 'event'))->toBe(['JobPopped', 'JobProcessing', 'JobHandle', 'JobProcessed', 'JobReleased', 'JobAttempted'])
                ->and(torque_delayed_count($run))->toBe(1)
                ->and(torque_pending_count($run))->toBe(0);
        });
    });

    it('fires JobReleasedAfterException once when the worker releases after a throw', function () {
        torque_with_events_worker([new JobEventsProbeJob('a', 'throw')], ['job_events' => true, 'max_retries' => 3], function (array $run): void {
            $attempt = torque_attempt_rows($run, 'a');

            expect(array_column($attempt, 'event'))->toBe(['JobPopped', 'JobProcessing', 'JobHandle', 'JobExceptionOccurred', 'JobReleasedAfterException', 'JobAttempted'])
                ->and($attempt[4])->toMatchArray(['connection' => 'torque', 'backoff' => 2, 'exception' => 'probe failed'])
                ->and(torque_delayed_count($run))->toBe(1);
        });
    });

    it('fires neither release event when a job releases itself and then throws', function () {
        torque_with_events_worker([new JobEventsProbeJob('a', 'release-throw')], ['job_events' => true, 'max_retries' => 3], function (array $run): void {
            expect(array_column(torque_attempt_rows($run, 'a'), 'event'))->toBe(['JobPopped', 'JobProcessing', 'JobHandle', 'JobExceptionOccurred', 'JobAttempted'])
                ->and(torque_delayed_count($run))->toBe(1)
                ->and(torque_pending_count($run))->toBe(0);
        });
    });

    it('fires no JobReleased when the job catches its failed release', function () {
        torque_with_events_worker([new JobEventsProbeJob('a', 'release-caught')], ['job_events' => true, 'delayed_wrongtype' => true], function (array $run): void {
            expect(array_column(torque_attempt_rows($run, 'a'), 'event'))->toBe(['JobPopped', 'JobProcessing', 'JobHandle', 'JobReleaseFailed', 'JobProcessed', 'JobAttempted'])
                ->and(torque_pending_count($run))->toBe(1);
        });
    });

    it('fires no JobReleasedAfterException when the worker release fails', function () {
        torque_with_events_worker([new JobEventsProbeJob('a', 'throw')], ['job_events' => true, 'max_retries' => 3, 'delayed_wrongtype' => true], function (array $run): void {
            expect(array_column(torque_attempt_rows($run, 'a'), 'event'))->toBe(['JobPopped', 'JobProcessing', 'JobHandle', 'JobExceptionOccurred', 'JobAttempted'])
                ->and(torque_pending_count($run))->toBe(1)
                ->and((string) file_get_contents($run['output']))->toContain('Handling a job failure threw');
        });
    });

    it('scopes Context from JobPopped to the attempt, where the hydrate drops it', function () {
        torque_with_events_worker([new JobEventsProbeJob('a')], ['job_events' => true], function (array $run): void {
            $context = array_column(torque_attempt_rows($run, 'a'), 'context', 'event');

            // A slot polls in the ambient scope: this one would see the value if
            // the listener had added it there.
            $count = count(torque_event_rows($run['log']));
            torque_events_wait_until(static function () use ($run, $count): bool {
                return in_array('JobPopping', array_column(array_slice(torque_event_rows($run['log']), $count), 'event'), true);
            }, 15, 'a poll after the attempt', $run['output']);

            expect($context)->toBe([
                'JobPopped' => 'a',
                'JobProcessing' => null,
                'JobHandle' => null,
                'JobProcessed' => null,
                'JobAttempted' => null,
            ]);

            foreach (torque_event_rows($run['log']) as $row) {
                if ($row['event'] === 'JobPopping') {
                    expect($row['context'])->toBeNull();
                }
            }
        });
    });

    it('keeps the events of interleaved slots on their own job', function () {
        $jobs = [new JobEventsProbeJob('a', seconds: 0.4), new JobEventsProbeJob('b', seconds: 0.4)];

        torque_with_events_worker($jobs, ['job_events' => true, 'concurrency' => 2], function (array $run): void {
            $a = torque_attempt_rows($run, 'a');
            $b = torque_attempt_rows($run, 'b');
            $expected = ['JobPopped', 'JobProcessing', 'JobHandle', 'JobProcessed', 'JobAttempted'];

            expect(array_column($a, 'event'))->toBe($expected)
                ->and(array_column($b, 'event'))->toBe($expected)
                ->and(array_unique(array_column($a, 'fiber')))->toHaveCount(1)
                ->and(array_unique(array_column($b, 'fiber')))->toHaveCount(1)
                ->and($a[0]['fiber'])->not->toBe($b[0]['fiber'])
                ->and($a[0]['context'])->toBe('a')
                ->and($b[0]['context'])->toBe('b');

            // They really overlapped: each started before the other finished.
            $rows = torque_event_rows($run['log']);
            $at = static fn (string $event, string $label): int => array_key_first(array_filter(
                $rows,
                static fn (array $row): bool => $row['event'] === $event && ($row['tag'] ?? null) === $label,
            ));

            expect($at('JobProcessing', 'a'))->toBeLessThan($at('JobProcessed', 'b'))
                ->and($at('JobProcessing', 'b'))->toBeLessThan($at('JobProcessed', 'a'));

            // Polling slots stayed out of both attempts' Context.
            foreach ($rows as $row) {
                if ($row['event'] === 'JobPopping') {
                    expect($row['context'])->toBeNull();
                }
            }
        });
    });

    it('does not let a throwing listener change the outcome', function (string $event, string $mode, int $delayed, ?string $exception) {
        torque_with_events_worker([new JobEventsProbeJob('a', $mode)], ['job_events' => true, 'max_retries' => 3], function (array $run) use ($event, $delayed, $exception): void {
            $attempt = torque_attempt_rows($run, 'a');

            expect($attempt[array_key_last($attempt)])->toMatchArray(['event' => 'JobAttempted', 'exception' => $exception])
                ->and(array_column($attempt, 'event'))->toContain('JobHandle')
                ->and(torque_delayed_count($run))->toBe($delayed)
                ->and(torque_pending_count($run))->toBe(0)
                ->and((string) file_get_contents($run['output']))->toContain("{$event} dispatch failed");
        }, preCreate: ["throw-{$event}"]);
    })->with([
        'JobPopping' => ['JobPopping', 'ok', 0, null],
        'JobPopped' => ['JobPopped', 'ok', 0, null],
        'JobReleased' => ['JobReleased', 'release', 1, null],
        'JobReleasedAfterException' => ['JobReleasedAfterException', 'throw', 1, 'probe failed'],
    ]);
});
