<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ClearableQueue;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\AttemptProbeJob;

/*
|--------------------------------------------------------------------------
| StreamQueue::clear() Tests
|--------------------------------------------------------------------------
|
| queue:clear must empty the stream (XTRIM, never DEL, so the consumer group
| survives) and the delayed set. A real worker runs across the clear.
|
| Requires a running Redis instance.
|
*/

function torque_clear_queue(string $prefix, string $group): StreamQueue
{
    $queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: 'clearing',
        retryAfter: 90,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: $group,
    );
    $queue->setContainer(app());
    $queue->setConnectionName('torque');
    $queue->ensureConsumerGroup($queue->getStreamKey(), $group);

    return $queue;
}

function torque_clear_cleanup(string $prefix): void
{
    $redis = torqueRedis();
    $cursor = '0';

    do {
        [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');
        $cursor = (string) $cursor;

        foreach ($keys ?? [] as $key) {
            $redis->execute('DEL', (string) $key);
        }
    } while ($cursor !== '0');
}

it('implements ClearableQueue and returns stream plus delayed jobs removed', function () {
    $prefix = 'torque-clear-'.bin2hex(random_bytes(4)).':';
    $queue = torque_clear_queue($prefix, 'clear-group');
    $redis = $queue->getRedisClient();

    try {
        expect($queue)->toBeInstanceOf(ClearableQueue::class);

        foreach (range(1, 3) as $i) {
            (void) $queue->push(new AttemptProbeJob('ok'), '', 'clearing');
        }
        foreach (range(1, 2) as $i) {
            (void) $queue->later(3600, new AttemptProbeJob('ok'), '', 'clearing');
        }

        // size() is waiting (3) + delayed (2) + reserved (0).
        expect($queue->size('clearing'))->toBe(5)
            ->and($queue->pendingSize('clearing'))->toBe(3)
            ->and($queue->delayedSize('clearing'))->toBe(2);

        expect($queue->clear('clearing'))->toBe(5)
            ->and($queue->size('clearing'))->toBe(0)
            ->and($queue->delayedSize('clearing'))->toBe(0)
            ->and($queue->clear('clearing'))->toBe(0);

        // The consumer group survived (DEL would have destroyed it).
        $groups = $redis->execute('XINFO', 'GROUPS', $queue->getStreamKey('clearing'));
        expect($groups)->toHaveCount(1);
    } finally {
        torque_clear_cleanup($prefix);
    }
});

it('leaves a trimmed PEL entry reclaimable without crashing or looping', function () {
    $prefix = 'torque-clear-'.bin2hex(random_bytes(4)).':';
    $queue = torque_clear_queue($prefix, 'clear-group');
    $redis = $queue->getRedisClient();
    $key = $queue->getStreamKey('clearing');

    try {
        (void) $queue->push(new AttemptProbeJob('ok'), '', 'clearing');
        $read = $redis->execute('XREADGROUP', 'GROUP', 'clear-group', 'c1', 'COUNT', '1', 'STREAMS', $key, '>');
        $id = (string) $read[0][1][0][0];

        expect($queue->clear('clearing'))->toBe(1);

        // The PEL record stays: clear() does not claim to have removed it.
        expect($queue->reservedSize('clearing'))->toBe(1);

        // Same call the worker's steal path makes. Must return cleanly and, on
        // Redis 7+, drop the dead id from the PEL instead of returning it forever.
        $claim = $redis->execute('XAUTOCLAIM', $key, 'clear-group', 'c2', '0', '0-0', 'COUNT', '1');
        expect($claim[1] ?? [])->toBe([])
            ->and(array_map('strval', $claim[2] ?? []))->toBe([$id])
            ->and($queue->reservedSize('clearing'))->toBe(0);

        // Acking an id that is no longer pending is harmless.
        expect((int) $redis->execute('XACK', $key, 'clear-group', $id))->toBe(0);
    } finally {
        torque_clear_cleanup($prefix);
    }
});

it('lets a real worker finish its in-flight job and consume one dispatched after the clear', function () {
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-clear-{$token}:";
    $group = 'clear-group';
    $logFile = sys_get_temp_dir()."/torque-clear-{$token}.jsonl";
    $outputFile = sys_get_temp_dir()."/torque-clear-{$token}.out";
    $queue = torque_clear_queue($prefix, $group);
    $key = $queue->getStreamKey('clearing');

    (void) $queue->push(new AttemptProbeJob('slow'), '', 'clearing');
    (void) $queue->push(new AttemptProbeJob('ok'), '', 'clearing');
    (void) $queue->later(3600, new AttemptProbeJob('ok'), '', 'clearing');

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Fixtures/job-attempted-worker.php', torqueRedisUri(), $prefix, $group, 'clearing', $logFile, '2', '1', '2', '10'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
        $pipes,
    );

    try {
        expect(is_resource($process))->toBeTrue();

        // Wait until the worker holds the slow job in its PEL.
        $deadline = microtime(true) + 20;
        while ($queue->reservedSize('clearing') < 1 && microtime(true) < $deadline) {
            usleep(50_000);
        }
        expect($queue->reservedSize('clearing'))->toBe(1);

        // Waiting ok job (1) + the in-flight entry trimmed from the stream (1) + delayed (1).
        // size() is 1 afterwards, not 0: the slow job really is still running and
        // its PEL record counts as reserved until the worker acks it.
        expect($queue->clear('clearing'))->toBe(3)
            ->and($queue->size('clearing'))->toBe(1)
            ->and($queue->reservedSize('clearing'))->toBe(1)
            ->and($queue->delayedSize('clearing'))->toBe(0);

        (void) $queue->push(new AttemptProbeJob('ok'), '', 'clearing');

        $deadline = microtime(true) + 45;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(100_000);
        }
        $timedOut = proc_get_status($process)['running'];
        if ($timedOut) {
            proc_terminate($process, SIGKILL);
        }
        proc_close($process);

        $output = (string) @file_get_contents($outputFile);
        expect($timedOut)->toBeFalse($output);

        $rows = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        );
        $attempted = array_values(array_filter($rows, static fn (array $r): bool => $r['type'] === 'attempted'));

        expect(array_column($attempted, 'job'))->toBe(['probe:slow', 'probe:ok'])
            ->and(array_column($attempted, 'successful'))->toBe([true, true])
            ->and($queue->reservedSize('clearing'))->toBe(0);
    } finally {
        @unlink($logFile);
        @unlink($outputFile);
        torque_clear_cleanup($prefix);
    }
});
