<?php

declare(strict_types=1);

use Illuminate\Queue\MaxAttemptsExceededException;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\AttemptProbeJob;
use Webpatser\Torque\Tests\Fixtures\DeferProbeJob;

/*
|--------------------------------------------------------------------------
| JobAttempted Integration Tests
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/job-attempted-worker.php) consumes real
| jobs from a real stream and logs what its listeners receive. One run covers
| each scenario, so it happens once and the tests read its log.
|
| Requires a running Redis instance.
|
*/

/**
 * Run a real worker over the given jobs until it exits by itself.
 *
 * @param  list<object>  $jobs
 * @return array{rows: list<array<string, mixed>>, output: string}
 */
function torque_run_attempt_worker(array $jobs, int $maxJobs, int $concurrency = 1, int $maxRetries = 2, int $drainGrace = 10): array
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-attempted-{$token}:";
    $group = 'attempted-test';
    $queueName = 'attempted';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-attempted-{$token}.jsonl";
    $outputFile = "{$directory}/torque-attempted-{$token}.out";

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

    try {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/job-attempted-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, (string) $maxJobs, (string) $concurrency, (string) $maxRetries, (string) $drainGrace],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        $deadline = microtime(true) + 45;

        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(100_000);
        }

        $timedOut = proc_get_status($process)['running'];

        if ($timedOut) {
            proc_terminate($process, SIGKILL);
        }

        proc_close($process);

        $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        if ($timedOut || $lines === []) {
            throw new RuntimeException('The worker '.($timedOut ? 'timed out' : 'logged nothing').":\n".@file_get_contents($outputFile));
        }

        return [
            'rows' => array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines),
            'output' => (string) @file_get_contents($outputFile),
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
 * Every line the worker logged in the attempt outcome scenario.
 *
 * @return list<array<string, mixed>>
 */
function torque_job_attempted_log(): array
{
    static $rows = null;

    // boom first: if its throwing listener cost the slot, nothing after it runs.
    $modes = ['boom', 'ok', 'slow', 'release', 'throw', 'fail', 'refused'];

    return $rows ??= torque_run_attempt_worker(
        array_map(static fn (string $mode): AttemptProbeJob => new AttemptProbeJob($mode), $modes),
        // One attempt each, plus a second one for release and for throw.
        maxJobs: count($modes) + 2,
    )['rows'];
}

/**
 * The attempted lines of `defer:` probe jobs, by type and job name.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function torque_defer_jobs_of(array $rows, string $type): array
{
    $jobs = [];

    foreach ($rows as $row) {
        if ($row['type'] === $type) {
            $jobs[] = substr((string) $row['job'], strlen('defer:'));
        }
    }

    sort($jobs);

    return $jobs;
}

/**
 * Jobs in concurrent slots, interleaved through marks so every job has its
 * callback registered while another job's JobAttempted fires.
 *
 * @return list<array<string, mixed>>
 */
function torque_defer_concurrency_log(): array
{
    static $rows = null;

    $firstSuccessEnded = 'ended:defer:first-success';

    $jobs = [
        // Ends only once the jobs it lists have registered their callbacks, and
        // those jobs in turn stay in flight until it has ended.
        new DeferProbeJob('first-success', waitBeforeEnd: [
            'registered:runs-after-end',
            'registered:late-failure',
            'registered:always-failure',
            'registered:child-success',
            'registered:child-failure',
        ]),
        new DeferProbeJob('runs-after-end', waitBeforeEnd: [$firstSuccessEnded]),
        new DeferProbeJob('late-failure', waitBeforeEnd: [$firstSuccessEnded], throws: true),
        new DeferProbeJob('always-failure', waitBeforeEnd: [$firstSuccessEnded], throws: true, always: true),
        new DeferProbeJob('child-success', waitBeforeEnd: [$firstSuccessEnded], fromChildFiber: true),
        new DeferProbeJob('child-failure', waitBeforeEnd: [$firstSuccessEnded], throws: true, fromChildFiber: true),

        // Registers after the first success, so only the failure can affect it.
        new DeferProbeJob('outlives-failure', waitBeforeDefer: [$firstSuccessEnded], waitBeforeEnd: ['ended:defer:other-failure']),
        new DeferProbeJob('other-failure', waitBeforeEnd: [$firstSuccessEnded, 'registered:outlives-failure'], throws: true),

        // Another attempt ends while this callback is suspended.
        new DeferProbeJob('suspends', callbackWaitsFor: ['ended:defer:ends-meanwhile']),
        new DeferProbeJob('ends-meanwhile', waitBeforeEnd: ['callback-started:suspends']),
    ];

    return $rows ??= torque_run_attempt_worker($jobs, maxJobs: count($jobs), concurrency: count($jobs), maxRetries: 1)['rows'];
}

/**
 * Slots abandoned by the worker's forced exit at the end of the drain window:
 * one suspended in the handler, one suspended in its failure handling.
 *
 * @return array{rows: list<array<string, mixed>>, output: string}
 */
function torque_forced_exit_run(): array
{
    static $run = null;

    return $run ??= torque_run_attempt_worker([
        new DeferProbeJob('abandoned', always: true, hangs: true),
        new DeferProbeJob('stuck-in-failure', throws: true, always: true),
        new DeferProbeJob('finisher', waitBeforeEnd: ['registered:abandoned', 'failing:stuck-in-failure']),
    ], maxJobs: 1, concurrency: 3, drainGrace: 1);
}

/**
 * A job taking the whole worker down with exit(), with one job suspended in its
 * handler and one suspended in its failure handling.
 *
 * @return list<array<string, mixed>>
 */
function torque_job_exit_log(): array
{
    static $rows = null;

    return $rows ??= torque_run_attempt_worker([
        new DeferProbeJob('bystander', always: true, hangs: true),
        new DeferProbeJob('stuck-in-failure', throws: true, always: true),
        new DeferProbeJob('exiter', waitBeforeEnd: ['registered:bystander', 'failing:stuck-in-failure'], always: true, exits: true),
    ], maxJobs: 10, concurrency: 3)['rows'];
}

/**
 * The JobAttempted events one probe job produced, in order.
 *
 * @return list<array<string, mixed>>
 */
function torque_attempts_of(string $job): array
{
    return array_values(array_filter(
        torque_job_attempted_log(),
        static fn (array $row): bool => $row['type'] === 'attempted' && $row['job'] === $job,
    ));
}

it('fires once for a completed attempt, with the handler run time in milliseconds', function () {
    $attempts = torque_attempts_of('probe:slow');

    expect($attempts)->toHaveCount(1)
        ->and($attempts[0]['connection'])->toBe('torque')
        ->and($attempts[0]['exception'])->toBeNull()
        ->and($attempts[0]['successful'])->toBeTrue()
        ->and($attempts[0]['duration'])->toBeGreaterThanOrEqual(1_100)->toBeLessThan(10_000);

    expect(torque_attempts_of('probe:ok'))->toHaveCount(1);
});

it('fires once per attempt when a thrown exception releases and then fails the job', function () {
    $attempts = torque_attempts_of('probe:throw');

    expect($attempts)->toHaveCount(2);

    expect($attempts[0])
        ->attempt->toBe(1)
        ->exception->toBe(RuntimeException::class)
        ->successful->toBeFalse()
        ->released->toBeTrue()
        ->failed->toBeFalse()
        ->duration->toBeNumeric();

    expect($attempts[1])
        ->attempt->toBe(2)
        ->exception->toBe(RuntimeException::class)
        ->successful->toBeFalse()
        ->released->toBeFalse()
        ->failed->toBeTrue()
        ->duration->toBeNumeric();
});

it('fires once per attempt when a job releases itself', function () {
    $attempts = torque_attempts_of('probe:release');

    expect($attempts)->toHaveCount(2);

    expect($attempts[0])
        ->attempt->toBe(1)
        ->exception->toBeNull()
        ->released->toBeTrue();

    expect($attempts[1])
        ->attempt->toBe(2)
        ->exception->toBeNull()
        ->released->toBeFalse()
        ->successful->toBeTrue();
});

it('reports a job that failed itself as unsuccessful without an exception', function () {
    $attempts = torque_attempts_of('probe:fail');

    expect($attempts)->toHaveCount(1);

    expect($attempts[0])
        ->exception->toBeNull()
        ->failed->toBeTrue()
        ->successful->toBeFalse()
        ->duration->toBeNumeric();
});

it('has no duration when the attempt ends before the handler runs', function () {
    $attempts = torque_attempts_of('probe:refused');

    expect($attempts)->toHaveCount(1);

    expect($attempts[0])
        ->exception->toBe(MaxAttemptsExceededException::class)
        ->failed->toBeTrue()
        ->duration->toBeNull();
});

it('keeps the slot alive when a JobAttempted listener throws', function () {
    $total = array_filter(torque_job_attempted_log(), static fn (array $row): bool => $row['type'] === 'attempted');

    expect(torque_attempts_of('probe:boom'))->toHaveCount(1)
        ->and($total)->toHaveCount(9);
});

it('reports a throwing JobAttempted listener through the exception handler', function () {
    $reported = array_filter(torque_job_attempted_log(), static fn (array $row): bool => $row['type'] === 'reported');

    expect(array_count_values(array_column($reported, 'message'))['JobAttempted listener threw'] ?? 0)->toBe(1);
});

it('reports each thrown job exception once, and a job failing itself not at all', function () {
    $messages = array_column(
        array_filter(torque_job_attempted_log(), static fn (array $row): bool => $row['type'] === 'reported'),
        'message',
    );

    // Two attempts threw, and the refused job threw before its handler ran.
    expect(array_count_values($messages)['probe threw'] ?? 0)->toBe(2)
        ->and(array_filter($messages, static fn (string $message): bool => str_contains($message, 'attempted too many times')))->toHaveCount(1)
        ->and($messages)->not->toContain('probe failed');
});

// The 1000 ms threshold leaves room for a cold first job (autoloading, a loaded
// CI box) to stay under it; the slow job sleeps past it on every run.
it('fires Queue::whenJobRunsLongerThan for a job over the threshold only', function () {
    $long = array_values(array_filter(torque_job_attempted_log(), static fn (array $row): bool => $row['type'] === 'long'));

    expect(array_column($long, 'job'))->toBe(['probe:slow'])
        ->and($long[0]['duration'])->toBeGreaterThan(1_000);
});

it('runs deferred callbacks once for the attempts entitled to them, and not for the others', function () {
    $rows = torque_defer_concurrency_log();

    expect(array_filter($rows, static fn (array $row): bool => $row['type'] === 'timeout'))->toBe([])
        ->and(torque_defer_jobs_of($rows, 'attempted'))->toHaveCount(10)
        ->and(torque_defer_jobs_of($rows, 'deferred'))->toBe([
            'always-failure',
            'child-success',
            'ends-meanwhile',
            'first-success',
            'outlives-failure',
            'runs-after-end',
            'suspends',
        ]);
});

it('does not run a job\'s deferred callback before that job ended', function () {
    $rows = torque_defer_concurrency_log();

    $position = static fn (string $type, string $job): int|false => array_search(
        ['type' => $type, 'job' => "defer:{$job}"],
        array_map(static fn (array $row): array => array_intersect_key($row, ['type' => 0, 'job' => 0]), $rows),
    );

    foreach (['first-success', 'runs-after-end', 'child-success', 'always-failure', 'outlives-failure', 'ends-meanwhile'] as $job) {
        expect($position('handle-end', $job))->toBeInt()
            ->and($position('deferred', $job))->toBeGreaterThan($position('handle-end', $job));
    }
});

it('fires nothing for a job abandoned by the forced exit', function () {
    ['rows' => $rows, 'output' => $output] = torque_forced_exit_run();

    expect($output)->toContain('Drain window expired; forcing exit')
        ->and(torque_defer_jobs_of($rows, 'handle-start'))->toBe(['abandoned', 'finisher', 'stuck-in-failure'])
        ->and(torque_defer_jobs_of($rows, 'attempted'))->toBe(['finisher'])
        ->and(torque_defer_jobs_of($rows, 'deferred'))->toBe(['finisher']);

    expect(array_filter($rows, static fn (array $row): bool => $row['type'] === 'long'))->toBe([]);
});

it('fires nothing for jobs caught in a job\'s own exit()', function () {
    $rows = torque_job_exit_log();

    expect(torque_defer_jobs_of($rows, 'handle-start'))->toBe(['bystander', 'exiter', 'stuck-in-failure'])
        ->and(torque_defer_jobs_of($rows, 'attempted'))->toBe([])
        ->and(torque_defer_jobs_of($rows, 'deferred'))->toBe([]);
});
