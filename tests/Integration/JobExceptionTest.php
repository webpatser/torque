<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\JobExceptionProbeJob;
use Webpatser\Torque\Worker\WorkerProcess;

/*
|--------------------------------------------------------------------------
| Thrown job exceptions and slot survival
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/job-exception-worker.php) consumes real
| jobs from a real stream. Each test reads the worker's log and what is left in
| Redis afterwards: copies still waiting in the stream or the delayed set are
| jobs that were added once too often.
|
| A worker whose slot died stays idle until its lifetime ends, so a failing test
| ends with the job missing from the log rather than with a timeout.
|
| Requires a running Redis instance.
|
*/

/**
 * @param  list<JobExceptionProbeJob>  $jobs
 * @param  list<string>  $rawEntries  Payloads added to the stream as they are, ahead of the jobs.
 * @param  bool  $brokenDeadLetter  Make every write to the dead-letter stream fail.
 * @return array{rows: list<array<string, mixed>>, output: string, waiting: int, pending: int, deadLetters: int, deadLetterReasons: list<string>}
 */
function torque_run_exception_worker(
    array $jobs,
    int $maxJobs,
    int $concurrency = 1,
    int $maxRetries = 3,
    int $lifetime = 8,
    string $brokenListener = 'none',
    string $serializer = 'json',
    array $rawEntries = [],
    bool $brokenDeadLetter = false,
): array {
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-exception-{$token}:";
    $group = 'exception-test';
    $queueName = 'exception';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-exception-{$token}.jsonl";
    $outputFile = "{$directory}/torque-exception-{$token}.out";

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

    foreach ($rawEntries as $rawEntry) {
        $redis->execute('XADD', $queue->getStreamKey(), '*', 'payload', $rawEntry);
    }

    foreach ($jobs as $job) {
        (void) $queue->push($job, '', $queueName);
    }

    if ($brokenDeadLetter) {
        // A write to a key of the wrong type fails with WRONGTYPE.
        $redis->execute('SET', $prefix.'dead-letter', 'not a stream');
    }

    try {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/job-exception-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, (string) $maxJobs, (string) $concurrency, (string) $maxRetries, (string) $lifetime, $brokenListener, $serializer],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        $deadline = microtime(true) + 45;

        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $timedOut = proc_get_status($process)['running'];

        if ($timedOut) {
            proc_terminate($process, SIGKILL);
        }

        proc_close($process);

        $output = (string) @file_get_contents($outputFile);

        if ($timedOut) {
            throw new RuntimeException("The worker timed out:\n{$output}");
        }

        $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $stream = $prefix.$queueName;
        $deadLetterEntries = $brokenDeadLetter ? [] : ($redis->execute('XRANGE', $prefix.'dead-letter', '-', '+') ?? []);

        return [
            'rows' => array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines),
            'output' => $output,
            'waiting' => (int) $redis->execute('XLEN', $stream) + (int) $redis->execute('ZCARD', $stream.':delayed'),
            'pending' => (int) ($redis->execute('XPENDING', $stream, $group)[0] ?? 0),
            'deadLetters' => count($deadLetterEntries),
            'deadLetterReasons' => array_map(
                static fn (array $entry): string => (string) ($entry[1][array_search('reason', $entry[1], true) + 1] ?? ''),
                $deadLetterEntries,
            ),
        ];
    } finally {
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

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<array<string, mixed>>
 */
function torque_exception_rows(array $rows, string $type): array
{
    return array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === $type));
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<int>
 */
function torque_exception_attempts(array $rows, string $job): array
{
    $attempts = [];

    foreach (torque_exception_rows($rows, 'handled') as $row) {
        if ($row['job'] === $job) {
            $attempts[] = $row['attempt'];
        }
    }

    return $attempts;
}

it('adds a job that released itself and then threw exactly once on the retry path', function () {
    $run = torque_run_exception_worker([new JobExceptionProbeJob('release-then-throw')], maxJobs: 2);

    expect(torque_exception_attempts($run['rows'], 'exception:release-then-throw'))->toBe([1, 2])
        ->and($run['waiting'])->toBe(0)
        ->and($run['pending'])->toBe(0)
        ->and($run['deadLetters'])->toBe(0);
});

it('adds a job that released itself and then threw exactly once on the dead-letter path', function () {
    $run = torque_run_exception_worker(
        [new JobExceptionProbeJob('release-then-throw-then-throw')],
        maxJobs: 2,
        maxRetries: 1,
    );

    expect(torque_exception_attempts($run['rows'], 'exception:release-then-throw-then-throw'))->toBe([1, 2])
        ->and($run['deadLetters'])->toBe(1)
        ->and(torque_exception_rows($run['rows'], 'failed'))->toHaveCount(1)
        ->and($run['waiting'])->toBe(0)
        ->and($run['pending'])->toBe(0);
});

it('leaves nothing behind when a job settled itself and then threw', function (string $mode, int $failedEvents) {
    $run = torque_run_exception_worker([new JobExceptionProbeJob($mode)], maxJobs: 1);

    expect(torque_exception_attempts($run['rows'], "exception:{$mode}"))->toBe([1])
        ->and(torque_exception_rows($run['rows'], 'failed'))->toHaveCount($failedEvents)
        ->and($run['waiting'])->toBe(0)
        ->and($run['pending'])->toBe(0)
        ->and($run['deadLetters'])->toBe(0);
})->with([
    'failed' => ['fail-then-throw', 1],
    'deleted' => ['delete-then-throw', 0],
    'marked as failed' => ['mark-failed-then-throw', 0],
]);

it('reports a thrown job exception exactly once, with the Context of its own attempt', function () {
    $run = torque_run_exception_worker([
        new JobExceptionProbeJob('context', 'tenant-a'),
        new JobExceptionProbeJob('context', 'tenant-b'),
    ], maxJobs: 2, concurrency: 2, maxRetries: 1);

    $reported = array_map(
        static fn (array $row): array => [$row['message'], $row['tenant']],
        torque_exception_rows($run['rows'], 'reported'),
    );

    expect($reported)->toEqualCanonicalizing([
        ['tenant-a threw', 'tenant-a'],
        ['tenant-b threw', 'tenant-b'],
    ]);
});

it('keeps the slot alive when a Looping listener throws, and reports it once a minute', function () {
    $run = torque_run_exception_worker([
        new JobExceptionProbeJob('linger'),
    ], maxJobs: 1, concurrency: 2, brokenListener: 'looping');

    expect(torque_exception_attempts($run['rows'], 'exception:linger'))->toBe([1])
        ->and(array_column(torque_exception_rows($run['rows'], 'reported'), 'message'))->toBe(['looping listener threw']);
});

it('keeps the slot alive through a failing read, backing off instead of spinning', function () {
    $run = torque_run_exception_worker([
        new JobExceptionProbeJob('sabotage-group'),
        new JobExceptionProbeJob('next'),
    ], maxJobs: 2, lifetime: 12);

    $reported = array_column(torque_exception_rows($run['rows'], 'reported'), 'message');

    expect(torque_exception_attempts($run['rows'], 'exception:next'))->toBe([1])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('NOGROUP')
        ->and(substr_count($run['output'], 'failed to read'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(5);
});

it('still retries the job and keeps the slot when a JobExceptionOccurred listener throws', function () {
    $run = torque_run_exception_worker(
        [new JobExceptionProbeJob('throw-once')],
        maxJobs: 2,
        brokenListener: 'exception-occurred',
    );

    expect(torque_exception_attempts($run['rows'], 'exception:throw-once'))->toBe([1, 2])
        ->and(array_column(torque_exception_rows($run['rows'], 'reported'), 'message'))
        ->toEqualCanonicalizing(['exception-occurred listener threw', 'threw once']);
});

it('reports what a job\'s failed() callback threw, next to the job\'s own exception', function () {
    $run = torque_run_exception_worker([new JobExceptionProbeJob('failed-callback-throws')], maxJobs: 1, maxRetries: 1);

    expect(array_column(torque_exception_rows($run['rows'], 'reported'), 'message'))
        ->toEqualCanonicalizing(['failed() callback threw', 'threw'])
        ->and($run['deadLetters'])->toBe(1);
});

it('reports a failed-jobs write that threw', function () {
    $run = torque_run_exception_worker([new JobExceptionProbeJob('throw')], maxJobs: 1, maxRetries: 1, brokenListener: 'failer');

    expect(array_column(torque_exception_rows($run['rows'], 'reported'), 'message'))
        ->toEqualCanonicalizing(['failer write threw', 'threw']);
});

it('reports neither a failed() callback nor a failer write with job reporting off', function () {
    $run = torque_run_exception_worker([new JobExceptionProbeJob('failed-callback-throws')], maxJobs: 1, maxRetries: 1, brokenListener: 'no-report');

    expect(torque_exception_rows($run['rows'], 'reported'))->toBe([])
        ->and($run['output'])->toContain('failer write threw')
        ->and($run['output'])->toContain('failed() callback threw');
});

it('reports a quiescent reset that keeps failing once a minute, and still runs the jobs', function () {
    $run = torque_run_exception_worker([
        new JobExceptionProbeJob('next'),
        new JobExceptionProbeJob('next'),
        new JobExceptionProbeJob('next'),
    ], maxJobs: 3, brokenListener: 'reset');

    expect(torque_exception_attempts($run['rows'], 'exception:next'))->toBe([1, 1, 1])
        ->and(torque_exception_rows($run['rows'], 'reset-attempt'))->toHaveCount(2)
        ->and(array_column(torque_exception_rows($run['rows'], 'reported'), 'message'))->toBe(['reset threw']);
});

it('quarantines and acknowledges an igbinary entry with a valid header and a corrupt body', function () {
    $corrupt = substr(igbinary_serialize(['uuid' => 'corrupt', 'displayName' => 'corrupt', 'job' => 'none']), 0, 12);

    expect(StreamQueue::isValidPayload($corrupt, 'igbinary'))->toBeTrue();

    $run = torque_run_exception_worker(
        [new JobExceptionProbeJob('next')],
        maxJobs: 2,
        lifetime: 4,
        serializer: 'igbinary',
        rawEntries: [$corrupt],
    );

    expect(torque_exception_attempts($run['rows'], 'exception:next'))->toBe([1])
        ->and($run['deadLetterReasons'])->toBe(['undecodable'])
        ->and($run['waiting'])->toBe(0)
        ->and($run['pending'])->toBe(0);
})->skip(! function_exists('igbinary_serialize'), 'Needs ext-igbinary.');

it('keeps a corrupt entry pending when it cannot be quarantined', function () {
    $corrupt = substr(igbinary_serialize(['uuid' => 'corrupt', 'displayName' => 'corrupt', 'job' => 'none']), 0, 12);

    $run = torque_run_exception_worker(
        [new JobExceptionProbeJob('next')],
        maxJobs: 2,
        lifetime: 3,
        serializer: 'igbinary',
        rawEntries: [$corrupt],
        brokenDeadLetter: true,
    );

    expect(torque_exception_attempts($run['rows'], 'exception:next'))->toBe([1])
        ->and($run['waiting'])->toBe(1)
        ->and($run['pending'])->toBe(1);
})->skip(! function_exists('igbinary_serialize'), 'Needs ext-igbinary.');

it('claims nothing from a dead consumer once the worker has reached its limits', function () {
    $redis = torqueRedis();
    $stream = 'torque-exception-'.bin2hex(random_bytes(4)).':scan';
    $group = 'exception-test';

    $redis->execute('XGROUP', 'CREATE', $stream, $group, '0', 'MKSTREAM');
    $redis->execute('XADD', $stream, '*', 'payload', 'dead-consumer-job');
    $redis->execute('XREADGROUP', 'GROUP', $group, 'dead-consumer', 'COUNT', '1', 'STREAMS', $stream, '>');

    $worker = new WorkerProcess(['redis' => ['uri' => torqueRedisUri()]]);
    (new ReflectionProperty($worker, 'startTime'))->setValue($worker, time());

    $steal = static fn (): ?array => (new ReflectionMethod($worker, 'stealMessage'))->invoke(
        $worker,
        $redis,
        ['scan'],
        '',
        $group,
        ['scan' => ['retry_after' => 0]],
        static fn (): string => $stream,
    );

    $owner = static fn (): string => (string) $redis->execute('XPENDING', $stream, $group, '-', '+', '1')[0][1];

    try {
        (new ReflectionProperty($worker, 'stopRequested'))->setValue($worker, true);

        expect($steal())->toBeNull()
            ->and($owner())->toBe('dead-consumer');

        // The same scan with the limits not reached does claim it.
        (new ReflectionProperty($worker, 'stopRequested'))->setValue($worker, false);

        expect($steal()['payload'] ?? null)->toBe('dead-consumer-job')
            ->and($owner())->toBe($worker->consumerId);
    } finally {
        $redis->execute('DEL', $stream);
    }
});

it('keeps the slot when a JobPermanentlyFailed listener throws', function () {
    $run = torque_run_exception_worker([
        new JobExceptionProbeJob('throw'),
        new JobExceptionProbeJob('next'),
    ], maxJobs: 2, maxRetries: 1, brokenListener: 'permanently-failed');

    expect(torque_exception_attempts($run['rows'], 'exception:next'))->toBe([1])
        ->and($run['deadLetters'])->toBe(1)
        ->and($run['pending'])->toBe(0);
});
