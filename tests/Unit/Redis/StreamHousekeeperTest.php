<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Queue\StreamQueue;
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

describe('redelivery counts', function () {
    beforeEach(function () {
        // Unit tests do not boot Testbench, and MasterProcess resolves its artisan
        // path through the application, so these bring their own.
        $this->previousContainer = Container::getInstance();
        new Application(dirname(__DIR__, 3));

        $this->stream = $this->prefix.'default';
        $this->hash = $this->stream.StreamQueue::REDELIVERIES_SUFFIX;

        // One round trip, whatever the size. A hash this big is a real hash table,
        // so HSCAN honours COUNT instead of returning everything at once.
        $this->seed = fn (int $live, int $stale) => $this->redis->execute('EVAL', <<<'LUA'
            for i = 1, tonumber(ARGV[1]) do
                redis.call('XADD', KEYS[1], i .. '-1', 'payload', 'x')
                redis.call('HSET', KEYS[2], i .. '-1', '1')
            end
            for i = 1, tonumber(ARGV[2]) do
                redis.call('HSET', KEYS[2], (1000000 + i) .. '-0', '1')
            end
            return 1
            LUA, '2', $this->stream, $this->hash, (string) $live, (string) $stale);
    });

    afterEach(function () {
        Container::setInstance($this->previousContainer);

        $this->redis->execute('DEL', $this->hash);
    });

    it('caps a sweep and continues the scan on the next one, and never drops a live field', function () {
        ($this->seed)(2000, 300);

        $housekeeper = ($this->housekeeper)();
        $limit = StreamHousekeeper::REDELIVERY_FIELDS_PER_SWEEP;

        (void) $housekeeper->pruneConsumers(3600, redeliveryFieldLimit: $limit);

        $afterOne = (int) $this->redis->execute('HLEN', $this->hash);

        // Only about 1000 of the 2300 were looked at, so some stale fields are left.
        expect($afterOne)->toBeGreaterThan(2000)->toBeLessThan(2300);

        // A scan restarted from 0 would keep revisiting that same first window.
        for ($sweep = 0; $sweep < 3; $sweep++) {
            (void) $housekeeper->pruneConsumers(3600, redeliveryFieldLimit: $limit);
        }

        $fields = array_map(strval(...), $this->redis->execute('HKEYS', $this->hash));
        sort($fields);

        $live = array_map(fn (int $i): string => $i.'-1', range(1, 2000));
        sort($live);

        expect($fields)->toBe($live);
    });

    it('sweeps the whole hash in one go without a limit, as torque:prune does', function () {
        ($this->seed)(0, 1500);

        (void) ($this->housekeeper)()->pruneConsumers(3600);

        expect((int) $this->redis->execute('EXISTS', $this->hash))->toBe(0);
    });

    it('caps the sweep in the master tick', function () {
        ($this->seed)(0, 2500);

        $master = new MasterProcess([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
            'consumer_group' => $this->group,
            'streams' => ['default' => []],
        ], static function (string $message): void {});

        $master->handleHousekeepingTick();

        // At most one HSCAN page past the limit.
        expect((int) $this->redis->execute('HLEN', $this->hash))
            ->toBeLessThan(2500)
            ->toBeGreaterThan(2500 - StreamHousekeeper::REDELIVERY_FIELDS_PER_SWEEP - 200);
    });

    /*
     * A socket kept for the 300s between sweeps can be dropped silently, and with no
     * read timeout the master's loop would then wait for TCP to give up. So each tick
     * connects anew, and only the scan position stays behind.
     */
    it('connects anew at each master tick, and keeps the scan position across them', function () {
        ($this->seed)(2000, 300);

        $master = new MasterProcess([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
            'consumer_group' => $this->group,
            'streams' => ['default' => []],
        ], static function (string $message): void {});

        $due = new ReflectionProperty($master, 'housekeepingDueAt');
        $stat = function (string $section, string $name): int {
            preg_match('/^'.$name.':(\d+)/m', (string) $this->redis->execute('INFO', $section), $found);

            return (int) ($found[1] ?? 0);
        };

        $master->handleHousekeepingTick();

        $clientsAfterOne = $stat('clients', 'connected_clients');
        $connections = $stat('stats', 'total_connections_received');

        // A scan restarted from 0 would keep revisiting that same first window.
        for ($tick = 0; $tick < 3; $tick++) {
            $due->setValue($master, null);
            $master->handleHousekeepingTick();
        }

        $fields = array_map(strval(...), $this->redis->execute('HKEYS', $this->hash));

        expect($stat('stats', 'total_connections_received') - $connections)->toBeGreaterThanOrEqual(3)
            ->and($stat('clients', 'connected_clients'))->toBeLessThanOrEqual($clientsAfterOne)
            ->and($fields)->toHaveCount(2000);
    });
});
