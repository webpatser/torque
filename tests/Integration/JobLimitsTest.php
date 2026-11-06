<?php

declare(strict_types=1);

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\JobLimitsMethodProbeJob;
use Webpatser\Torque\Tests\Fixtures\JobLimitsProbeJob;

/*
|--------------------------------------------------------------------------
| Limits a job declares, and the stream's limits for jobs that declare none
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/job-limits-worker.php) consumes one real
| job from a real stream, until max_jobs attempts have ended. Each test reads
| which attempts ran and what was left in Redis. The fixture logs the real delay
| of a retry and then shortens it, so these tests do not wait out their backoffs.
|
| Requires a running Redis instance.
|
*/

/**
 * @param  array<string, mixed>  $stream  Config of the job's stream.
 * @param  array<string, mixed>  $options  Top-level worker options.
 * @param  array<string, mixed>  $payload  Fields to overwrite in the queued payload, for a job far along.
 * @return array{attempts: list<int>, backoffs: list<int>, delays: list<int|null>, failed: list<string>, output: string, waiting: int, pending: int, deadLetters: int, reasons: list<string|null>, payloads: list<array<string, mixed>>}
 */
function torque_run_limits_worker(object $job, array $stream, int $maxJobs, array $options = [], ?int $later = null, array $payload = []): array
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-limits-{$token}:";
    $group = 'limits-test';
    $queueName = 'limits';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-limits-{$token}.jsonl";
    $outputFile = "{$directory}/torque-limits-{$token}.out";

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

    if ($later === null) {
        (void) $queue->push($job, '', $queueName);
    } else {
        (void) $queue->later($later, $job, '', $queueName);
    }

    if ($payload !== []) {
        [[$id, $fields]] = $redis->execute('XRANGE', $prefix.$queueName, '-', '+');
        $redis->execute('XDEL', $prefix.$queueName, (string) $id);
        $redis->execute('XADD', $prefix.$queueName, '*', 'payload', json_encode($payload + json_decode((string) $fields[1], true), JSON_THROW_ON_ERROR));
    }

    $settings = ['stream' => $stream, 'max_jobs' => $maxJobs] + $options;

    try {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/job-limits-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, json_encode($settings, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        $deadline = microtime(true) + 30;

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
        $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
        $ofType = static fn (string $type): array => array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === $type));
        $stream = $prefix.$queueName;
        $deadLetters = $redis->execute('XRANGE', $prefix.'dead-letter', '-', '+') ?? [];
        $stored = [
            ...array_map(static fn (array $entry): string => (string) $entry[1][1], $redis->execute('XRANGE', $stream, '-', '+') ?? []),
            ...array_map(strval(...), $redis->execute('ZRANGE', $stream.':delayed', '0', '-1') ?? []),
        ];

        return [
            'reasons' => array_map(static function (array $entry): ?string {
                foreach (array_chunk($entry[1], 2) as [$field, $value]) {
                    if ((string) $field === 'reason') {
                        return (string) $value;
                    }
                }

                return null;
            }, $deadLetters),
            'payloads' => array_map(static fn (string $body): array => json_decode($body, true, flags: JSON_THROW_ON_ERROR), $stored),
            'attempts' => array_column($ofType('handled'), 'attempt'),
            'backoffs' => array_column($ofType('released'), 'backoff'),
            'delays' => array_column($ofType('released'), 'delayed_for'),
            'failed' => array_column($ofType('failed'), 'exception'),
            'output' => $output,
            'waiting' => (int) $redis->execute('XLEN', $stream) + (int) $redis->execute('ZCARD', $stream.':delayed'),
            'pending' => (int) ($redis->execute('XPENDING', $stream, $group)[0] ?? 0),
            'deadLetters' => count($redis->execute('XRANGE', $prefix.'dead-letter', '-', '+') ?? []),
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

describe('jobs that declare nothing', function () {
    it('retries up to the stream limit with 2**attempts backoff, then dead-letters', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob, ['max_retries' => 3], maxJobs: 3);

        expect($run['attempts'])->toBe([1, 2, 3])
            ->and($run['backoffs'])->toBe([2, 4])
            ->and($run['delays'][0])->toBeIn([1, 2])
            ->and($run['delays'][1])->toBeIn([3, 4])
            ->and($run['deadLetters'])->toBe(1)
            ->and($run['waiting'])->toBe(0)
            ->and($run['pending'])->toBe(0);
    });

    it('dead-letters at the first failure with max_retries 0', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob, ['max_retries' => 0], maxJobs: 1);

        expect($run['attempts'])->toBe([1])
            ->and($run['backoffs'])->toBe([])
            ->and($run['deadLetters'])->toBe(1);
    });

    it('lets a job release itself past the stream limit', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('release', until: 4), ['max_retries' => 2], maxJobs: 4);

        expect($run['attempts'])->toBe([1, 2, 3, 4])
            ->and($run['backoffs'])->toBe([])
            ->and($run['deadLetters'])->toBe(0)
            ->and($run['waiting'])->toBe(0);
    });

    it('does not take a dispatch delay for a backoff', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob, ['max_retries' => 3], maxJobs: 1, later: 1);

        expect($run['attempts'])->toBe([1])
            ->and($run['backoffs'])->toBe([2]);
    });
});

describe('declared attempts', function () {
    it('stops below the stream limit at a lower $tries', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 1), ['max_retries' => 3], maxJobs: 1);

        expect($run['attempts'])->toBe([1])
            ->and($run['deadLetters'])->toBe(1)
            ->and($run['waiting'])->toBe(0);
    });

    it('goes past the stream limit at a higher $tries', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 3, backoff: 0), ['max_retries' => 1], maxJobs: 3);

        expect($run['attempts'])->toBe([1, 2, 3])
            ->and($run['deadLetters'])->toBe(1);
    });

    it('has no limit at $tries = 0', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(until: 5, tries: 0, backoff: 0), ['max_retries' => 2], maxJobs: 5);

        expect($run['attempts'])->toBe([1, 2, 3, 4, 5])
            ->and($run['deadLetters'])->toBe(0)
            ->and($run['waiting'])->toBe(0);
    });

    it('fails on the first exception once retryUntil has passed, whatever $tries says', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 5, backoff: 0, retryUntil: time() - 10), ['max_retries' => 3], maxJobs: 1);

        expect($run['attempts'])->toBe([1])
            ->and($run['backoffs'])->toBe([])
            ->and($run['deadLetters'])->toBe(1);
    });

    it('keeps retrying past $tries until retryUntil has passed', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(until: 3, tries: 1, backoff: 0, retryUntil: time() + 60), ['max_retries' => 1], maxJobs: 3);

        expect($run['attempts'])->toBe([1, 2, 3])
            ->and($run['deadLetters'])->toBe(0);
    });

    it('fails at maxExceptions with attempts to spare', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 10, backoff: 0, maxExceptions: 2), ['max_retries' => 10], maxJobs: 2);

        expect($run['attempts'])->toBe([1, 2])
            ->and($run['backoffs'])->toBe([0])
            ->and($run['deadLetters'])->toBe(1)
            ->and($run['waiting'])->toBe(0);
    });

    it('reads limits declared through tries() and backoff()', function () {
        $run = torque_run_limits_worker(new JobLimitsMethodProbeJob(2, [0]), ['max_retries' => 5], maxJobs: 2);

        expect($run['attempts'])->toBe([1, 2])
            ->and($run['backoffs'])->toBe([0])
            ->and($run['deadLetters'])->toBe(1);
    });

    it('reads a deadline declared through retryUntil()', function () {
        $run = torque_run_limits_worker(new JobLimitsMethodProbeJob(5, [0], time() - 10), ['max_retries' => 5], maxJobs: 1);

        expect($run['attempts'])->toBe([1])
            ->and($run['deadLetters'])->toBe(1);
    });
});

describe('backoff', function () {
    it('waits an integer $backoff', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(until: 2, tries: 2, backoff: 1), ['max_retries' => 2], maxJobs: 2);

        expect($run['attempts'])->toBe([1, 2])
            ->and($run['backoffs'])->toBe([1])
            ->and($run['delays'][0])->toBeIn([0, 1]);
    });

    it('takes an array $backoff per attempt, staying on the last step', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 4, backoff: [1, 0]), ['max_retries' => 4], maxJobs: 4);

        expect($run['attempts'])->toBe([1, 2, 3, 4])
            ->and($run['backoffs'])->toBe([1, 0, 0]);
    });

    it('takes a numeric stream backoff, in any form the config allows', function (mixed $backoff, array $expected) {
        $run = torque_run_limits_worker(new JobLimitsProbeJob, ['max_retries' => 3, 'backoff' => $backoff], maxJobs: 3);

        expect($run['attempts'])->toBe([1, 2, 3])
            ->and($run['backoffs'])->toBe($expected);
    })->with([
        'integer' => [1, [1, 1]],
        'zero' => [0, [0, 0]],
        'numeric string' => ['0', [0, 0]],
        'comma string' => ['0,1', [0, 1]],
        'array' => [[0, 1], [0, 1]],
    ]);

    it('takes exponential as 2**attempts', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob, ['max_retries' => 3, 'backoff' => 'exponential'], maxJobs: 3);

        expect($run['backoffs'])->toBe([2, 4]);
    });

    it('leaves a job that declares nothing at the default max_retries on 2 and 4 seconds', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob, [], maxJobs: 3);

        expect($run['attempts'])->toBe([1, 2, 3])
            ->and($run['backoffs'])->toBe([2, 4])
            ->and($run['deadLetters'])->toBe(1);
    });

    it('caps exponential backoff at an hour, for a job far along on its $tries', function (int $priorTries, int $expected) {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 25), ['max_retries' => 3], maxJobs: 1, payload: ['attempts' => $priorTries]);

        expect($run['attempts'])->toBe([$priorTries + 1])
            ->and($run['backoffs'])->toBe([$expected])
            ->and($run['deadLetters'])->toBe(0);
    })->with([
        'below the cap' => [10, 2048],
        'above the cap' => [16, 3600],
    ]);

    it('does not raise exponential backoff for a job that released itself first', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('release', tries: 25, throwAt: 4), ['max_retries' => 3], maxJobs: 4);

        expect($run['attempts'])->toBe([1, 2, 3, 4])
            ->and($run['backoffs'])->toBe([2]);
    });

    it('caps exponential backoff for a job far along with a far retryUntil', function () {
        $job = new JobLimitsProbeJob(retryUntil: time() + 86400);

        $run = torque_run_limits_worker($job, ['max_retries' => 3], maxJobs: 1, payload: ['attempts' => 19]);

        expect($run['attempts'])->toBe([20])
            ->and($run['backoffs'])->toBe([3600])
            ->and($run['delays'][0])->toBeIn([3599, 3600]);
    });

    it('clamps exponential backoff to retryUntil, leaving time for a run before it', function () {
        $job = new JobLimitsProbeJob(until: 8, retryUntil: time() + 40);

        $run = torque_run_limits_worker($job, ['max_retries' => 3], maxJobs: 2, payload: ['attempts' => 6]);

        expect($run['attempts'])->toBe([7, 8])
            ->and($run['backoffs'][0])->toBeLessThanOrEqual(38)->toBeGreaterThan(20)
            ->and($run['deadLetters'])->toBe(0);
    });

    it('does not clamp a declared $backoff to retryUntil', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(backoff: 500, retryUntil: time() + 60), ['max_retries' => 3], maxJobs: 1);

        expect($run['backoffs'])->toBe([500]);
    });

    it('retries a TimeoutExceededException on its limits, unlike its parent exception', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('timeout', tries: 3), ['max_retries' => 3], maxJobs: 3);

        expect($run['attempts'])->toBe([1, 2, 3])
            ->and($run['backoffs'])->toBe([2, 4])
            ->and($run['failed'])->toBe([TimeoutExceededException::class])
            ->and($run['deadLetters'])->toBe(1);
    });

    it('warns at boot about a stream backoff it cannot read, and uses 2**attempts', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob, ['max_retries' => 3, 'backoff' => 'linear'], maxJobs: 1);

        expect($run['backoffs'])->toBe([2])
            ->and($run['output'])->toContain('WARN')
            ->and($run['output'])->toContain('linear');
    });

    it('prefers $backoff over the stream backoff', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(backoff: 0), ['max_retries' => 2, 'backoff' => 7], maxJobs: 2);

        expect($run['backoffs'])->toBe([0]);
    });
});

describe('job_limits: stream', function () {
    it('ignores everything a job and the stream backoff declare', function () {
        $job = new JobLimitsProbeJob(tries: 1, backoff: 0, maxExceptions: 1);

        $run = torque_run_limits_worker($job, ['max_retries' => 2, 'backoff' => 0], maxJobs: 2, options: ['job_limits' => 'stream']);

        expect($run['attempts'])->toBe([1, 2])
            ->and($run['backoffs'])->toBe([2])
            ->and($run['deadLetters'])->toBe(1);
    });

    it('also switches strict_attempts off', function () {
        $job = new JobLimitsProbeJob('release', until: 3, tries: 1);

        $run = torque_run_limits_worker($job, ['max_retries' => 1], maxJobs: 3, options: ['job_limits' => 'stream', 'strict_attempts' => true]);

        expect($run['attempts'])->toBe([1, 2, 3])
            ->and($run['deadLetters'])->toBe(0)
            ->and($run['output'])->toContain('strict_attempts and release_limit_seconds are ignored while job_limits is stream');
    });
});

describe('release limit', function () {
    $past = fn (): array => ['attempts' => 2, 'torque_releases' => 2, 'torque_release_deadline' => time() - 10];

    it('dead-letters a real release() loop once its limit has passed', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('release'), ['max_retries' => 3], maxJobs: 100000, options: ['release_limit_seconds' => 1, 'lifetime' => 3]);

        expect(count($run['attempts']))->toBeGreaterThan(1)
            ->and($run['failed'])->toBe([MaxAttemptsExceededException::class])
            ->and($run['reasons'])->toBe(['release_limit'])
            ->and($run['waiting'])->toBe(0)
            ->and($run['pending'])->toBe(0)
            ->and($run['output'])->toContain('failed without running');
    });

    it('fails a job past its deadline without running it, whatever strict_attempts says', function (bool $strict) use ($past) {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 5), ['max_retries' => 3], maxJobs: 1, options: ['strict_attempts' => $strict], payload: $past());

        expect($run['attempts'])->toBe([])
            ->and($run['reasons'])->toBe(['release_limit'])
            ->and($run['waiting'])->toBe(0);
    })->with(['strict' => [true], 'not strict' => [false]]);

    it('runs a job before its deadline', function () {
        $payload = ['attempts' => 2, 'torque_releases' => 2, 'torque_release_deadline' => time() + 600];

        $run = torque_run_limits_worker(new JobLimitsProbeJob(until: 1, tries: 1), ['max_retries' => 3], maxJobs: 1, options: ['strict_attempts' => true], payload: $payload);

        expect($run['attempts'])->toBe([3])
            ->and($run['deadLetters'])->toBe(0);
    });

    it('does not apply to a job past its deadline when', function (array $jobArguments, array $stream, array $options) use ($past) {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(...['until' => 1, ...$jobArguments]), $stream + ['max_retries' => 3], maxJobs: 1, options: $options, payload: $past());

        expect($run['attempts'])->toBe([3])
            ->and($run['deadLetters'])->toBe(0);
    })->with([
        'the limit is off' => [[], [], ['release_limit_seconds' => 0]],
        'the limit is off for the stream' => [[], ['release_limit_seconds' => 0], []],
        'it has a retryUntil' => [['retryUntil' => time() + 600], [], []],
        'its $tries is 0' => [['tries' => 0], [], []],
        'job_limits is stream' => [[], [], ['job_limits' => 'stream']],
    ]);

    it('ignores the counters of a job whose attempts were reset by queue:retry', function () use ($past) {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(until: 1), ['max_retries' => 3], maxJobs: 1, payload: ['attempts' => 0] + $past());

        expect($run['attempts'])->toBe([1])
            ->and($run['deadLetters'])->toBe(0);
    });
});

describe('what counts as a try', function () {
    it('counts a retry after a throw, and leaves the release counters alone', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 3), ['max_retries' => 3], maxJobs: 1);

        expect($run['backoffs'])->toBe([2])
            ->and($run['payloads'])->toHaveCount(1)
            ->and($run['payloads'][0]['attempts'])->toBe(1)
            ->and($run['payloads'][0])->not->toHaveKeys(['torque_releases', 'torque_release_deadline']);
    });

    it('counts a retry after a real timeout, and leaves the release counters alone', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('hang', tries: 3), ['max_retries' => 3, 'timeout' => 1], maxJobs: 1, options: ['timeouts' => ['enabled' => true]]);

        expect($run['attempts'])->toBe([1])
            ->and($run['backoffs'])->toBe([2])
            ->and($run['payloads'])->toHaveCount(1)
            ->and($run['payloads'][0]['attempts'])->toBe(1)
            ->and($run['payloads'][0])->not->toHaveKeys(['torque_releases', 'torque_release_deadline']);
    });

    it('keeps a release deadline through a retry after a throw', function () {
        $deadline = time() + 600;

        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 3), ['max_retries' => 3], maxJobs: 1, payload: ['attempts' => 1, 'torque_releases' => 1, 'torque_release_deadline' => $deadline]);

        expect($run['payloads'][0])->toMatchArray(['attempts' => 2, 'torque_releases' => 1, 'torque_release_deadline' => $deadline]);
    });

    it('spends tries on throws only, not on the releases between them', function (bool $strict) {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('release', tries: 2, throwAt: 2), ['max_retries' => 3], maxJobs: 3, options: ['strict_attempts' => $strict]);

        expect($run['attempts'])->toBe([1, 2, 3])
            ->and($run['backoffs'])->toBe([2])
            ->and($run['failed'])->toBe([RuntimeException::class])
            ->and($run['deadLetters'])->toBe(1);
    })->with(['strict' => [true], 'not strict' => [false]]);
});

describe('strict_attempts', function () {
    it('off: a release() loop keeps going past every limit, and the worker says so once', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('release', until: 4, tries: 1), ['max_retries' => 1], maxJobs: 4);

        expect($run['attempts'])->toBe([1, 2, 3, 4])
            ->and($run['backoffs'])->toBe([])
            ->and($run['deadLetters'])->toBe(0)
            ->and(substr_count($run['output'], 'strict_attempts is off'))->toBe(1)
            ->and($run['output'])->toContain('is not counted either')
            ->and($run['output'])->not->toContain('neither is one whose worker keeps dying');
    });

    it('on: fails a job past retryUntil without running it', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob(tries: 5, retryUntil: time() - 10), ['max_retries' => 3], maxJobs: 1, options: ['strict_attempts' => true]);

        expect($run['attempts'])->toBe([])
            ->and($run['failed'])->toBe([MaxAttemptsExceededException::class])
            ->and($run['deadLetters'])->toBe(1)
            ->and($run['waiting'])->toBe(0)
            ->and($run['pending'])->toBe(0);
    });

    it('on: does not spend $tries on release()', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('release', until: 5, tries: 2), ['max_retries' => 3], maxJobs: 5, options: ['strict_attempts' => true]);

        expect($run['attempts'])->toBe([1, 2, 3, 4, 5])
            ->and($run['failed'])->toBe([])
            ->and($run['deadLetters'])->toBe(0)
            ->and($run['waiting'])->toBe(0);
    });

    it('on: does not spend the stream limit on release() of a job that declares nothing', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob('release', until: 5), ['max_retries' => 2], maxJobs: 5, options: ['strict_attempts' => true]);

        expect($run['attempts'])->toBe([1, 2, 3, 4, 5])
            ->and($run['deadLetters'])->toBe(0);
    });

    it('on: still gives every job its one run at max_retries 0', function () {
        $run = torque_run_limits_worker(new JobLimitsProbeJob, ['max_retries' => 0], maxJobs: 1, options: ['strict_attempts' => true]);

        expect($run['attempts'])->toBe([1])
            ->and($run['deadLetters'])->toBe(1);
    });
});
