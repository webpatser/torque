<?php

declare(strict_types=1);

use Fledge\Async\Redis\Connection\RedisTimeoutException;
use Fledge\Async\TimeoutCancellation;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\StealProbeJob;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\async;

/*
|--------------------------------------------------------------------------
| Worker Steal Path Tests
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/steal-worker.php) against a PEL the test
| arranges. A long job must not be claimed by its own worker, a dead consumer's
| message must be stolen past it, entries trimmed from the stream must not break
| the reads, and a cluster hash tag must not hide a stream's settings.
|
| XCLAIM ... IDLE ages an entry without waiting, so the scenarios that need
| "idle past the threshold" do not depend on timing.
|
| The cluster tests run on a single node, with the same hash-tagged key names a
| cluster would see. `q` and `{q}` are different keys here too, so a command
| aimed at the wrong one does nothing and the stream contents and PEL show it.
| What a single node cannot show is slot routing: MOVED and CROSSSLOT.
|
| Requires a running Redis instance.
|
*/

const TORQUE_STEAL_GROUP = 'steal-group';

function torque_steal_queue(string $prefix, string $queueName, bool $cluster): StreamQueue
{
    $queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: $queueName,
        retryAfter: 90,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: TORQUE_STEAL_GROUP,
        cluster: $cluster,
    );
    $queue->setContainer(app());
    $queue->setConnectionName('torque');
    $queue->ensureConsumerGroup($queue->getStreamKey(), TORQUE_STEAL_GROUP);

    return $queue;
}

/**
 * Run a real worker over an arranged queue until it exits by itself.
 *
 * @param  array<string, mixed>  $settings  Worker config; `queues` and `streams` are required.
 * @param  Closure(StreamQueue, string $streamKey): void  $arrange  Runs before the worker starts.
 * @param  (Closure(StreamQueue, string $streamKey, Closure(string): void $waitForStart, Closure(string): void $waitForOutput): void)|null  $whileRunning
 * @param  (Closure(StreamQueue, string $prefix): void)|null  $afterExit  Runs after the worker exits, before the counts are read.
 * @return array{rows: list<array<string, mixed>>, output: string, reserved: int, size: int, keys: list<string>}
 */
function torque_steal_run(array $settings, Closure $arrange, ?Closure $whileRunning = null, ?Closure $afterExit = null): array
{
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-steal-{$token}:";
    $logFile = sys_get_temp_dir()."/torque-steal-{$token}.jsonl";
    $outputFile = sys_get_temp_dir()."/torque-steal-{$token}.out";
    $cluster = (bool) ($settings['redis']['cluster'] ?? false);
    $queueName = $settings['queues'][0];

    $readRows = static fn (): array => array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [],
    );

    $queue = torque_steal_queue($prefix, $queueName, $cluster);
    $streamKey = $queue->getStreamKey();
    $process = null;

    try {
        $arrange($queue, $streamKey);

        $config = array_replace_recursive([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $prefix, 'cluster' => false],
            'consumer_group' => TORQUE_STEAL_GROUP,
            'serializer' => 'json',
            'coroutines_per_worker' => 3,
            'block_for' => 100,
            'max_worker_lifetime' => 40,
            'max_worker_lifetime_jitter' => 0,
            'drain_grace_seconds' => 10,
        ], $settings);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/steal-worker.php', $logFile, json_encode($config, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        if ($whileRunning !== null) {
            $whileRunning($queue, $streamKey, static function (string $job) use ($readRows, $outputFile): void {
                $deadline = microtime(true) + 20;

                while (microtime(true) < $deadline) {
                    if (in_array(['type' => 'start', 'job' => $job], $readRows(), true)) {
                        return;
                    }

                    usleep(20_000);
                }

                throw new RuntimeException("Job {$job} never started:\n".@file_get_contents($outputFile));
            }, static function (string $text) use ($outputFile): void {
                $deadline = microtime(true) + 10;

                while (microtime(true) < $deadline) {
                    if (str_contains((string) @file_get_contents($outputFile), $text)) {
                        return;
                    }

                    usleep(20_000);
                }

                throw new RuntimeException("The worker never wrote '{$text}':\n".@file_get_contents($outputFile));
            });
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
        $process = null;

        $output = (string) @file_get_contents($outputFile);

        if ($timedOut) {
            throw new RuntimeException("The worker timed out:\n{$output}");
        }

        if ($afterExit !== null) {
            $afterExit($queue, $prefix);
        }

        return [
            'rows' => $readRows(),
            'output' => $output,
            'reserved' => $queue->reservedSize(),
            'size' => $queue->size(),
            'keys' => torque_steal_keys($prefix),
        ];
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }

        @unlink($logFile);
        @unlink($outputFile);

        torque_steal_forget($prefix);
    }
}

/**
 * Every key under a prefix, without the prefix.
 *
 * @return list<string>
 */
function torque_steal_keys(string $prefix): array
{
    $redis = torqueRedis();
    $cursor = '0';
    $found = [];

    do {
        [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');
        $cursor = (string) $cursor;

        foreach ($keys ?? [] as $key) {
            $found[] = substr((string) $key, strlen($prefix));
        }
    } while ($cursor !== '0');

    return array_values(array_unique($found));
}

function torque_steal_forget(string $prefix): void
{
    foreach (torque_steal_keys($prefix) as $key) {
        torqueRedis()->execute('DEL', $prefix.$key);
    }
}

/**
 * The dead-letter handler the dashboard gets: the container's, built from config.
 *
 * @param  list<string>  $streams  Configured stream names.
 */
function torque_steal_dead_letters(string $prefix, array $streams, bool $cluster): DeadLetterHandler
{
    config([
        'torque.redis.uri' => torqueRedisUri(),
        'torque.redis.prefix' => $prefix,
        'torque.redis.cluster' => $cluster,
        'torque.streams' => array_fill_keys($streams, []),
    ]);

    app()->forgetInstance(DeadLetterHandler::class);

    return app(DeadLetterHandler::class);
}

/**
 * Retry the way the dashboard does: rescue() swallows a refused retry, so only
 * the stream contents tell whether it worked.
 */
function torque_steal_retry(DeadLetterHandler $handler, string $id): void
{
    rescue(static fn () => $handler->retry($id), null, false);
}

/**
 * The payloads held by a stream.
 *
 * @return list<string>
 */
function torque_steal_payloads(string $streamKey): array
{
    $entries = torqueRedis()->execute('XRANGE', $streamKey, '-', '+') ?: [];

    return array_map(static fn (array $entry): string => (string) $entry[1][1], $entries);
}

/**
 * How many times each job's handler started.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, int>
 */
function torque_steal_starts(array $rows): array
{
    $starts = array_column(array_filter($rows, static fn (array $row): bool => $row['type'] === 'start'), 'job');

    return array_count_values($starts);
}

/**
 * The most handlers running at the same moment.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function torque_steal_peak(array $rows): int
{
    $running = 0;
    $peak = 0;

    foreach ($rows as $row) {
        $running += match ($row['type']) {
            'start' => 1,
            'end' => -1,
            default => 0,
        };
        $peak = max($peak, $running);
    }

    return $peak;
}

/**
 * Deliver every waiting message to a consumer that will never come back.
 *
 * @return list<string> The delivered ids.
 */
function torque_steal_deliver_to_dead_consumer(StreamQueue $queue, string $streamKey, int $count): array
{
    $read = $queue->getRedisClient()->execute('XREADGROUP', 'GROUP', TORQUE_STEAL_GROUP, 'dead-consumer', 'COUNT', (string) $count, 'STREAMS', $streamKey, '>');

    return array_map(static fn (array $message): string => (string) $message[0], $read[0][1]);
}

/**
 * Make a pending entry look idle for two minutes, with the given owner.
 */
function torque_steal_age(StreamQueue $queue, string $streamKey, string $id, string $owner): void
{
    $queue->getRedisClient()->execute('XCLAIM', $streamKey, TORQUE_STEAL_GROUP, $owner, '0', $id, 'IDLE', '120000', 'JUSTID');
}

it('runs a job that outlives the steal threshold exactly once in a worker with free slots', function () {
    $run = torque_steal_run(
        [
            'queues' => ['stealing'],
            'streams' => ['stealing' => ['retry_after' => 1]],
            'max_jobs_per_worker' => 1,
        ],
        static function (StreamQueue $queue): void {
            (void) $queue->push(new StealProbeJob('long', seconds: 3.5), '', 'stealing');
        },
    );

    expect(torque_steal_starts($run['rows']))->toBe(['long' => 1])
        ->and($run['reserved'])->toBe(0);
});

it('still steals a dead consumer\'s message behind a job the worker is running', function () {
    $ids = [];

    $run = torque_steal_run(
        [
            'queues' => ['stealing'],
            'streams' => ['stealing' => ['retry_after' => 60]],
            'max_jobs_per_worker' => 2,
        ],
        static function (StreamQueue $queue, string $streamKey) use (&$ids): void {
            (void) $queue->push(new StealProbeJob('long', seconds: 5.0), '', 'stealing');
            (void) $queue->push(new StealProbeJob('dead'), '', 'stealing');

            $ids = torque_steal_deliver_to_dead_consumer($queue, $streamKey, 2);

            // Only the first is stale, so that is the one the worker takes.
            torque_steal_age($queue, $streamKey, $ids[0], 'dead-consumer');
        },
        static function (StreamQueue $queue, string $streamKey, Closure $waitForStart) use (&$ids): void {
            $waitForStart('long');

            // A pending read refreshes the idle time of everything it lists, and
            // the slots still starting up do one. Age the entries after those.
            usleep(500_000);

            $pending = $queue->getRedisClient()->execute('XPENDING', $streamKey, TORQUE_STEAL_GROUP, '-', '+', '10');
            $owners = array_column($pending, 1, 0);

            // The running job is first in the PEL and both are now past the
            // threshold: every scan of a free slot meets the running job first.
            torque_steal_age($queue, $streamKey, $ids[0], (string) $owners[$ids[0]]);
            torque_steal_age($queue, $streamKey, $ids[1], 'dead-consumer');
        },
    );

    $finished = array_column(array_filter($run['rows'], static fn (array $row): bool => $row['type'] === 'end'), 'job');

    expect(torque_steal_starts($run['rows']))->toEqualCanonicalizing(['long' => 1, 'dead' => 1])
        ->and($finished)->toBe(['dead', 'long'])
        ->and($run['reserved'])->toBe(0);
});

it('survives a dead consumer\'s entry trimmed by clear(), leaving an empty PEL', function () {
    $run = torque_steal_run(
        [
            'queues' => ['stealing'],
            'streams' => ['stealing' => ['retry_after' => 60]],
            'max_jobs_per_worker' => 1,
        ],
        static function (StreamQueue $queue, string $streamKey): void {
            (void) $queue->push(new StealProbeJob('trimmed'), '', 'stealing');

            [$id] = torque_steal_deliver_to_dead_consumer($queue, $streamKey, 1);

            // Age first: XCLAIM on a trimmed id drops it from the PEL on Redis 7+.
            torque_steal_age($queue, $streamKey, $id, 'dead-consumer');

            expect($queue->clear('stealing'))->toBe(1);

            // The worker must meet a PEL entry without a stream entry. Only its
            // own XAUTOCLAIM can remove it, which the pending count at the end
            // then proves.
            $pel = $queue->getRedisClient()->execute('XPENDING', $streamKey, TORQUE_STEAL_GROUP, '-', '+', '10') ?: [];

            expect(array_map(static fn (array $entry): string => (string) $entry[0], $pel))->toBe([$id])
                ->and($queue->getRedisClient()->execute('XRANGE', $streamKey, $id, $id))->toBeEmpty();

            // Keeps the worker up long enough for its free slots to scan.
            (void) $queue->push(new StealProbeJob('after-clear', seconds: 1.5), '', 'stealing');
        },
    );

    expect(torque_steal_starts($run['rows']))->toBe(['after-clear' => 1])
        ->and($run['output'])->not->toContain('failed to read')
        ->and($run['output'])->not->toContain('TypeError')
        ->and($run['reserved'])->toBe(0);
});

// Reading a consumer's own PEL lists a trimmed entry as [id, nil] on every Redis
// version, unlike XAUTOCLAIM, which only does so below 7.
it('survives its own in-flight entry being trimmed when a free slot re-reads the PEL', function () {
    $run = torque_steal_run(
        [
            'queues' => ['stealing'],
            'streams' => ['stealing' => ['retry_after' => 60]],
            'max_jobs_per_worker' => 1,
            // The pending re-read comes every 50 polls, so about every two seconds.
            'block_for' => 40,
        ],
        static function (StreamQueue $queue): void {
            (void) $queue->push(new StealProbeJob('in-flight', seconds: 3.5), '', 'stealing');
        },
        static function (StreamQueue $queue, string $streamKey, Closure $waitForStart): void {
            $waitForStart('in-flight');

            expect($queue->clear('stealing'))->toBe(1);
        },
    );

    expect(torque_steal_starts($run['rows']))->toBe(['in-flight' => 1])
        ->and($run['output'])->not->toContain('failed to read')
        ->and($run['reserved'])->toBe(0);
});

it('applies a stream\'s max_concurrency under a cluster hash tag', function (string $queueName, string $streamsKey) {
    $run = torque_steal_run(
        [
            'redis' => ['cluster' => true],
            'queues' => [$queueName],
            'streams' => [$streamsKey => ['max_concurrency' => 1, 'retry_after' => 60]],
            'max_jobs_per_worker' => 3,
        ],
        static function (StreamQueue $queue) use ($queueName): void {
            foreach (['one', 'two', 'three'] as $name) {
                (void) $queue->push(new StealProbeJob($name, seconds: 0.4), '', $queueName);
            }
        },
    );

    expect(array_sum(torque_steal_starts($run['rows'])))->toBe(3)
        ->and(torque_steal_peak($run['rows']))->toBe(1)
        ->and(array_unique(array_column(array_filter($run['rows'], static fn (array $row): bool => $row['type'] === 'attempted'), 'queue')))->toBe(['capped'])
        ->and($run['reserved'])->toBe(0)
        ->and($run['size'])->toBe(0)
        ->and($run['keys'])->toContain('{capped}')
        ->and($run['keys'])->not->toContain('capped');
})->with([
    'plain queue, plain streams key' => ['capped', 'capped'],
    'tagged queue, plain streams key' => ['{capped}', 'capped'],
    'plain queue, tagged streams key' => ['capped', '{capped}'],
    'tagged queue, tagged streams key' => ['{capped}', '{capped}'],
]);

// Many slots on a short poll make the reads overlap: each slot that passed the
// check while the count was still below the cap must not get a job as well.
it('keeps max_concurrency when many slots poll at the same moment', function (int $cap) {
    $run = torque_steal_run(
        [
            'queues' => ['capped'],
            'streams' => ['capped' => ['max_concurrency' => $cap, 'retry_after' => 60]],
            'coroutines_per_worker' => 16,
            'block_for' => 5,
            'max_jobs_per_worker' => 20,
        ],
        static function (StreamQueue $queue): void {
            foreach (range(1, 20) as $number) {
                (void) $queue->push(new StealProbeJob("job-{$number}", seconds: 0.02), '', 'capped');
            }
        },
    );

    expect(array_sum(torque_steal_starts($run['rows'])))->toBe(20)
        ->and(torque_steal_peak($run['rows']))->toBe($cap)
        ->and($run['reserved'])->toBe(0)
        ->and($run['size'])->toBe(0);
})->with([
    'cap of one' => [1],
    'cap of two' => [2],
]);

it('gives up on a read when the server accepts the connection and never answers', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $worker = new WorkerProcess(['redis' => ['uri' => torqueRedisUri(), 'read_timeout' => 0.2]]);
    $client = $worker->createReaderClient('redis://'.stream_socket_get_name($server, false));

    // The outer limit turns a client that would wait forever into a failure.
    expect(static fn () => async(static fn () => $client->execute('XREADGROUP', 'GROUP', 'g', 'c', 'STREAMS', 's', '>'))->await(new TimeoutCancellation(2.0)))
        ->toThrow(RedisTimeoutException::class, 'no response to XREADGROUP');
});

// Called directly: in a running worker a free slot's XAUTOCLAIM also drops a
// trimmed entry from the PEL, on Redis 7 and up, whatever the entry's idle time.
it('leaves a trimmed entry a slot is running for that slot to acknowledge', function () {
    $prefix = 'torque-steal-'.bin2hex(random_bytes(4)).':';
    $queue = torque_steal_queue($prefix, 'stealing', cluster: false);
    $streamKey = $queue->getStreamKey();

    try {
        (void) $queue->push(new StealProbeJob('running'), '', 'stealing');
        $read = torqueRedis()->execute('XREADGROUP', 'GROUP', TORQUE_STEAL_GROUP, 'me', 'STREAMS', $streamKey, '>');
        $id = (string) $read[0][1][0][0];
        (void) $queue->clear('stealing');

        $worker = new WorkerProcess(['redis' => ['uri' => torqueRedisUri()]]);
        (new ReflectionProperty($worker, 'consumerId'))->setValue($worker, 'me');
        (new ReflectionProperty($worker, 'inFlight'))->setValue($worker, ["{$streamKey}|{$id}" => true]);

        $message = (new ReflectionMethod($worker, 'readPendingMessage'))->invoke(
            $worker,
            torqueRedis(),
            ['stealing'],
            $prefix,
            TORQUE_STEAL_GROUP,
            static fn (string $name): string => $prefix.$name,
        );

        $pending = torqueRedis()->execute('XPENDING', $streamKey, TORQUE_STEAL_GROUP, '-', '+', '10');

        expect($message)->toBeNull()
            ->and(array_map(static fn (array $entry): string => (string) $entry[0], $pending))->toBe([$id]);
    } finally {
        torque_steal_forget($prefix);
    }
});

it('serves a capped stream again after a read that never got an answer', function () {
    $flagFile = tempnam(sys_get_temp_dir(), 'torque-silent-');
    ['host' => $host, 'port' => $port, 'path' => $database] = parse_url(torqueRedisUri());

    $proxy = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Fixtures/redis-silent-proxy.php', "tcp://{$host}:{$port}", $flagFile],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
        $pipes,
    );

    try {
        $address = trim((string) fgets($pipes[1]));

        $run = torque_steal_run(
            [
                'redis' => ['uri' => "redis://{$address}{$database}", 'read_timeout' => 0.2],
                'queues' => ['capped'],
                'streams' => ['capped' => ['max_concurrency' => 1, 'retry_after' => 60]],
                'block_for' => 50,
                'max_jobs_per_worker' => 1,
                'max_worker_lifetime' => 8,
            ],
            static function (StreamQueue $queue): void {
                (void) $queue->push(new StealProbeJob('after-silence'), '', 'capped');
            },
            static function (StreamQueue $queue, string $streamKey, Closure $waitForStart, Closure $waitForOutput) use ($flagFile): void {
                $waitForOutput('no response to XREADGROUP');

                unlink($flagFile);
            },
        );

        expect(torque_steal_starts($run['rows']))->toBe(['after-silence' => 1], $run['output'])
            ->and($run['reserved'])->toBe(0)
            ->and($run['size'])->toBe(0);
    } finally {
        proc_terminate($proxy, SIGKILL);
        proc_close($proxy);

        @unlink($flagFile);
    }
});

it('serves a capped stream again after a failing read', function () {
    $run = torque_steal_run(
        [
            'queues' => ['capped'],
            'streams' => ['capped' => ['max_concurrency' => 1, 'retry_after' => 60]],
            'block_for' => 50,
            'max_jobs_per_worker' => 2,
            'max_worker_lifetime' => 8,
        ],
        static function (StreamQueue $queue): void {
            (void) $queue->push(new StealProbeJob('before'), '', 'capped');
        },
        static function (StreamQueue $queue, string $streamKey, Closure $waitForStart, Closure $waitForOutput): void {
            $waitForStart('before');

            // The job must finish and acknowledge before its group goes away.
            while ($queue->reservedSize('capped') > 0) {
                usleep(10_000);
            }

            // Every read now fails with NOGROUP, the way it does after a flush.
            $queue->getRedisClient()->execute('XGROUP', 'DESTROY', $streamKey, TORQUE_STEAL_GROUP);

            $waitForOutput('failed to read');

            $queue->getRedisClient()->execute('XGROUP', 'CREATE', $streamKey, TORQUE_STEAL_GROUP, '$', 'MKSTREAM');

            (void) $queue->push(new StealProbeJob('after'), '', 'capped');
        },
    );

    expect(torque_steal_starts($run['rows']))->toBe(['before' => 1, 'after' => 1])
        ->and($run['output'])->toContain('NOGROUP')
        ->and($run['reserved'])->toBe(0)
        ->and($run['size'])->toBe(0);
});

it('applies a stream\'s max_retries under a cluster hash tag', function () {
    $run = torque_steal_run(
        [
            'redis' => ['cluster' => true],
            'queues' => ['capped'],
            'streams' => ['capped' => ['max_retries' => 1, 'retry_after' => 60]],
            'max_jobs_per_worker' => 1,
        ],
        static function (StreamQueue $queue): void {
            (void) $queue->push(new StealProbeJob('thrower', throws: true), '', 'capped');
        },
    );

    $attempted = array_values(array_filter($run['rows'], static fn (array $row): bool => $row['type'] === 'attempted'));

    expect($attempted)->toHaveCount(1)
        ->and($attempted[0]['failed'])->toBeTrue()
        ->and($attempted[0]['released'])->toBeFalse()
        ->and($attempted[0]['queue'])->toBe('capped')
        ->and($run['reserved'])->toBe(0)
        ->and($run['size'])->toBe(0);
});

it('leaves one copy of a released job under a cluster hash tag', function () {
    $run = torque_steal_run(
        [
            'redis' => ['cluster' => true],
            'queues' => ['capped'],
            'streams' => ['capped' => ['retry_after' => 60]],
            'max_jobs_per_worker' => 2,
        ],
        static function (StreamQueue $queue): void {
            (void) $queue->push(new StealProbeJob('released', releaseOnce: true), '', 'capped');
        },
    );

    expect(torque_steal_starts($run['rows']))->toBe(['released' => 2])
        ->and($run['reserved'])->toBe(0)
        ->and($run['size'])->toBe(0);
});

it('retries a job a cluster worker dead-lettered into the stream it reads', function () {
    $deadLetter = null;

    $run = torque_steal_run(
        [
            'redis' => ['cluster' => true],
            'queues' => ['capped'],
            'streams' => ['capped' => ['max_retries' => 1, 'retry_after' => 60]],
            'max_jobs_per_worker' => 1,
        ],
        static function (StreamQueue $queue): void {
            (void) $queue->push(new StealProbeJob('thrower', throws: true), '', 'capped');
        },
        afterExit: static function (StreamQueue $queue, string $prefix) use (&$deadLetter): void {
            $handler = torque_steal_dead_letters($prefix, ['capped'], cluster: true);
            $deadLetter = $handler->list(1)[0];

            torque_steal_retry($handler, $deadLetter['id']);

            expect($handler->count())->toBe(0)
                // The retried job is a new one: the same payload with attempts back at 0.
                ->and(array_map(static fn (string $p): array => json_decode($p, true), torque_steal_payloads($prefix.'{capped}')))
                ->toBe([array_replace(json_decode($deadLetter['payload'], true), ['attempts' => 0])]);
        },
    );

    expect($deadLetter['original_queue'])->toBe('capped')
        ->and($run['size'])->toBe(1)
        ->and($run['reserved'])->toBe(0)
        ->and($run['keys'])->not->toContain('capped');
});

it('retries a dead-letter entry into the hash-tagged stream', function (string $originalQueue, string $configured) {
    $prefix = 'torque-steal-'.bin2hex(random_bytes(4)).':';
    $handler = torque_steal_dead_letters($prefix, [$configured], cluster: true);

    try {
        $handler->handle($originalQueue, '{"uuid":"dl"}', '1-0', new RuntimeException('boom'));

        torque_steal_retry($handler, $handler->list(1)[0]['id']);

        expect(torque_steal_payloads($prefix.'{capped}'))->toBe(['{"uuid":"dl","attempts":0}'])
            ->and($handler->count())->toBe(0)
            ->and(torque_steal_keys($prefix))->not->toContain('capped');
    } finally {
        torque_steal_forget($prefix);
    }
})->with([
    'plain entry, plain config' => ['capped', 'capped'],
    'tagged entry, plain config' => ['{capped}', 'capped'],
    'plain entry, tagged config' => ['capped', '{capped}'],
    'tagged entry, tagged config' => ['{capped}', '{capped}'],
]);

it('leaves a dead-letter entry with a partial hash tag where it is', function () {
    $prefix = 'torque-steal-'.bin2hex(random_bytes(4)).':';
    $handler = torque_steal_dead_letters($prefix, ['foo{bar}'], cluster: true);

    try {
        $handler->handle('foo{bar}', '{"uuid":"dl"}', '1-0', new RuntimeException('boom'));

        torque_steal_retry($handler, $handler->list(1)[0]['id']);

        expect($handler->count())->toBe(1)
            ->and(torque_steal_keys($prefix))->toBe(['dead-letter']);
    } finally {
        torque_steal_forget($prefix);
    }
});

it('retries a dead-letter entry into the plain stream without cluster mode', function () {
    $prefix = 'torque-steal-'.bin2hex(random_bytes(4)).':';
    $handler = torque_steal_dead_letters($prefix, ['capped'], cluster: false);

    try {
        $handler->handle('capped', '{"uuid":"dl"}', '1-0', new RuntimeException('boom'));

        torque_steal_retry($handler, $handler->list(1)[0]['id']);

        expect(torque_steal_payloads($prefix.'capped'))->toBe(['{"uuid":"dl","attempts":0}'])
            ->and($handler->count())->toBe(0)
            ->and(torque_steal_keys($prefix))->not->toContain('{capped}');
    } finally {
        torque_steal_forget($prefix);
    }
});
