<?php

declare(strict_types=1);

use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\RedisClient;
use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\TestCase;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Redis\createRedisConnector;

// The application is needed for torque:flush below.
uses(TestCase::class);

afterEach(function () {
    if (isset($this->prefetchRedis)) {
        foreach ($this->prefetchRedis->execute('KEYS', 'torque-prefetch-*') as $key) {
            $this->prefetchRedis->execute('DEL', (string) $key);
        }
    }
});

/**
 * A multi-stream XREADGROUP with COUNT 1 delivers up to one entry per stream.
 * Every delivered entry must reach a Fiber; the ones beyond the first are
 * buffered and served before the next read instead of being stranded in the
 * PEL until retry_after (scrpr 2026-08-28: slowSync Persist parts waited
 * 30 minutes each while the default stream was busy).
 */
function makeWorker(): WorkerProcess
{
    return new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379'], 'queues' => ['default', 'slowSync']]);
}

function parseResponse(WorkerProcess $worker, mixed $result): ?array
{
    return (new ReflectionMethod(WorkerProcess::class, 'parseXreadgroupResponse'))->invoke($worker, $result);
}

function prefetched(WorkerProcess $worker): array
{
    return (new ReflectionProperty(WorkerProcess::class, 'prefetched'))->getValue($worker);
}

it('returns the first delivered message and buffers the rest', function () {
    $worker = makeWorker();

    $first = parseResponse($worker, [
        ['torque:default', [['1-0', ['payload', 'a']]]],
        ['torque:slowSync', [['2-0', ['payload', 'b']], ['3-0', ['payload', 'c']]]],
    ]);

    expect($first)->toBe(['stream' => 'torque:default', 'id' => '1-0', 'payload' => 'a'])
        ->and(prefetched($worker))->toBe([
            ['stream' => 'torque:slowSync', 'id' => '2-0', 'payload' => 'b'],
            ['stream' => 'torque:slowSync', 'id' => '3-0', 'payload' => 'c'],
        ]);
});

it('skips null streams and entries without a payload field', function () {
    $worker = makeWorker();

    $first = parseResponse($worker, [
        null,
        ['torque:default', [['1-0', ['other', 'x']]]],
        ['torque:slowSync', [['2-0', ['payload', 'b']]]],
    ]);

    expect($first)->toBe(['stream' => 'torque:slowSync', 'id' => '2-0', 'payload' => 'b'])
        ->and(prefetched($worker))->toBe([]);
});

it('returns null for an empty reply and leaves the buffer untouched', function () {
    $worker = makeWorker();

    expect(parseResponse($worker, null))->toBeNull()
        ->and(parseResponse($worker, []))->toBeNull()
        ->and(prefetched($worker))->toBe([]);
});

it('serves buffered messages before reading from Redis again', function () {
    $worker = makeWorker();
    parseResponse($worker, [
        ['torque:default', [['1-0', ['payload', 'a']]]],
        ['torque:slowSync', [['2-0', ['payload', 'b']]]],
    ]);

    // With a non-empty buffer readNextMessage returns before touching Redis,
    // so a client on a port nothing listens on proves the read is skipped.
    $redis = createRedisClient('redis://127.0.0.1:1');

    $next = (new ReflectionMethod(WorkerProcess::class, 'readNextMessage'))
        ->invoke($worker, $redis, ['default', 'slowSync'], 'torque:', 'torque', fn (string $q) => 'torque:'.$q);

    expect($next)->toBe(['stream' => 'torque:slowSync', 'id' => '2-0', 'payload' => 'b'])
        ->and(prefetched($worker))->toBe([]);
});

it('serves a buffered message only to a read for its own queue', function () {
    $worker = makeWorker();

    // Left over from two earlier reads: one XREADGROUP reply names a stream once.
    (new ReflectionProperty(WorkerProcess::class, 'prefetched'))->setValue($worker, [
        ['stream' => 'torque:slowSync', 'id' => '2-0', 'payload' => 'b'],
        ['stream' => 'torque:default', 'id' => '3-0', 'payload' => 'c'],
    ]);

    // Nothing listens on port 1, so a read that reached Redis would throw.
    $redis = createRedisClient('redis://127.0.0.1:1');

    // slowSync is at max_concurrency, paused or behind an open breaker, so
    // this slot may read default only: the slowSync entry stays buffered.
    $next = (new ReflectionMethod(WorkerProcess::class, 'readNextMessage'))
        ->invoke($worker, $redis, ['default'], 'torque:', 'torque', fn (string $q) => 'torque:'.$q);

    expect($next)->toBe(['stream' => 'torque:default', 'id' => '3-0', 'payload' => 'c'])
        ->and(prefetched($worker))->toBe([
            ['stream' => 'torque:slowSync', 'id' => '2-0', 'payload' => 'b'],
        ]);
});

it('reads from Redis when no buffered message belongs to the queues asked for', function () {
    $redis = torqueRedis();
    $prefix = 'torque-prefetch-'.bin2hex(random_bytes(4)).':';
    $default = $prefix.'default';

    $redis->execute('XGROUP', 'CREATE', $default, 'torque', '$', 'MKSTREAM');
    $id = $redis->execute('XADD', $default, '*', 'payload', 'from-redis');

    $worker = makeWorker();
    (new ReflectionProperty(WorkerProcess::class, 'prefetched'))->setValue($worker, [
        ['stream' => $prefix.'slowSync', 'id' => '2-0', 'payload' => 'b'],
    ]);

    try {
        $next = (new ReflectionMethod(WorkerProcess::class, 'readNextMessage'))
            ->invoke($worker, $redis, ['default'], $prefix, 'torque', fn (string $q) => $prefix.$q);
    } finally {
        $redis->execute('DEL', $default);
    }

    // The default-only read went to Redis and left the slowSync entry buffered.
    expect($next)->toBe(['stream' => $default, 'id' => $id, 'payload' => 'from-redis'])
        ->and(prefetched($worker))->toBe([
            ['stream' => $prefix.'slowSync', 'id' => '2-0', 'payload' => 'b'],
        ]);
});

/*
 * A buffered entry must not wait in this consumer's PEL while its queue is
 * not eligible here: another worker steals it after retry_after while it is
 * still buffered (a double run), and a flush leaves it to run. Against the
 * suite's test Redis, under a prefix of its own.
 */

function prefetchRead(WorkerProcess $worker, RedisClient $redis, array $queues, string $prefix): ?array
{
    return (new ReflectionMethod(WorkerProcess::class, 'readNextMessage'))
        ->invoke($worker, $redis, $queues, $prefix, 'torque', fn (string $q) => $prefix.$q);
}

function prefetchHandBack(WorkerProcess $worker, string $prefix, array $paused, ?RedisClient $through = null): int
{
    if ($through === null) {
        $queue = new StreamQueue(redisUri: torqueRedisUri(), default: 'default', retryAfter: 60, blockFor: 0, prefix: $prefix, consumerGroup: 'torque');
    } else {
        // A real StreamQueue whose connection is the given client.
        $queue = (new ReflectionClass(StreamQueue::class))->newInstanceWithoutConstructor();
        (function () use ($through): void {
            $this->redis = $through;
            $this->consumerGroup = 'torque';
        })->call($queue);
    }

    return (new ReflectionMethod(WorkerProcess::class, 'handBackHeldPrefetched'))
        ->invoke($worker, $queue, $paused, false, $prefix, false);
}

/**
 * A client on the real test Redis whose commands pass through $around, which
 * gets the command, its parameters and a closure running it on the real link.
 */
function prefetchLink(Closure $around): RedisClient
{
    return new RedisClient(new class(new ReconnectingRedisLink(createRedisConnector(torqueRedisUri())), $around) implements RedisLink
    {
        public function __construct(private readonly RedisLink $link, private readonly Closure $around) {}

        public function execute(string $command, array $parameters): RedisResponse
        {
            return ($this->around)(strtoupper($command), $parameters, fn (): RedisResponse => $this->link->execute($command, $parameters));
        }
    });
}

function prefetchProperty(WorkerProcess $worker, string $name): mixed
{
    return (new ReflectionProperty(WorkerProcess::class, $name))->getValue($worker);
}

function prefetchOwnPending(WorkerProcess $worker, RedisClient $redis, string $streamKey): array
{
    $consumer = (new ReflectionProperty(WorkerProcess::class, 'consumerId'))->getValue($worker);

    return array_column($redis->execute('XPENDING', $streamKey, 'torque', '-', '+', '10', $consumer), 0);
}

function prefetchHeldFor(WorkerProcess $worker, string $key, float $seconds): void
{
    (new ReflectionProperty(WorkerProcess::class, 'prefetchedAt'))
        ->setValue($worker, [$key => hrtime(true) / 1e9 - $seconds]);
}

/**
 * One real multi-stream read: the default entry goes to the slot, the slowSync
 * entry is buffered and pending for this consumer.
 *
 * @return array{0: WorkerProcess, 1: RedisClient, 2: string, 3: string}
 */
function prefetchRig(): array
{
    $redis = torqueRedis();
    test()->prefetchRedis = $redis;
    $prefix = 'torque-prefetch-'.bin2hex(random_bytes(4)).':';

    foreach (['default', 'slowSync'] as $queue) {
        $redis->execute('XGROUP', 'CREATE', $prefix.$queue, 'torque', '$', 'MKSTREAM');
    }

    $redis->execute('XADD', $prefix.'default', '*', 'payload', 'a');
    $id = (string) $redis->execute('XADD', $prefix.'slowSync', '*', 'payload', 'b');

    $worker = makeWorker();
    $first = prefetchRead($worker, $redis, ['default', 'slowSync'], $prefix);

    expect($first['payload'])->toBe('a')
        ->and(prefetched($worker))->toBe([['stream' => $prefix.'slowSync', 'id' => $id, 'payload' => 'b']])
        ->and(prefetchOwnPending($worker, $redis, $prefix.'slowSync'))->toBe([$id]);

    return [$worker, $redis, $prefix, $id];
}

it('hands a buffered entry back when its queue is paused, and delivers it once after resume', function () {
    [$worker, $redis, $prefix, $id] = prefetchRig();

    expect(prefetchHandBack($worker, $prefix, ['slowSync']))->toBe(1)
        ->and(prefetched($worker))->toBe([])
        ->and(prefetchOwnPending($worker, $redis, $prefix.'slowSync'))->toBe([])
        ->and((int) $redis->execute('XLEN', $prefix.'slowSync'))->toBe(1);

    // Resumed: one read gets it as a new entry, the next finds nothing.
    $again = prefetchRead($worker, $redis, ['slowSync'], $prefix);

    expect($again['payload'])->toBe('b')
        ->and($again['id'])->not->toBe($id)
        ->and(prefetchRead($worker, $redis, ['slowSync'], $prefix))->toBeNull()
        ->and(prefetchOwnPending($worker, $redis, $prefix.'slowSync'))->toBe([$again['id']]);
});

it('keeps a buffered entry of an eligible queue that has not waited past the hold limit', function () {
    [$worker, $redis, $prefix, $id] = prefetchRig();

    // A max_concurrency cap of a moment costs no round trip and no re-read.
    expect(prefetchHandBack($worker, $prefix, []))->toBe(0)
        ->and(prefetched($worker))->toHaveCount(1)
        ->and(prefetchOwnPending($worker, $redis, $prefix.'slowSync'))->toBe([$id]);
});

it('hands back a buffered entry that waited past the hold limit', function () {
    [$worker, $redis, $prefix, $id] = prefetchRig();
    prefetchHeldFor($worker, $prefix.'slowSync|'.$id, 6.0);

    expect(prefetchHandBack($worker, $prefix, []))->toBe(1)
        ->and(prefetched($worker))->toBe([])
        ->and(prefetchOwnPending($worker, $redis, $prefix.'slowSync'))->toBe([]);
});

it('does not run a buffered entry another consumer has claimed', function () {
    [$worker, $redis, $prefix, $id] = prefetchRig();

    // Another worker's steal, after the entry sat here past retry_after.
    $redis->execute('XCLAIM', $prefix.'slowSync', 'torque', 'other-consumer', '0', $id, 'JUSTID');
    prefetchHeldFor($worker, $prefix.'slowSync|'.$id, 61.0);

    expect(prefetchRead($worker, $redis, ['slowSync'], $prefix))->toBeNull()
        ->and(prefetched($worker))->toBe([]);
});

it('leaves nothing to run after a torque:flush of a paused queue', function () {
    [$worker, $redis, $prefix] = prefetchRig();

    config([
        'torque.redis.prefix' => $prefix,
        'torque.consumer_group' => 'torque',
        'torque.streams' => ['default' => [], 'slowSync' => []],
    ]);

    // Paused, flushed, resumed.
    prefetchHandBack($worker, $prefix, ['slowSync']);

    expect(Artisan::call('torque:flush', ['--queues' => 'slowSync']))->toBe(0)
        ->and((int) $redis->execute('XLEN', $prefix.'slowSync'))->toBe(0)
        ->and(prefetchRead($worker, $redis, ['slowSync'], $prefix))->toBeNull()
        ->and(prefetched($worker))->toBe([]);
});

it('runs an entry exactly once when the hand-back ran in Redis but its reply was lost', function () {
    [$worker, $redis, $prefix, $id] = prefetchRig();

    // The script runs; the reply never reaches the worker.
    $lossy = prefetchLink(function (string $command, array $parameters, Closure $next): RedisResponse {
        $response = $next();

        if ($command === 'EVAL') {
            $response->unwrap();

            throw new RuntimeException('read timed out');
        }

        return $response;
    });

    expect(prefetchHandBack($worker, $prefix, ['slowSync'], $lossy))->toBe(0)
        ->and(prefetched($worker))->toHaveCount(1);

    // Resumed: the copy Redis holds under its new id runs, the old id does not.
    $first = prefetchRead($worker, $redis, ['slowSync'], $prefix);

    expect($first['payload'])->toBe('b')
        ->and($first['id'])->not->toBe($id)
        ->and(prefetchRead($worker, $redis, ['slowSync'], $prefix))->toBeNull()
        ->and(prefetched($worker))->toBe([]);
});

/**
 * A pending read of this consumer's PEL during which the slowSync entry is
 * handed back, as when the pause check runs while a slot reads.
 */
function prefetchHandBackDuringPendingRead(WorkerProcess $worker, string $prefix): void
{
    $armed = true;
    $reader = prefetchLink(function (string $command, array $parameters, Closure $next) use ($worker, $prefix, &$armed): RedisResponse {
        if ($armed && $command === 'XREADGROUP' && in_array('0-0', $parameters, true)) {
            $armed = false;
            prefetchHandBack($worker, $prefix, ['slowSync']);
        }

        return $next();
    });

    (new ReflectionMethod(WorkerProcess::class, 'readPendingMessage'))
        ->invoke($worker, $reader, ['slowSync'], $prefix, 'torque', fn (string $q) => $prefix.$q);
}

it('forgets handed-back ids once the pending read in flight completes', function () {
    [$worker, , $prefix] = prefetchRig();

    prefetchHandBackDuringPendingRead($worker, $prefix);

    expect(prefetched($worker))->toBe([])
        ->and(prefetchProperty($worker, 'handedBack'))->toBe([]);
});

it('runs an entry the hand-back skipped once a claim brings it back to this consumer', function () {
    [$worker, $redis, $prefix, $id] = prefetchRig();

    // Another consumer holds it, so the hand-back script leaves it alone.
    $redis->execute('XCLAIM', $prefix.'slowSync', 'torque', 'other-consumer', '0', $id, 'JUSTID');
    prefetchHandBackDuringPendingRead($worker, $prefix);

    // That consumer died: this worker's steal claims the entry. A running
    // worker has a start time; without one its lifetime limit counts as reached.
    (new ReflectionProperty(WorkerProcess::class, 'startTime'))->setValue($worker, time());
    $stolen = (new ReflectionMethod(WorkerProcess::class, 'stealMessage'))->invoke(
        $worker, $redis, ['slowSync'], $prefix, 'torque', ['slowSync' => ['retry_after' => 0]], fn (string $q) => $prefix.$q,
    );

    expect($stolen['id'] ?? null)->toBe($id);
});

it('hands a buffered entry back when its pending entry was lost but the stream entry is still there', function () {
    [$worker, $redis, $prefix, $id] = prefetchRig();

    // A failover that lost the PEL and kept the stream.
    $redis->execute('XACK', $prefix.'slowSync', 'torque', $id);
    prefetchHeldFor($worker, $prefix.'slowSync|'.$id, 61.0);

    $next = prefetchRead($worker, $redis, ['slowSync'], $prefix);

    expect($next['payload'] ?? null)->toBe('b')
        ->and($next['id'])->not->toBe($id)
        ->and(prefetchRead($worker, $redis, ['slowSync'], $prefix))->toBeNull();
});

it('does not charge a redelivery when the ownership check fails', function () {
    [$worker, , $prefix, $id] = prefetchRig();
    prefetchHeldFor($worker, $prefix.'slowSync|'.$id, 61.0);

    $failing = prefetchLink(function (string $command, array $parameters, Closure $next): RedisResponse {
        if (in_array($command, ['EVAL', 'XPENDING'], true)) {
            throw new RuntimeException('connection reset');
        }

        return $next();
    });

    expect(prefetchRead($worker, $failing, ['slowSync'], $prefix))->toBeNull()
        ->and(prefetched($worker))->toBe([])
        ->and(prefetchProperty($worker, 'neverStarted'))->toBe([$prefix.'slowSync|'.$id => false]);
});
