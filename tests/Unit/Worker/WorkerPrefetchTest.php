<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\Redis\createRedisClient;

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
