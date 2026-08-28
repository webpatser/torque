<?php

declare(strict_types=1);

use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Redis\StreamHousekeeper;

/*
 * The housekeeper is the shared implementation behind `torque:prune` and the
 * master's own maintenance tick. It runs against the live test Redis: the
 * whole point is the XTRIM / XINFO / XGROUP behaviour, which a fake would
 * only re-describe.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-hk-'.bin2hex(random_bytes(4)).':';
    $this->group = 'torque-test';

    $this->housekeeper = function (int $maxEntries = 0, int $ttl = 60): StreamHousekeeper {
        return new StreamHousekeeper(
            redisUri: torqueRedisUri(),
            prefix: $this->prefix,
            consumerGroup: $this->group,
            queues: ['default'],
            deadLetters: new DeadLetterHandler(
                redisUri: torqueRedisUri(),
                ttl: $ttl,
                prefix: $this->prefix,
                maxEntries: $maxEntries,
            ),
            maxEntries: $maxEntries,
        );
    };
});

afterEach(function () {
    $this->redis->execute('DEL', $this->prefix.'dead-letter', $this->prefix.'default');
});

it('trims dead-letter entries older than the ttl and reports the change', function () {
    $key = $this->prefix.'dead-letter';
    $old = ((time() - 3600) * 1000).'-0';

    $this->redis->execute('XADD', $key, $old, 'payload', 'stale');

    for ($i = 0; $i < 5; $i++) {
        $this->redis->execute('XADD', $key, '*', 'payload', 'fresh');
    }

    $result = ($this->housekeeper)()->pruneDeadLetter();

    expect($result['before'])->toBe(6)
        ->and($result['after'])->toBe(5)
        ->and($this->redis->execute('XRANGE', $key, $old, $old))->toBe([]);
});

it('leaves the stream alone on a dry run', function () {
    $key = $this->prefix.'dead-letter';
    $old = ((time() - 3600) * 1000).'-0';
    $this->redis->execute('XADD', $key, $old, 'payload', 'stale');

    $result = ($this->housekeeper)()->pruneDeadLetter(dryRun: true);

    expect($result)->toBe(['before' => 1, 'after' => 1])
        ->and((int) $this->redis->execute('XLEN', $key))->toBe(1);
});

it('deletes idle consumers without pending messages and keeps busy ones', function () {
    $stream = $this->prefix.'default';

    $this->redis->execute('XGROUP', 'CREATE', $stream, $this->group, '$', 'MKSTREAM');
    $this->redis->execute('XGROUP', 'CREATECONSUMER', $stream, $this->group, 'dead-worker');
    $this->redis->execute('XADD', $stream, '*', 'payload', 'job');
    $this->redis->execute('XREADGROUP', 'GROUP', $this->group, 'busy-worker', 'COUNT', '1', 'STREAMS', $stream, '>');

    $removed = ($this->housekeeper)()->pruneConsumers(idleSeconds: 0);

    $names = array_map(
        fn ($consumer) => array_is_list($consumer) ? $consumer[1] : $consumer['name'],
        $this->redis->execute('XINFO', 'CONSUMERS', $stream, $this->group),
    );

    expect($removed)->toBe(['default' => 1])
        ->and($names)->toBe(['busy-worker']);
});

it('keeps consumers that have not been idle long enough', function () {
    $stream = $this->prefix.'default';

    $this->redis->execute('XGROUP', 'CREATE', $stream, $this->group, '$', 'MKSTREAM');
    $this->redis->execute('XGROUP', 'CREATECONSUMER', $stream, $this->group, 'fresh-worker');

    $removed = ($this->housekeeper)()->pruneConsumers(idleSeconds: 3600);

    expect($removed)->toBe(['default' => 0])
        ->and($this->redis->execute('XINFO', 'CONSUMERS', $stream, $this->group))->toHaveCount(1);
});

it('counts without deleting on a dry run', function () {
    $stream = $this->prefix.'default';

    $this->redis->execute('XGROUP', 'CREATE', $stream, $this->group, '$', 'MKSTREAM');
    $this->redis->execute('XGROUP', 'CREATECONSUMER', $stream, $this->group, 'dead-worker');

    $removed = ($this->housekeeper)()->pruneConsumers(idleSeconds: 0, dryRun: true);

    expect($removed)->toBe(['default' => 1])
        ->and($this->redis->execute('XINFO', 'CONSUMERS', $stream, $this->group))->toHaveCount(1);
});

it('reports nothing for a stream whose group does not exist yet', function () {
    expect(($this->housekeeper)()->pruneConsumers(idleSeconds: 0))->toBe(['default' => 0]);
});
