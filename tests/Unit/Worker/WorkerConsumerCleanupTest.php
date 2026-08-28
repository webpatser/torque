<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

/*
 * Every worker start mints a fresh `{host}-{pid}-{hex}` consumer name. Nothing
 * ever removed the old ones, so scrpr accumulated 124k consumers per stream.
 * On shutdown a worker now deletes its own consumer, but only when its PEL is
 * empty: XGROUP DELCONSUMER discards pending entries, which must stay
 * claimable by the next worker's XAUTOCLAIM instead.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-consumer-'.bin2hex(random_bytes(4)).':';
    $this->group = 'torque-test';
    $this->stream = $this->prefix.'default';

    $this->worker = new WorkerProcess([
        'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
        'consumer_group' => $this->group,
    ]);

    $this->buildStreamKey = fn (string $queue): string => $this->prefix.$queue;

    $this->redis->execute('XGROUP', 'CREATE', $this->stream, $this->group, '$', 'MKSTREAM');
});

afterEach(function () {
    $this->redis->execute('DEL', $this->stream);
});

function torqueConsumerNames(mixed $redis, string $stream, string $group): array
{
    return array_map(
        fn ($consumer) => (string) (array_is_list($consumer) ? $consumer[1] : $consumer['name']),
        $redis->execute('XINFO', 'CONSUMERS', $stream, $group),
    );
}

it('deletes its own consumer when nothing is pending', function () {
    $this->redis->execute('XGROUP', 'CREATECONSUMER', $this->stream, $this->group, $this->worker->consumerId);

    expect(torqueConsumerNames($this->redis, $this->stream, $this->group))->toBe([$this->worker->consumerId]);

    $this->worker->releaseConsumer(torqueRedisUri(), ['default'], $this->group, $this->buildStreamKey);

    expect(torqueConsumerNames($this->redis, $this->stream, $this->group))->toBe([]);
});

it('keeps its consumer when it still has pending entries', function () {
    $this->redis->execute('XADD', $this->stream, '*', 'payload', '{"uuid":"pending-1"}');
    $this->redis->execute(
        'XREADGROUP', 'GROUP', $this->group, $this->worker->consumerId,
        'COUNT', '1', 'STREAMS', $this->stream, '>',
    );

    $this->worker->releaseConsumer(torqueRedisUri(), ['default'], $this->group, $this->buildStreamKey);

    expect(torqueConsumerNames($this->redis, $this->stream, $this->group))->toBe([$this->worker->consumerId])
        ->and($this->redis->execute('XPENDING', $this->stream, $this->group, '-', '+', '1', $this->worker->consumerId))
        ->not->toBe([]);
});

it('leaves other consumers alone', function () {
    $this->redis->execute('XGROUP', 'CREATECONSUMER', $this->stream, $this->group, 'another-worker');
    $this->redis->execute('XGROUP', 'CREATECONSUMER', $this->stream, $this->group, $this->worker->consumerId);

    $this->worker->releaseConsumer(torqueRedisUri(), ['default'], $this->group, $this->buildStreamKey);

    expect(torqueConsumerNames($this->redis, $this->stream, $this->group))->toBe(['another-worker']);
});

it('is a no-op for a stream that has no group', function () {
    $this->worker->releaseConsumer(torqueRedisUri(), ['missing'], $this->group, $this->buildStreamKey);
})->throwsNoExceptions();
