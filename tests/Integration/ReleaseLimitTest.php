<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\ReleaseLimitProbeJob;

/*
|--------------------------------------------------------------------------
| A job that keeps releasing itself is stopped by time, not by its tries
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/release-limit-worker.php) runs a job that
| sits behind the real RateLimited, WithoutOverlapping or ThrottlesExceptions
| middleware. Nothing here releases a job by hand: the middleware does, and the
| worker has to decide when that stops. The limit is a couple of seconds so the
| tests are fast; the fixture pulls the middleware's delays forward.
|
| Requires a running Redis instance.
|
*/

/**
 * @param  array<string, mixed>  $settings  probe, release_limit_seconds, lifetime.
 * @return array{delivered: list<array{attempt: int, t: float}>, handled: list<int>, reasons: list<string|null>, deadLetters: int, waiting: int, output: string, elapsed: float}
 */
function torque_run_release_limit_worker(ReleaseLimitProbeJob $job, array $settings): array
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-release-limit-{$token}:";
    $group = 'release-limit-test';
    $queueName = 'limits';
    $logFile = sys_get_temp_dir()."/torque-release-limit-{$token}.jsonl";
    $outputFile = sys_get_temp_dir()."/torque-release-limit-{$token}.out";

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
    (void) $queue->push($job, '', $queueName);

    $settings += ['stream' => ['max_retries' => 5], 'lifetime' => 6];
    $started = microtime(true);

    try {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/release-limit-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, json_encode($settings, JSON_THROW_ON_ERROR)],
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

        return [
            'delivered' => $ofType('delivered'),
            'handled' => array_column($ofType('handled'), 'attempt'),
            'reasons' => array_map(static function (array $entry): ?string {
                foreach (array_chunk($entry[1], 2) as [$field, $value]) {
                    if ((string) $field === 'reason') {
                        return (string) $value;
                    }
                }

                return null;
            }, $deadLetters),
            'deadLetters' => count($deadLetters),
            'waiting' => (int) $redis->execute('XLEN', $stream) + (int) $redis->execute('ZCARD', $stream.':delayed'),
            'output' => $output,
            'elapsed' => microtime(true) - $started,
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

/** @param array{delivered: list<array{attempt: int, t: float}>} $run */
function torque_release_limit_span(array $run): float
{
    $times = array_column($run['delivered'], 't');

    return $times === [] ? 0.0 : max($times) - min($times);
}

describe('RateLimited', function () {
    it('outlives its $tries, then dead-letters once the release limit passes', function () {
        $run = torque_run_release_limit_worker(
            new ReleaseLimitProbeJob('rate_limit', tries: 2),
            ['probe' => 'rate_limit', 'release_limit_seconds' => 2],
        );

        expect(count($run['delivered']))->toBeGreaterThan(2)
            ->and(max(array_column($run['delivered'], 'attempt')))->toBeGreaterThan(2)
            ->and($run['handled'])->toBe([])
            ->and($run['reasons'])->toBe(['release_limit'])
            ->and($run['waiting'])->toBe(0)
            ->and(torque_release_limit_span($run))->toBeGreaterThanOrEqual(2.0)
            ->and($run['elapsed'])->toBeLessThan(15.0);
    });

    it('is not limited at $tries = 0', function () {
        $run = torque_run_release_limit_worker(
            new ReleaseLimitProbeJob('rate_limit', tries: 0),
            ['probe' => 'rate_limit', 'release_limit_seconds' => 2],
        );

        expect($run['deadLetters'])->toBe(0)
            ->and(torque_release_limit_span($run))->toBeGreaterThan(3.0);
    });

    it('is limited by retryUntil instead of the release limit', function () {
        $run = torque_run_release_limit_worker(
            new ReleaseLimitProbeJob('rate_limit', tries: 2, retryUntil: time() + 120),
            ['probe' => 'rate_limit', 'release_limit_seconds' => 2],
        );

        expect($run['deadLetters'])->toBe(0)
            ->and(torque_release_limit_span($run))->toBeGreaterThan(3.0);
    });
});

describe('WithoutOverlapping', function () {
    it('does not spend tries while another job holds the lock', function () {
        $run = torque_run_release_limit_worker(
            new ReleaseLimitProbeJob('overlap', tries: 2),
            ['probe' => 'overlap', 'release_limit_seconds' => 86400, 'lifetime' => 5],
        );

        expect(count($run['delivered']))->toBeGreaterThan(2)
            ->and(max(array_column($run['delivered'], 'attempt')))->toBeGreaterThan(2)
            ->and($run['handled'])->toBe([])
            ->and($run['deadLetters'])->toBe(0)
            ->and($run['waiting'])->toBe(1);
    });
});

describe('ThrottlesExceptions', function () {
    it('dead-letters a job whose handle always throws at the release limit, and does not loop on', function () {
        // The shape of ikregeer's SyncKoopDocumentFromApiJob.
        $run = torque_run_release_limit_worker(
            new ReleaseLimitProbeJob('throttle', tries: 3),
            ['probe' => 'throttle', 'release_limit_seconds' => 2],
        );

        // The middleware catches every exception and releases, so the worker never sees
        // one: the throws spend no tries, and only the release limit ends the job. Once
        // three exceptions are within the window it releases without calling handle.
        expect($run['handled'])->toHaveCount(3)
            ->and(count($run['delivered']))->toBeGreaterThan(3)
            ->and(max(array_column($run['delivered'], 'attempt')))->toBeGreaterThan(3)
            ->and($run['reasons'])->toBe(['release_limit'])
            ->and($run['waiting'])->toBe(0)
            ->and($run['elapsed'])->toBeLessThan(15.0);
    });
});
