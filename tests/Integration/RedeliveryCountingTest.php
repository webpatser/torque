<?php

declare(strict_types=1);

use Fledge\Async\Future;
use Fledge\Async\TimeoutCancellation;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Tests\Fixtures\RedeliveryProbeJob;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/*
|--------------------------------------------------------------------------
| Redeliveries count against a job's limit under strict_attempts
|--------------------------------------------------------------------------
|
| Real workers (tests/Fixtures/steal-worker.php) that really die: the probe job
| sends its own worker SIGKILL. A worker is started per delivery, as the master
| would start a replacement, and steals the entry after retry_after (1s here).
|
| A string at the hash's key makes any hash command raise WRONGTYPE, so that is how
| these tests see that a path sends none at all: nothing else would tell a counter
| that was incremented and then removed from one that was never touched.
|
| Requires a running Redis instance.
|
*/

const TORQUE_REDELIVERY_GROUP = 'redelivery-group';
const TORQUE_REDELIVERY_QUEUE = 'redelivered';
const TORQUE_REDELIVERY_OTHER = 'redelivered-other';

/**
 * @return array{prefix: string, log: string, output: string, cluster: bool, queue: StreamQueue, stream: string, hash: string}
 */
function torque_redelivery_context(bool $cluster = false): array
{
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-redelivery-{$token}:";

    $queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: TORQUE_REDELIVERY_QUEUE,
        retryAfter: 1,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: TORQUE_REDELIVERY_GROUP,
        cluster: $cluster,
    );
    $queue->setContainer(app());
    $queue->setConnectionName('torque');
    $queue->ensureConsumerGroup($queue->getStreamKey(), TORQUE_REDELIVERY_GROUP);

    return [
        'prefix' => $prefix,
        'log' => sys_get_temp_dir()."/torque-redelivery-{$token}.jsonl",
        'output' => sys_get_temp_dir()."/torque-redelivery-{$token}.out",
        'cluster' => $cluster,
        'queue' => $queue,
        'stream' => $queue->getStreamKey(),
        'hash' => $queue->getStreamKey().':redeliveries',
    ];
}

/**
 * Start one worker and wait for it to exit, by itself or by SIGKILL.
 *
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $settings  Worker config overrides.
 */
function torque_redelivery_worker(array $ctx, array $settings = []): void
{
    $config = array_replace_recursive([
        'redis' => ['uri' => torqueRedisUri(), 'prefix' => $ctx['prefix'], 'cluster' => $ctx['cluster']],
        'consumer_group' => TORQUE_REDELIVERY_GROUP,
        'serializer' => 'json',
        'queues' => [TORQUE_REDELIVERY_QUEUE],
        'streams' => [TORQUE_REDELIVERY_QUEUE => ['retry_after' => 1, 'max_retries' => 3]],
        'slots_per_worker' => 1,
        'poll_interval_ms' => 100,
        'max_jobs_per_worker' => 1,
        'max_worker_lifetime' => 5,
        'max_worker_lifetime_jitter' => 0,
        'drain_grace_seconds' => 1,
        'strict_attempts' => true,
    ], $settings);

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Fixtures/steal-worker.php', $ctx['log'], json_encode($config, JSON_THROW_ON_ERROR)],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $ctx['output'], 'a'], 2 => ['file', $ctx['output'], 'a']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start the worker.');
    }

    $deadline = microtime(true) + 20;

    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        usleep(20_000);
    }

    $timedOut = proc_get_status($process)['running'];

    if ($timedOut) {
        proc_terminate($process, SIGKILL);
    }

    proc_close($process);

    if ($timedOut) {
        throw new RuntimeException("The worker timed out:\n".@file_get_contents($ctx['output']));
    }
}

/** @param array<string, mixed> $ctx */
function torque_redelivery_runs(array $ctx): int
{
    $lines = is_file($ctx['log']) ? file($ctx['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return count(array_filter($lines, static fn (string $line): bool => str_contains($line, '"type":"run"')));
}

/**
 * @param  array<string, mixed>  $ctx
 * @return list<int> attempts() as each run saw it
 */
function torque_redelivery_attempts(array $ctx): array
{
    $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($ctx['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

    return array_values(array_column(array_filter($rows, static fn (array $row): bool => $row['type'] === 'run'), 'attempts'));
}

/** @return array{queues: list<string>, streams: array<string, array<string, int>>} */
function torque_redelivery_two_streams(int $retryAfter = 1): array
{
    $stream = ['retry_after' => $retryAfter, 'max_retries' => 3];

    return [
        'queues' => [TORQUE_REDELIVERY_QUEUE, TORQUE_REDELIVERY_OTHER],
        'streams' => [TORQUE_REDELIVERY_QUEUE => $stream, TORQUE_REDELIVERY_OTHER => $stream],
    ];
}

/**
 * @param  array<string, mixed>  $ctx
 * @return list<array<string, string>>
 */
function torque_redelivery_dead_letters(array $ctx): array
{
    return array_map(static function (array $entry): array {
        $fields = ['id' => (string) $entry[0]];

        foreach (array_chunk($entry[1], 2) as [$field, $value]) {
            $fields[(string) $field] = (string) $value;
        }

        return $fields;
    }, torqueRedis()->execute('XRANGE', $ctx['prefix'].'dead-letter', '-', '+') ?? []);
}

/**
 * @param  array<string, mixed>  $ctx
 * @return list<string>
 */
function torque_redelivery_keys(array $ctx): array
{
    $found = [];
    $cursor = '0';

    do {
        [$cursor, $keys] = torqueRedis()->execute('SCAN', $cursor, 'MATCH', $ctx['prefix'].'*', 'COUNT', '200');
        $cursor = (string) $cursor;

        foreach ($keys ?? [] as $key) {
            $found[] = (string) $key;
        }
    } while ($cursor !== '0');

    return $found;
}

afterEach(function () {
    foreach ($this->contexts ?? [] as $ctx) {
        @unlink($ctx['log']);
        @unlink($ctx['output']);

        foreach (torque_redelivery_keys($ctx) as $key) {
            torqueRedis()->execute('DEL', $key);
        }
    }
});

function torque_redelivery_track(object $test, array $ctx): array
{
    $test->contexts = [...($test->contexts ?? []), $ctx];

    return $ctx;
}

describe('a job that kills its worker', function () {
    it('stops being run and lands in the dead letter at its limit', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        $ctx['queue']->push(new RedeliveryProbeJob('kill', tries: 2), '', TORQUE_REDELIVERY_QUEUE);

        for ($worker = 0; $worker < 5 && torque_redelivery_dead_letters($ctx) === []; $worker++) {
            torque_redelivery_worker($ctx);
        }

        $deadLetters = torque_redelivery_dead_letters($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and($deadLetters)->toHaveCount(1)
            ->and($deadLetters[0]['exception_class'])->toBe(MaxAttemptsExceededException::class)
            ->and($deadLetters[0]['reason'])->toBe('redelivery_limit')
            ->and($deadLetters[0]['exception_message'])->toContain('2 redeliveries')
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(0)
            ->and((int) ($redis->execute('XPENDING', $ctx['stream'], TORQUE_REDELIVERY_GROUP)[0] ?? 0))->toBe(0)
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0);
    });

    it('keeps looping with strict_attempts off and writes nothing to the hash', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        $ctx['queue']->push(new RedeliveryProbeJob('kill', tries: 2), '', TORQUE_REDELIVERY_QUEUE);

        // One more run than the limit that stopped it above.
        for ($worker = 0; $worker < 3; $worker++) {
            torque_redelivery_worker($ctx, ['strict_attempts' => false]);
        }

        expect(torque_redelivery_runs($ctx))->toBe(3)
            ->and(torque_redelivery_dead_letters($ctx))->toBe([])
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(1)
            ->and(array_filter(torque_redelivery_keys($ctx), static fn (string $key): bool => str_ends_with($key, ':redeliveries')))->toBe([]);
    });

    it('sends no hash command with strict_attempts off, even for a redelivered job', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $redis->execute('SET', $ctx['hash'], 'not a hash');

        $ctx['queue']->push(new RedeliveryProbeJob('kill', misbehavingRuns: 1), '', TORQUE_REDELIVERY_QUEUE);

        torque_redelivery_worker($ctx, ['strict_attempts' => false]);
        torque_redelivery_worker($ctx, ['strict_attempts' => false]);

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(0)
            ->and((string) @file_get_contents($ctx['output']))->not->toContain('WRONGTYPE');
    });

    it('runs on without a limit at $tries = 0', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        $ctx['queue']->push(new RedeliveryProbeJob('kill', tries: 0), '', TORQUE_REDELIVERY_QUEUE);

        for ($worker = 0; $worker < 4; $worker++) {
            torque_redelivery_worker($ctx);
        }

        $ids = $redis->execute('XRANGE', $ctx['stream'], '-', '+');

        expect(torque_redelivery_runs($ctx))->toBe(4)
            ->and(torque_redelivery_dead_letters($ctx))->toBe([])
            ->and((int) $redis->execute('HGET', $ctx['hash'], (string) $ids[0][0]))->toBe(3)
            ->and((int) $redis->execute('TTL', $ctx['hash']))->toBeGreaterThan(0);
    });

    it('starts clean when its dead letter is retried', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        $ctx['queue']->push(new RedeliveryProbeJob('kill', misbehavingRuns: 1, tries: 1), '', TORQUE_REDELIVERY_QUEUE);

        torque_redelivery_worker($ctx);
        torque_redelivery_worker($ctx);

        $deadLetters = torque_redelivery_dead_letters($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(1)->and($deadLetters)->toHaveCount(1);

        (new DeadLetterHandler(torqueRedisUri(), prefix: $ctx['prefix']))->retry($deadLetters[0]['id']);

        torque_redelivery_worker($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and(torque_redelivery_dead_letters($ctx))->toBe([])
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(0)
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0);
    });

    it('leaves no field after a redelivered job is acknowledged', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        $ctx['queue']->push(new RedeliveryProbeJob('kill', misbehavingRuns: 1, tries: 3), '', TORQUE_REDELIVERY_QUEUE);

        torque_redelivery_worker($ctx);
        torque_redelivery_worker($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(0)
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0);
    });

    it('starts at 0 once it is released', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        $ctx['queue']->push(new RedeliveryProbeJob('release', misbehavingRuns: 1, tries: 2), '', TORQUE_REDELIVERY_QUEUE);
        $original = (string) $redis->execute('XRANGE', $ctx['stream'], '-', '+')[0][0];

        // A field as a redelivery leaves it, on the entry the release replaces.
        $redis->execute('HSET', $ctx['hash'], $original, '1');

        torque_redelivery_worker($ctx);

        $entries = $redis->execute('XRANGE', $ctx['stream'], '-', '+');

        expect($entries)->toHaveCount(1)
            ->and((string) $entries[0][0])->not->toBe($original)
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0);

        // Attempts 2 of 2 and no redeliveries, so the new entry still runs.
        torque_redelivery_worker($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and(torque_redelivery_dead_letters($ctx))->toBe([]);
    });
});

describe('which deliveries count', function () {
    it('sends no hash command for a clean job', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $redis->execute('SET', $ctx['hash'], 'not a hash');

        $ctx['queue']->push(new RedeliveryProbeJob, '', TORQUE_REDELIVERY_QUEUE);

        torque_redelivery_worker($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(1)
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(0)
            ->and((string) $redis->execute('GET', $ctx['hash']))->toBe('not a hash')
            ->and((string) @file_get_contents($ctx['output']))->not->toContain('WRONGTYPE');
    });

    it('does not count a re-read of a message this worker is running', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $redis->execute('SET', $ctx['hash'], 'not a hash');

        // 2.5s against a retry_after of 1s, with a free slot scanning all along.
        $ctx['queue']->push(new RedeliveryProbeJob('slow', tries: 1), '', TORQUE_REDELIVERY_QUEUE);

        torque_redelivery_worker($ctx, ['slots_per_worker' => 3]);

        expect(torque_redelivery_runs($ctx))->toBe(1)
            ->and(torque_redelivery_dead_letters($ctx))->toBe([])
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(0)
            ->and((string) @file_get_contents($ctx['output']))->not->toContain('WRONGTYPE');
    });

    it('counts a job a draining worker abandoned', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());

        $ctx['queue']->push(new RedeliveryProbeJob('hang', tries: 1), '', TORQUE_REDELIVERY_QUEUE);

        // The lifetime runs out with the job hanging, and the grace after it.
        torque_redelivery_worker($ctx, ['max_worker_lifetime' => 2]);
        torque_redelivery_worker($ctx);

        $deadLetters = torque_redelivery_dead_letters($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(1)
            ->and($deadLetters)->toHaveCount(1)
            ->and($deadLetters[0]['reason'])->toBe('redelivery_limit');
    });

    it('counts the delivery after a failed release', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        // The delayed write of the release fails, so the entry stays in the PEL.
        $redis->execute('SET', $ctx['stream'].':delayed', 'not a sorted set');

        $ctx['queue']->push(new RedeliveryProbeJob('throw', tries: 2), '', TORQUE_REDELIVERY_QUEUE);

        torque_redelivery_worker($ctx, [
            'max_jobs_per_worker' => 3,
            'max_worker_lifetime' => 8,
            'streams' => [TORQUE_REDELIVERY_QUEUE => ['backoff' => 1]],
        ]);

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and(torque_redelivery_dead_letters($ctx))->toHaveCount(1)
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0);
    });
});

describe('a job that never ran', function () {
    it('is returned at drain when it was prefetched, and is not charged', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $other = $ctx['queue']->getStreamKey(TORQUE_REDELIVERY_OTHER);

        $ctx['queue']->push(new RedeliveryProbeJob(tries: 1), '', TORQUE_REDELIVERY_QUEUE);
        $ctx['queue']->push(new RedeliveryProbeJob(tries: 1), '', TORQUE_REDELIVERY_OTHER);
        $original = (string) $redis->execute('XRANGE', $other, '-', '+')[0][0];

        // COUNT 1 per stream: one read delivers both, and max_jobs ends the worker after one.
        torque_redelivery_worker($ctx, torque_redelivery_two_streams());

        $entries = $redis->execute('XRANGE', $other, '-', '+');

        expect(torque_redelivery_runs($ctx))->toBe(1)
            ->and($entries)->toHaveCount(1)
            ->and((string) $entries[0][0])->not->toBe($original)
            ->and((int) ($redis->execute('XPENDING', $other, TORQUE_REDELIVERY_GROUP)[0] ?? 0))->toBe(0);

        torque_redelivery_worker($ctx, torque_redelivery_two_streams());

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and(torque_redelivery_dead_letters($ctx))->toBe([])
            ->and(array_unique(torque_redelivery_attempts($ctx)))->toHaveCount(1)
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0)
            ->and((int) $redis->execute('EXISTS', $other.':redeliveries'))->toBe(0);
    });

    it('is returned at drain with strict_attempts off too, rather than waiting out retry_after', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $settings = ['strict_attempts' => false] + torque_redelivery_two_streams(retryAfter: 60);

        $ctx['queue']->push(new RedeliveryProbeJob, '', TORQUE_REDELIVERY_QUEUE);
        $ctx['queue']->push(new RedeliveryProbeJob, '', TORQUE_REDELIVERY_OTHER);

        torque_redelivery_worker($ctx, $settings);
        torque_redelivery_worker($ctx, ['max_worker_lifetime' => 2] + $settings);

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and((int) $redis->execute('XLEN', $ctx['queue']->getStreamKey(TORQUE_REDELIVERY_OTHER)))->toBe(0);
    });

    it('is returned at a forced exit, while the job abandoned there is still charged', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $other = $ctx['queue']->getStreamKey(TORQUE_REDELIVERY_OTHER);

        $ctx['queue']->push(new RedeliveryProbeJob('hang', tries: 1), '', TORQUE_REDELIVERY_QUEUE);
        $ctx['queue']->push(new RedeliveryProbeJob(tries: 1), '', TORQUE_REDELIVERY_OTHER);

        $started = microtime(true);
        torque_redelivery_worker($ctx, ['max_worker_lifetime' => 2] + torque_redelivery_two_streams());

        // Lifetime 2 and grace 1, the rest is boot and the tick.
        expect(microtime(true) - $started)->toBeLessThan(7.0)
            ->and((int) ($redis->execute('XPENDING', $other, TORQUE_REDELIVERY_GROUP)[0] ?? 0))->toBe(0);

        torque_redelivery_worker($ctx, ['max_jobs_per_worker' => 2] + torque_redelivery_two_streams());

        $deadLetters = torque_redelivery_dead_letters($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(2)
            ->and($deadLetters)->toHaveCount(1)
            ->and($deadLetters[0]['reason'])->toBe('redelivery_limit');
    });

    it('is not charged after a failed start', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        // An igbinary header on a JSON worker is quarantined, and that write fails.
        $redis->execute('SET', $ctx['prefix'].'dead-letter', 'not a stream');
        $redis->execute('XADD', $ctx['stream'], '*', 'payload', "\x00\x00\x00\x02\x14\x00");

        torque_redelivery_worker($ctx, ['max_worker_lifetime' => 3]);

        expect((string) file_get_contents($ctx['output']))->toContain('failed to start a job')
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0)
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(1);

        $redis->execute('DEL', $ctx['prefix'].'dead-letter');

        torque_redelivery_worker($ctx);

        expect(torque_redelivery_dead_letters($ctx))->toHaveCount(1)
            ->and((int) $redis->execute('XLEN', $ctx['stream']))->toBe(0)
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0);
    });

    it('stays owed when counting a real redelivery failed', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        $ctx['queue']->push(new RedeliveryProbeJob('kill', tries: 1), '', TORQUE_REDELIVERY_QUEUE);

        torque_redelivery_worker($ctx);

        $redis->execute('SET', $ctx['hash'], 'not a hash');
        torque_redelivery_worker($ctx, ['max_worker_lifetime' => 2]);

        expect((string) file_get_contents($ctx['output']))->toContain('WRONGTYPE')
            ->and(torque_redelivery_dead_letters($ctx))->toBe([]);

        $redis->execute('DEL', $ctx['hash']);
        torque_redelivery_worker($ctx);

        $deadLetters = torque_redelivery_dead_letters($ctx);

        expect(torque_redelivery_runs($ctx))->toBe(1)
            ->and($deadLetters)->toHaveCount(1)
            ->and($deadLetters[0]['reason'])->toBe('redelivery_limit');
    });

    it('is forgotten oldest first once the set is full', function () {
        $set = [];

        foreach (['a', 'b', 'c'] as $key) {
            $set = WorkerProcess::rememberUnstarted($set, $key, $key === 'b', 2);
        }

        expect($set)->toBe(['b' => true, 'c' => false]);
    });
});

/*
 * The race itself needs one slot's `>` reply to reach PHP after another slot's
 * 0-0 reply, which no real worker can be made to do on demand. These state it
 * directly: the delivery is real, only the slot that has not seen the reply yet
 * is played through the worker's own bookkeeping.
 */
describe('a pending read beside a read of new messages', function () {
    /**
     * @param  array<string, mixed>  $ctx
     * @return array{worker: WorkerProcess, reflection: ReflectionClass<WorkerProcess>, read: Future<mixed>, key: string}
     */
    function torque_redelivery_pending_read(array $ctx, float $readTimeout = 30.0): array
    {
        $ctx['queue']->push(new RedeliveryProbeJob(tries: 1), '', TORQUE_REDELIVERY_QUEUE);

        $worker = new WorkerProcess(['redis' => ['uri' => torqueRedisUri(), 'read_timeout_seconds' => $readTimeout]]);
        $reflection = new ReflectionClass($worker);
        $consumer = (string) $reflection->getProperty('consumerId')->getValue($worker);

        // Connected first, so the wait below only has to cover one round trip.
        $reader = $worker->createReaderClient(torqueRedisUri());
        $reader->execute('PING');

        $delivered = torqueRedis()->execute('XREADGROUP', 'GROUP', TORQUE_REDELIVERY_GROUP, $consumer, 'COUNT', '1', 'STREAMS', $ctx['stream'], '>');
        $reflection->getProperty('newReads')->setValue($worker, [1 => true]);

        $read = async(fn (): mixed => $reflection->getMethod('readPendingMessage')->invoke(
            $worker,
            $reader,
            [TORQUE_REDELIVERY_QUEUE],
            $ctx['prefix'],
            TORQUE_REDELIVERY_GROUP,
            fn (string $queue): string => $ctx['stream'],
        ));

        delay(0.25);

        return [
            'worker' => $worker,
            'reflection' => $reflection,
            'read' => $read,
            'key' => $ctx['stream'].'|'.$delivered[0][1][0][0],
        ];
    }

    it('leaves an entry to the slot whose reply is still unprocessed', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $run = torque_redelivery_pending_read($ctx);

        // That slot's reply comes in and it claims the entry.
        $run['reflection']->getProperty('inFlight')->setValue($run['worker'], [$run['key'] => true]);
        $run['reflection']->getProperty('newReads')->setValue($run['worker'], []);

        expect($run['read']->await(new TimeoutCancellation(2)))->toBeNull();
    });

    it('does not wait for reads that started after its reply', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $run = torque_redelivery_pending_read($ctx);

        // Sustained reads: the next one is on the wire before the first has ended.
        $run['reflection']->getProperty('newReads')->setValue($run['worker'], [2 => true]);

        $found = $run['read']->await(new TimeoutCancellation(2));

        expect($found['stream'].'|'.$found['id'])->toBe($run['key']);
    });

    it('does not run an entry handed back while its reply was on the way', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $run = torque_redelivery_pending_read($ctx);

        // The reply is in and lists the entry. The drain hands it back as a new one.
        $run['reflection']->getProperty('neverStarted')->setValue($run['worker'], [$run['key'] => false]);
        $run['reflection']->getMethod('returnUnstartedEntries')->invoke($run['worker'], $ctx['queue']);
        $run['reflection']->getProperty('newReads')->setValue($run['worker'], []);

        expect($run['read']->await(new TimeoutCancellation(2)))->toBeNull()
            ->and($redis->execute('XRANGE', $ctx['stream'], '-', '+'))->toHaveCount(1)
            ->and((int) ($redis->execute('XPENDING', $ctx['stream'], TORQUE_REDELIVERY_GROUP)[0] ?? 0))->toBe(0);
    });

    it('stops waiting for a read that never ends, and runs nothing', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $run = torque_redelivery_pending_read($ctx, readTimeout: 0.2);

        expect($run['read']->await(new TimeoutCancellation(2)))->toBeNull()
            ->and($run['reflection']->getProperty('pendingReads')->getValue($run['worker']))->toBe(0);
    });

    it('bounds that wait without a read timeout too', function (float $readTimeout, float $wait) {
        expect(WorkerProcess::outstandingReadWait($readTimeout))->toBe($wait);
    })->with([
        'a read timeout' => [5.0, 5.0],
        'none' => [0.0, 30.0],
        'negative' => [-1.0, 30.0],
    ]);
});

/*
 * What the reply of XAUTOCLAIM cannot say: it lists no previous owner. So the steal
 * looks at this worker's own PEL first, and only while it remembers unstarted
 * entries of that stream.
 */
describe('a stolen entry this worker remembers as never started', function () {
    /**
     * @param  array<string, mixed>  $ctx
     * @param  (Closure(string): void)|null  $beforeSteal  Gets the entry id.
     * @return array{message: array<string, string>|null, neverStarted: array<string, bool>, key: string}
     */
    function torque_redelivery_steal(array $ctx, ?Closure $beforeSteal = null): array
    {
        $ctx['queue']->push(new RedeliveryProbeJob(tries: 1), '', TORQUE_REDELIVERY_QUEUE);

        $worker = new WorkerProcess(['redis' => ['uri' => torqueRedisUri()]]);
        $reflection = new ReflectionClass($worker);
        $consumer = (string) $reflection->getProperty('consumerId')->getValue($worker);
        $reflection->getProperty('startTime')->setValue($worker, time());

        $delivered = torqueRedis()->execute('XREADGROUP', 'GROUP', TORQUE_REDELIVERY_GROUP, $consumer, 'COUNT', '1', 'STREAMS', $ctx['stream'], '>');
        $id = (string) $delivered[0][1][0][0];
        $key = $ctx['stream'].'|'.$id;
        $reflection->getProperty('neverStarted')->setValue($worker, [$key => false]);

        if ($beforeSteal !== null) {
            $beforeSteal($id);
        }

        $message = $reflection->getMethod('stealMessage')->invoke(
            $worker,
            $worker->createReaderClient(torqueRedisUri()),
            [TORQUE_REDELIVERY_QUEUE],
            $ctx['prefix'],
            TORQUE_REDELIVERY_GROUP,
            [TORQUE_REDELIVERY_QUEUE => ['retry_after' => 0]],
            fn (string $queue): string => $ctx['stream'],
        );

        return [
            'message' => $message,
            'neverStarted' => $reflection->getProperty('neverStarted')->getValue($worker),
            'key' => $key,
        ];
    }

    it('is charged when it comes back from another consumer', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());

        // That consumer stole it, and is gone now.
        $run = torque_redelivery_steal($ctx, static function (string $id) use ($ctx): void {
            torqueRedis()->execute('XCLAIM', $ctx['stream'], TORQUE_REDELIVERY_GROUP, 'crashed-consumer', '0', $id);
        });

        expect($run['message']['stream'].'|'.$run['message']['id'])->toBe($run['key'])
            ->and($run['neverStarted'])->toBe([]);
    });

    it('stays uncharged when it was in this worker\'s own PEL all along', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());

        $run = torque_redelivery_steal($ctx);

        expect($run['message']['stream'].'|'.$run['message']['id'])->toBe($run['key'])
            ->and($run['neverStarted'])->toBe([$run['key'] => false]);
    });
});

describe('handing back entries that never started', function () {
    /**
     * @param  array<string, mixed>  $ctx
     */
    function torque_redelivery_deliver(array $ctx, string $stream, string $queueName, string $consumer): string
    {
        $ctx['queue']->push(new RedeliveryProbeJob(tries: 1), '', $queueName);

        $delivered = torqueRedis()->execute('XREADGROUP', 'GROUP', TORQUE_REDELIVERY_GROUP, $consumer, 'COUNT', '1', 'STREAMS', $stream, '>');

        return (string) $delivered[0][1][0][0];
    }

    it('moves the redelivery count to the new entry', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $id = torque_redelivery_deliver($ctx, $ctx['stream'], TORQUE_REDELIVERY_QUEUE, 'stopping');
        $redis->execute('HSET', $ctx['hash'], $id, '2');

        $returned = $ctx['queue']->returnUnstarted($ctx['stream'], 'stopping', [$id]);
        $entries = $redis->execute('XRANGE', $ctx['stream'], '-', '+');
        $new = (string) $entries[0][0];

        expect($returned)->toBe(1)
            ->and($entries)->toHaveCount(1)
            ->and($new)->not->toBe($id)
            ->and((int) $redis->execute('HGET', $ctx['hash'], $new))->toBe(2)
            ->and((int) $redis->execute('HEXISTS', $ctx['hash'], $id))->toBe(0)
            ->and((int) ($redis->execute('XPENDING', $ctx['stream'], TORQUE_REDELIVERY_GROUP)[0] ?? 0))->toBe(0);
    });

    it('leaves alone an entry another consumer stole', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $id = torque_redelivery_deliver($ctx, $ctx['stream'], TORQUE_REDELIVERY_QUEUE, 'stopping');
        $redis->execute('HSET', $ctx['hash'], $id, '2');
        $redis->execute('XCLAIM', $ctx['stream'], TORQUE_REDELIVERY_GROUP, 'thief', '0', $id);

        $returned = $ctx['queue']->returnUnstarted($ctx['stream'], 'stopping', [$id]);
        $entries = $redis->execute('XRANGE', $ctx['stream'], '-', '+');
        $pending = $redis->execute('XPENDING', $ctx['stream'], TORQUE_REDELIVERY_GROUP, '-', '+', '10');

        expect($returned)->toBe(0)
            ->and($entries)->toHaveCount(1)
            ->and((string) $entries[0][0])->toBe($id)
            ->and($pending)->toHaveCount(1)
            ->and((string) $pending[0][1])->toBe('thief')
            ->and((int) $redis->execute('HGET', $ctx['hash'], $id))->toBe(2);
    });

    it('carries on with the next stream when one fails', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $other = $ctx['queue']->getStreamKey(TORQUE_REDELIVERY_OTHER);
        $ctx['queue']->ensureConsumerGroup($other, TORQUE_REDELIVERY_GROUP);

        $worker = new WorkerProcess(['redis' => ['uri' => torqueRedisUri()]]);
        $reflection = new ReflectionClass($worker);
        $consumer = (string) $reflection->getProperty('consumerId')->getValue($worker);

        $broken = torque_redelivery_deliver($ctx, $ctx['stream'], TORQUE_REDELIVERY_QUEUE, $consumer);
        $healthy = torque_redelivery_deliver($ctx, $other, TORQUE_REDELIVERY_OTHER, $consumer);

        $redis->execute('XGROUP', 'DESTROY', $ctx['stream'], TORQUE_REDELIVERY_GROUP);

        $reflection->getProperty('prefetched')->setValue($worker, [
            ['stream' => $ctx['stream'], 'id' => $broken, 'payload' => '{}'],
            ['stream' => $other, 'id' => $healthy, 'payload' => '{}'],
        ]);

        $reflection->getMethod('returnUnstartedEntries')->invoke($worker, $ctx['queue']);

        $entries = $redis->execute('XRANGE', $other, '-', '+');

        expect($entries)->toHaveCount(1)
            ->and((string) $entries[0][0])->not->toBe($healthy)
            ->and((int) ($redis->execute('XPENDING', $other, TORQUE_REDELIVERY_GROUP)[0] ?? 0))->toBe(0);
    });
});

describe('the hash', function () {
    it('keeps the cluster hash tag, and queue:clear removes it', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context(cluster: true));
        $redis = torqueRedis();

        expect($ctx['hash'])->toBe($ctx['prefix'].'{'.TORQUE_REDELIVERY_QUEUE.'}:redeliveries');

        $ctx['queue']->push(new RedeliveryProbeJob('kill', tries: 0), '', TORQUE_REDELIVERY_QUEUE);

        torque_redelivery_worker($ctx);
        torque_redelivery_worker($ctx);

        expect(array_map(static fn (mixed $field): int => (int) $field, $redis->execute('HVALS', $ctx['hash'])))->toBe([1])
            ->and((int) $redis->execute('EXISTS', $ctx['prefix'].TORQUE_REDELIVERY_QUEUE.':redeliveries'))->toBe(0);

        $ctx['queue']->clear();

        expect((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0);
    });

    it('is removed by torque:flush', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();
        $redis->execute('HSET', $ctx['hash'], '1-0', '1');

        config(['torque.redis.uri' => torqueRedisUri(), 'torque.redis.prefix' => $ctx['prefix'], 'torque.redis.cluster' => false]);

        expect(Artisan::call('torque:flush', ['--queues' => TORQUE_REDELIVERY_QUEUE]))->toBe(0)
            ->and((int) $redis->execute('EXISTS', $ctx['hash']))->toBe(0);
    });

    it('loses a stale field to the housekeeper, and keeps the field of a waiting entry', function () {
        $ctx = torque_redelivery_track($this, torque_redelivery_context());
        $redis = torqueRedis();

        $live = (string) $redis->execute('XADD', $ctx['stream'], '*', 'payload', '{}');

        // What a crash between the ack and the HDEL leaves behind.
        $redis->execute('HSET', $ctx['hash'], $live, '1', '1-0', '1');

        $housekeeper = new StreamHousekeeper(
            redisUri: torqueRedisUri(),
            prefix: $ctx['prefix'],
            consumerGroup: TORQUE_REDELIVERY_GROUP,
            queues: [TORQUE_REDELIVERY_QUEUE],
            deadLetters: new DeadLetterHandler(torqueRedisUri(), prefix: $ctx['prefix']),
        );

        (void) $housekeeper->pruneConsumers(3600, dryRun: true);

        expect((int) $redis->execute('HLEN', $ctx['hash']))->toBe(2);

        (void) $housekeeper->pruneConsumers(3600);

        expect($redis->execute('HKEYS', $ctx['hash']))->toBe([$live]);
    });
});
