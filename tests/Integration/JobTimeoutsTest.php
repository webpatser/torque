<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\TimeoutExceededException;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\TimeoutProbeJob;

/*
|--------------------------------------------------------------------------
| Opt-in job timeouts
|--------------------------------------------------------------------------
|
| Real WorkerProcesses (tests/Fixtures/job-timeouts-worker.php) consume real jobs
| from a real stream until they exit. Each test reads the log of what ran and
| what was left in Redis. A worker that does not exit in time fails the test, so
| a hang shows up as such.
|
| Requires a running Redis instance.
|
*/

/**
 * Runs the scenario with a stream holding these jobs. Its first argument starts
 * one worker and waits for its exit; its second reads what is left in Redis.
 *
 * @param  list<object>  $jobs
 * @param  Closure(Closure(array<string, mixed>): array{code: int, seconds: float, output: string, rows: list<array<string, mixed>>}, Closure(): array{waiting: int, delayed: list<array{payload: array<string, mixed>, score: float}>, pending: int, deadLetters: int}): void  $scenario
 */
function torque_with_timeout_jobs(array $jobs, Closure $scenario): void
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-timeouts-{$token}:";
    $group = 'timeouts-test';
    $queueName = 'timeouts';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-timeouts-{$token}.jsonl";
    $outputFile = "{$directory}/torque-timeouts-{$token}.out";
    $cacheDir = "{$directory}/torque-timeouts-{$token}-cache";
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

    $work = static function (array $options) use ($prefix, $group, $queueName, $logFile, $outputFile, $cacheDir): array {
        @unlink($logFile);
        @unlink($outputFile);

        $startedAt = microtime(true);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/job-timeouts-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, $cacheDir, json_encode($options, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        $deadline = microtime(true) + 15;
        $code = null;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                $code = $status['exitcode'];

                break;
            }

            usleep(50_000);
        }

        if ($code === null) {
            proc_terminate($process, SIGKILL);
        }

        proc_close($process);

        $output = (string) @file_get_contents($outputFile);

        if ($code === null) {
            throw new RuntimeException("The worker did not exit:\n{$output}");
        }

        $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return [
            'code' => $code,
            'seconds' => microtime(true) - $startedAt,
            'output' => $output,
            'rows' => array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines),
        ];
    };

    $state = static function () use ($redis, $prefix, $group, $queueName): array {
        $stream = $prefix.$queueName;
        $flat = $redis->execute('ZRANGE', $stream.':delayed', '0', '-1', 'WITHSCORES') ?? [];
        $delayed = [];

        foreach (array_chunk($flat, 2) as [$payload, $score]) {
            $delayed[] = ['payload' => json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR), 'score' => (float) $score];
        }

        return [
            'waiting' => (int) $redis->execute('XLEN', $stream),
            'delayed' => $delayed,
            'pending' => (int) ($redis->execute('XPENDING', $stream, $group)[0] ?? 0),
            'deadLetters' => count($redis->execute('XRANGE', $prefix.'dead-letter', '-', '+') ?? []),
        ];
    };

    try {
        $scenario($work, $state);
    } finally {
        @unlink($logFile);
        @unlink($outputFile);
        (new Filesystem)->deleteDirectory($cacheDir);

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

/**
 * Runs the scenario with a sqlite file holding what the probe jobs and the failed
 * job log write, and a way to read the first column of a query from it.
 *
 * @param  Closure(string, Closure(string): list<mixed>): void  $scenario
 */
function torque_with_timeout_database(Closure $scenario): void
{
    $path = tempnam(sys_get_temp_dir(), 'torque-timeouts-db-');
    $pdo = new PDO("sqlite:{$path}");
    $pdo->exec('CREATE TABLE probe_writes (label TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY, connection TEXT, queue TEXT, payload TEXT, exception TEXT, failed_at TEXT)');

    try {
        $scenario($path, static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    } finally {
        @unlink($path);
    }
}

/**
 * The events of one job, in order.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function torque_timeout_events(array $rows, string $label): array
{
    return array_column(array_values(array_filter($rows, static fn (array $row): bool => ($row['tag'] ?? null) === $label)), 'event');
}

/**
 * The first row of this event, for this job when a label is given.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function torque_timeout_row(array $rows, string $event, ?string $label = null): array
{
    foreach ($rows as $row) {
        if ($row['event'] === $event && ($label === null || ($row['tag'] ?? null) === $label)) {
            return $row;
        }
    }

    throw new RuntimeException("No {$event} row:\n".json_encode($rows, JSON_PRETTY_PRINT));
}

it('times out a hung job while a bystander finishes, and runs it again on the next worker', function () {
    $jobs = [
        new TimeoutProbeJob('hung', seconds: 8, timeout: 1, backoff: 3),
        new TimeoutProbeJob('bystander', seconds: 1.8),
    ];

    torque_with_timeout_jobs($jobs, function (Closure $work, Closure $state): void {
        $first = $work(['concurrency' => 2, 'grace' => 3]);

        expect($first['code'])->toBe(1)
            ->and(torque_timeout_events($first['rows'], 'hung'))->toBe([
                'handle',
                'interrupted',
                'JobInterrupted',
                'JobTimedOut',
                'JobExceptionOccurred',
                'JobReleasedAfterException',
                'deferred-always',
                'JobAttempted',
            ])
            ->and(torque_timeout_events($first['rows'], 'bystander'))->toContain('JobProcessed')
            ->and(torque_timeout_events($first['rows'], 'bystander'))->not->toContain('JobTimedOut');

        expect(torque_timeout_row($first['rows'], 'interrupted'))->toMatchArray(['signal' => SIGALRM, 'context' => 'hung'])
            ->and(torque_timeout_row($first['rows'], 'JobInterrupted', 'hung'))->toMatchArray(['signal' => SIGALRM, 'context' => 'hung'])
            ->and(torque_timeout_row($first['rows'], 'JobTimedOut', 'hung'))->toMatchArray(['timeout' => 1, 'context' => 'hung'])
            ->and(torque_timeout_row($first['rows'], 'JobAttempted', 'hung'))->toMatchArray(['exception' => TimeoutExceededException::class, 'context' => 'hung']);

        expect(torque_timeout_row($first['rows'], 'JobAttempted', 'hung')['duration'])->toBeGreaterThan(900.0)->toBeLessThan(1900.0);

        $last = $first['rows'][array_key_last($first['rows'])];

        expect($last)->toMatchArray(['event' => 'WorkerStopping', 'status' => 1, 'reason' => 'timed_out', 'jobs' => 2]);

        $released = torque_timeout_row($first['rows'], 'JobReleasedAfterException', 'hung');
        $left = $state();

        expect($released['backoff'])->toBe(3)
            ->and($left['delayed'])->toHaveCount(1)
            ->and($left['delayed'][0]['payload']['attempts'])->toBe(1)
            ->and($left['delayed'][0]['score'] - $released['at'])->toBeGreaterThan(1.9)->toBeLessThan(3.5)
            ->and($left['pending'])->toBe(0)
            ->and($left['waiting'])->toBe(0);

        $second = $work(['max_jobs' => 1]);

        expect($second['code'])->toBe(0)
            ->and(torque_timeout_row($second['rows'], 'handle', 'hung')['attempt'])->toBe(2)
            ->and(torque_timeout_events($second['rows'], 'hung'))->toContain('JobProcessed')
            ->and(torque_timeout_row($second['rows'], 'WorkerStopping')['reason'])->toBe('max_jobs');

        $after = $state();

        expect($after['delayed'])->toBe([])
            ->and($after['waiting'])->toBe(0)
            ->and($after['pending'])->toBe(0)
            ->and($after['deadLetters'])->toBe(0);
    });
});

it('releases with the release delay, not the grace, when the backoff is shorter', function () {
    torque_with_timeout_jobs([new TimeoutProbeJob('hung', seconds: 8, timeout: 1, backoff: 0)], function (Closure $work, Closure $state): void {
        $run = $work(['grace' => 1, 'release_delay' => 2]);

        expect(torque_timeout_row($run['rows'], 'JobReleasedAfterException', 'hung')['backoff'])->toBe(2)
            ->and($state()['delayed'])->toHaveCount(1);
    });
});

it('fails the job in the order of the stock worker', function (TimeoutProbeJob $job) {
    torque_with_timeout_jobs([$job], function (Closure $work, Closure $state): void {
        $run = $work([]);
        $left = $state();

        expect($run['code'])->toBe(1)
            ->and(torque_timeout_events($run['rows'], 'hung'))->toBe([
                'handle',
                'interrupted',
                'JobInterrupted',
                'JobFailed',
                'JobTimedOut',
                'JobExceptionOccurred',
                'deferred-always',
                'JobAttempted',
            ])
            ->and(torque_timeout_row($run['rows'], 'JobFailed', 'hung'))->toMatchArray(['exception' => TimeoutExceededException::class, 'context' => 'hung'])
            ->and($left['deadLetters'])->toBe(1)
            ->and($left['delayed'])->toBe([])
            ->and($left['waiting'])->toBe(0)
            ->and($left['pending'])->toBe(0);
    });
})->with([
    'failOnTimeout' => fn () => new TimeoutProbeJob('hung', seconds: 8, timeout: 1, failOnTimeout: true, tries: 5),
    '$tries used up' => fn () => new TimeoutProbeJob('hung', seconds: 8, timeout: 1, tries: 1),
    'maxExceptions used up' => fn () => new TimeoutProbeJob('hung', seconds: 8, timeout: 1, tries: 5, maxExceptions: 1),
    'retryUntil passed' => fn () => new TimeoutProbeJob('hung', seconds: 8, timeout: 1, tries: 5, retryUntil: time() - 10),
]);

// A guard, not proof of the cancel: the check at the top of $onTimeout drops a stale
// timer as well. 'drops the timer of an attempt' below is what sees the cancel itself.
it('does not let an attempt that ended time out the next job in its slot', function (string $after) {
    $jobs = [
        new TimeoutProbeJob('fast', after: $after, timeout: 1, tries: 1),
        new TimeoutProbeJob('slow', seconds: 1.6),
    ];

    torque_with_timeout_jobs($jobs, function (Closure $work): void {
        $run = $work(['max_jobs' => 2]);

        expect($run['code'])->toBe(0)
            ->and(array_column($run['rows'], 'event'))->not->toContain('JobTimedOut')
            ->and(array_column($run['rows'], 'event'))->not->toContain('interrupted')
            ->and(torque_timeout_events($run['rows'], 'slow'))->toContain('JobProcessed')
            ->and(torque_timeout_row($run['rows'], 'WorkerStopping')['reason'])->toBe('max_jobs');
    });
})->with(['a job that completes' => 'none', 'a job that throws' => 'throw']);

it('drops the timer of an attempt that ended, so it does not hold on to the job', function () {
    // The next job replaces the slot's own references. A timer left behind would
    // keep the first job in memory until it fired, 30 seconds from now.
    $jobs = [new TimeoutProbeJob('fast', timeout: 30), new TimeoutProbeJob('next')];

    torque_with_timeout_jobs($jobs, function (Closure $work): void {
        $run = $work(['max_jobs' => 2]);

        expect($run['code'])->toBe(0)
            ->and(torque_timeout_row($run['rows'], 'WorkerStopping')['alive'])->not->toContain('fast');
    });
});

it('takes no outcome from a job that resumes while its attempt is settling', function () {
    $modes = ['none', 'throw', 'release', 'fail', 'delete'];
    $jobs = array_map(static fn (string $mode): TimeoutProbeJob => new TimeoutProbeJob($mode, seconds: 1.5, after: $mode, timeout: 1, interruptSeconds: 1.0), $modes);

    torque_with_timeout_jobs($jobs, function (Closure $work, Closure $state) use ($modes): void {
        $run = $work(['concurrency' => 5, 'grace' => 4]);
        $left = $state();

        foreach ($modes as $mode) {
            $ordered = torque_timeout_events($run['rows'], $mode);
            $events = array_count_values($ordered);

            expect(array_search('resumed', $ordered, true))->toBeLessThan(array_search('interrupted-returned', $ordered, true))
                ->and($events)->toMatchArray(['resumed' => 1, 'JobTimedOut' => 1, 'JobAttempted' => 1, 'JobReleasedAfterException' => 1])
                ->and($events)->not->toHaveKey('JobProcessed')
                ->and($events)->not->toHaveKey('JobFailed')
                ->and($events)->not->toHaveKey('JobReleased');
        }

        expect($left['delayed'])->toHaveCount(5)
            ->and(array_unique(array_map(static fn (array $entry): int => $entry['payload']['attempts'], $left['delayed'])))->toBe([1])
            ->and($left['waiting'])->toBe(0)
            ->and($left['pending'])->toBe(0)
            ->and($left['deadLetters'])->toBe(0);
    });
});

it('takes no outcome from an async child of a job that timed out', function () {
    $jobs = [
        new TimeoutProbeJob('parent', seconds: 8, timeout: 1, childActsAfter: 1.5),
        new TimeoutProbeJob('bystander', seconds: 2.5),
    ];

    torque_with_timeout_jobs($jobs, function (Closure $work, Closure $state): void {
        $run = $work(['concurrency' => 2, 'grace' => 4]);
        $left = $state();
        $events = array_count_values(torque_timeout_events($run['rows'], 'parent'));

        expect($events)->toMatchArray(['child-acted' => 1, 'JobTimedOut' => 1, 'JobAttempted' => 1, 'JobReleasedAfterException' => 1])
            ->and($left['delayed'])->toHaveCount(1)
            ->and($left['delayed'][0]['payload']['attempts'])->toBe(1)
            ->and($left['waiting'])->toBe(0)
            ->and($left['pending'])->toBe(0)
            ->and($left['deadLetters'])->toBe(0);
    });
});

it('takes no outcome from an abandoned job that resumes', function () {
    $modes = ['none', 'throw', 'release', 'fail', 'delete'];
    $jobs = array_map(static fn (string $mode): TimeoutProbeJob => new TimeoutProbeJob($mode, seconds: 1.5, after: $mode, timeout: 1), $modes);
    $jobs[] = new TimeoutProbeJob('bystander', seconds: 3.0);

    torque_with_timeout_jobs($jobs, function (Closure $work, Closure $state) use ($modes): void {
        $run = $work(['concurrency' => 6, 'grace' => 4]);
        $left = $state();

        foreach ($modes as $mode) {
            $events = array_count_values(torque_timeout_events($run['rows'], $mode));

            expect($events)->toMatchArray(['resumed' => 1, 'JobTimedOut' => 1, 'JobAttempted' => 1, 'JobReleasedAfterException' => 1])
                ->and($events)->not->toHaveKey('JobProcessed')
                ->and($events)->not->toHaveKey('JobFailed')
                ->and($events)->not->toHaveKey('JobReleased')
                ->and(torque_timeout_row($run['rows'], 'JobAttempted', $mode)['exception'])->toBe(TimeoutExceededException::class);
        }

        expect(torque_timeout_events($run['rows'], 'bystander'))->toContain('JobProcessed')
            ->and(torque_timeout_row($run['rows'], 'WorkerStopping'))->toMatchArray(['reason' => 'timed_out', 'jobs' => 6])
            ->and($run['output'])->toContain('thrown after the wait')
            ->and($left['delayed'])->toHaveCount(5)
            ->and(array_unique(array_map(static fn (array $entry): int => $entry['payload']['attempts'], $left['delayed'])))->toBe([1])
            ->and($left['waiting'])->toBe(0)
            ->and($left['pending'])->toBe(0)
            ->and($left['deadLetters'])->toBe(0);
    });
});

it('ignores $timeout while timeouts are off', function () {
    torque_with_timeout_jobs([new TimeoutProbeJob('slow', seconds: 1.6, timeout: 1)], function (Closure $work): void {
        $run = $work(['enabled' => false, 'max_jobs' => 1, 'stream' => ['timeout' => 1]]);

        expect($run['code'])->toBe(0)
            ->and(torque_timeout_events($run['rows'], 'slow'))->toBe(['handle', 'resumed', 'JobProcessed', 'deferred', 'deferred-always', 'JobAttempted'])
            ->and(torque_timeout_row($run['rows'], 'WorkerStopping')['reason'])->toBe('max_jobs');
    });
});

it('uses the stream timeout for a job that declares none', function () {
    torque_with_timeout_jobs([new TimeoutProbeJob('hung', seconds: 8)], function (Closure $work): void {
        $run = $work(['stream' => ['timeout' => 1]]);

        expect(torque_timeout_row($run['rows'], 'JobTimedOut', 'hung')['timeout'])->toBe(1);
    });
});

it('prefers the timeout of the job over the stream, where 0 is none', function () {
    torque_with_timeout_jobs([new TimeoutProbeJob('slow', seconds: 1.6, timeout: 0)], function (Closure $work): void {
        $run = $work(['max_jobs' => 1, 'stream' => ['timeout' => 1]]);

        expect(torque_timeout_events($run['rows'], 'slow'))->toContain('JobProcessed')
            ->and(array_column($run['rows'], 'event'))->not->toContain('JobTimedOut');
    });
});

it('does not let a memory stop wait for an abandoned job', function () {
    torque_with_timeout_jobs([new TimeoutProbeJob('hung', seconds: 8, timeout: 2)], function (Closure $work, Closure $state): void {
        // Every worker is over 1 MB, so the memory stop comes first, at its first tick.
        $run = $work(['memory_limit' => 1, 'grace' => 30]);

        expect($run['code'])->toBe(12)
            ->and($run['seconds'])->toBeLessThan(7.5)
            ->and(torque_timeout_row($run['rows'], 'WorkerStopping')['reason'])->toBe('memory')
            ->and(torque_timeout_events($run['rows'], 'hung'))->toContain('JobTimedOut')
            ->and($state()['delayed'])->toHaveCount(1);
    });
});

it('still settles and rotates when interrupted() never returns', function (bool $fails) {
    torque_with_timeout_jobs([new TimeoutProbeJob('hung', seconds: 8, timeout: 1, failOnTimeout: $fails, interruptHangs: true)], function (Closure $work, Closure $state) use ($fails): void {
        $run = $work(['grace' => 1]);
        $left = $state();
        $events = array_count_values(torque_timeout_events($run['rows'], 'hung'));

        expect($run['code'])->toBe(1)
            ->and($run['seconds'])->toBeLessThan(7.5)
            ->and(torque_timeout_row($run['rows'], 'WorkerStopping')['reason'])->toBe('timed_out')
            ->and($events)->toMatchArray(['JobTimedOut' => 1, 'JobExceptionOccurred' => 1, 'JobAttempted' => 1])
            ->and($events)->not->toHaveKey('JobInterrupted')
            ->and($left['pending'])->toBe(0)
            ->and($left['waiting'])->toBe(0);

        if ($fails) {
            expect($events['JobFailed'])->toBe(1)
                ->and($left['deadLetters'])->toBe(1)
                ->and($left['delayed'])->toBe([]);
        } else {
            expect($events['JobReleasedAfterException'])->toBe(1)
                ->and($left['deadLetters'])->toBe(0)
                ->and($left['delayed'])->toHaveCount(1)
                ->and($left['delayed'][0]['payload']['attempts'])->toBe(1);
        }
    });
})->with(['released' => false, 'failOnTimeout' => true]);

it('settles the attempt when a listener of the timeout events never returns', function (string $listener) {
    torque_with_timeout_jobs([new TimeoutProbeJob('hung', seconds: 8, timeout: 1)], function (Closure $work, Closure $state) use ($listener): void {
        $run = $work(['grace' => 2, 'hang_on' => $listener]);
        $left = $state();

        expect($run['code'])->toBe(1)
            ->and($run['seconds'])->toBeLessThan(7.5)
            ->and(torque_timeout_events($run['rows'], 'hung'))->toBe([
                'handle',
                'interrupted',
                'JobInterrupted',
                'JobTimedOut',
                'JobExceptionOccurred',
                'JobReleasedAfterException',
                'deferred-always',
                'JobAttempted',
            ])
            ->and($left['delayed'])->toHaveCount(1)
            ->and($left['delayed'][0]['payload']['attempts'])->toBe(1)
            ->and($left['pending'])->toBe(0)
            ->and($left['waiting'])->toBe(0);
    });
})->with(['JobInterrupted', 'JobTimedOut', 'JobExceptionOccurred']);

it('ignores what interrupted() does once its time is up', function () {
    $jobs = [
        new TimeoutProbeJob('late', seconds: 8, timeout: 1, interruptSeconds: 1.5, interruptAfter: 'delete'),
        new TimeoutProbeJob('bystander', seconds: 2.7),
    ];

    // Its time is up one second after the timeout, half a second before it deletes.
    torque_with_timeout_jobs($jobs, function (Closure $work, Closure $state): void {
        $run = $work(['concurrency' => 2, 'grace' => 2]);
        $left = $state();
        $events = array_count_values(torque_timeout_events($run['rows'], 'late'));

        expect($events)->toMatchArray(['interrupted-returned' => 1, 'JobTimedOut' => 1, 'JobReleasedAfterException' => 1])
            ->and($left['delayed'])->toHaveCount(1)
            ->and($left['pending'])->toBe(0)
            ->and($left['waiting'])->toBe(0);
    });
});

it('keeps what interrupted() settles within its time', function () {
    torque_with_timeout_jobs([new TimeoutProbeJob('hung', seconds: 8, timeout: 1, interruptAfter: 'delete')], function (Closure $work, Closure $state): void {
        $run = $work(['grace' => 2]);
        $left = $state();

        expect(torque_timeout_events($run['rows'], 'hung'))->not->toContain('JobReleasedAfterException')
            ->and($left['delayed'])->toBe([])
            ->and($left['pending'])->toBe(0)
            ->and($left['waiting'])->toBe(0);
    });
});

it('keeps the transaction of another slot when a job times out and fails', function (string $failedDriver, int $failedRows) {
    $jobs = [
        new TimeoutProbeJob('hung', seconds: 8, timeout: 1, failOnTimeout: true),
        new TimeoutProbeJob('writer', seconds: 1.8, writes: true),
    ];

    torque_with_timeout_database(function (string $database, Closure $query) use ($jobs, $failedDriver, $failedRows): void {
        torque_with_timeout_jobs($jobs, function (Closure $work) use ($database, $query, $failedDriver, $failedRows): void {
            $run = $work(['concurrency' => 2, 'grace' => 3, 'database' => $database, 'failed_driver' => $failedDriver]);

            expect(torque_timeout_events($run['rows'], 'hung'))->toContain('JobFailed')
                ->and(torque_timeout_events($run['rows'], 'writer'))->toContain('JobProcessed')
                ->and($query('SELECT label FROM probe_writes ORDER BY rowid'))->toBe(['writer-first', 'writer-second'])
                ->and($query('SELECT COUNT(*) FROM failed_jobs'))->toBe([$failedRows]);
        });
    });
})->with([
    'failed_jobs on that connection' => ['database', 1],
    'a Batchable job' => ['null', 0],
]);

it('keeps the stock rollback when the job that timed out is the only attempt', function () {
    $jobs = [new TimeoutProbeJob('writer', seconds: 8, timeout: 1, failOnTimeout: true, writes: true)];

    torque_with_timeout_database(function (string $database, Closure $query) use ($jobs): void {
        torque_with_timeout_jobs($jobs, function (Closure $work) use ($database, $query): void {
            $run = $work(['database' => $database, 'failed_driver' => 'database']);

            // Without the rollback the row sits in a transaction that never commits.
            expect(torque_timeout_events($run['rows'], 'writer'))->toContain('JobFailed')
                ->and($query('SELECT label FROM probe_writes'))->toBe([])
                ->and($query('SELECT COUNT(*) FROM failed_jobs'))->toBe([1]);
        });
    });
});

it('warns that the failure record joins the open transaction when others are in flight', function () {
    $jobs = [
        new TimeoutProbeJob('hung', seconds: 8, timeout: 1, failOnTimeout: true, writes: true),
        new TimeoutProbeJob('bystander', seconds: 1.8),
    ];

    torque_with_timeout_database(function (string $database, Closure $query) use ($jobs): void {
        torque_with_timeout_jobs($jobs, function (Closure $work, Closure $state) use ($database, $query): void {
            $run = $work(['concurrency' => 2, 'grace' => 3, 'database' => $database, 'failed_driver' => 'database']);

            // The row went into the transaction the job hung in, which exit rolls back.
            expect($run['output'])->toContain('still has a transaction open')
                ->and($query('SELECT COUNT(*) FROM failed_jobs'))->toBe([0])
                ->and($state()['deadLetters'])->toBe(1);
        });
    });
});

it('does not cut a settle short at a grace of zero', function () {
    // The job starts right after the first timer is set, so its timeout fires just
    // after a tick and the next tick comes almost a second later. Past that second
    // the settle is always caught by a tick; the tick after it comes well past 1.1s.
    torque_with_timeout_jobs([new TimeoutProbeJob('hung', seconds: 8, timeout: 1)], function (Closure $work, Closure $state): void {
        $run = $work(['grace' => 0, 'release_delay' => 0, 'slow_on' => 'JobReleasedAfterException', 'slow_seconds' => 1.1]);

        expect($run['code'])->toBe(1)
            ->and(torque_timeout_events($run['rows'], 'hung'))->toContain('JobReleasedAfterException', 'JobAttempted');

        // No backoff and no release delay, so the release adds it to the stream at once.
        expect($state())->toMatchArray(['waiting' => 1, 'delayed' => [], 'pending' => 0]);
    });
});

it('keeps the release of a job that released itself before it hung', function () {
    torque_with_timeout_jobs([new TimeoutProbeJob('hung', seconds: 8, timeout: 1, releasesFirst: true)], function (Closure $work, Closure $state): void {
        $run = $work([]);
        $left = $state();

        expect(torque_timeout_events($run['rows'], 'hung'))->toContain('JobTimedOut')
            ->and(torque_timeout_events($run['rows'], 'hung'))->not->toContain('JobReleasedAfterException')
            ->and($left['delayed'])->toHaveCount(1)
            ->and($left['delayed'][0]['score'] - time())->toBeGreaterThan(50)
            ->and($left['waiting'])->toBe(0)
            ->and($left['pending'])->toBe(0);
    });
});

it('warns at boot when a stream timeout does not stay below retry_after', function () {
    torque_with_timeout_jobs([new TimeoutProbeJob('quick')], function (Closure $work): void {
        $run = $work(['max_jobs' => 1, 'stream' => ['timeout' => 90, 'retry_after' => 90]]);

        expect($run['output'])->toContain('WARN')
            ->and($run['output'])->toContain('retry_after');
    });
});
