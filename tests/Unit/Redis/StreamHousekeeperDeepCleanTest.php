<?php

declare(strict_types=1);

use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Redis\StreamHousekeeper;

/*
 * deepClean() is the upgrade sweep: everything an older Torque left behind and
 * never expired. Seeded against the live test Redis, one category at a time,
 * because the whole point is the SCAN / TTL / ZREM behaviour.
 *
 * The orphan rule: a per-job event stream with no expiry (only terminal events
 * set one) whose last entry is older than job_streams.ttl plus the safety
 * margin. A running job's stream is younger than that and must survive.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-deep-'.bin2hex(random_bytes(4)).':';
    $this->ttl = 300;

    $this->housekeeper = fn (): StreamHousekeeper => new StreamHousekeeper(
        redisUri: torqueRedisUri(),
        prefix: $this->prefix,
        consumerGroup: 'torque-test',
        queues: ['default'],
        deadLetters: new DeadLetterHandler(
            redisUri: torqueRedisUri(),
            ttl: 60,
            prefix: $this->prefix,
            maxEntries: 0,
        ),
        jobStreamTtl: $this->ttl,
    );

    // A stream entry id is "{milliseconds}-{sequence}", so seeding an old id
    // is enough to make a stream look abandoned.
    $this->seedJobStream = function (string $uuid, int $ageSeconds, bool $withTtl = false): void {
        $key = $this->prefix.'job:'.$uuid;
        $this->redis->execute('XADD', $key, ((time() - $ageSeconds) * 1000).'-0', 'type', 'started');

        if ($withTtl) {
            $this->redis->execute('EXPIRE', $key, '300');
        }
    };
});

afterEach(function () {
    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('deletes orphaned job streams and keeps live ones', function () {
    ($this->seedJobStream)('abandoned', 7200);          // older than ttl + margin, no expiry
    ($this->seedJobStream)('running', 30);              // in flight right now
    ($this->seedJobStream)('terminal', 7200, true);     // already carries its TTL

    $counts = ($this->housekeeper)()->deepClean();

    expect($counts['job_streams'])->toBe(1)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'job:abandoned'))->toBe(0)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'job:running'))->toBe(1)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'job:terminal'))->toBe(1);
});

it('drops index members whose job stream is gone', function () {
    ($this->seedJobStream)('running', 30);

    $this->redis->execute('ZADD', $this->prefix.'jobs:active', (string) time(), 'running');
    $this->redis->execute('ZADD', $this->prefix.'jobs:active', (string) time(), 'vanished');
    $this->redis->execute('ZADD', $this->prefix.'jobs:recent', (string) time(), 'vanished');

    $counts = ($this->housekeeper)()->deepClean();

    expect($counts['index_members'])->toBe(2)
        ->and($this->redis->execute('ZRANGE', $this->prefix.'jobs:active', '0', '-1'))->toBe(['running'])
        ->and($this->redis->execute('ZRANGE', $this->prefix.'jobs:recent', '0', '-1'))->toBe([]);
});

it('removes an orphaned stream and its index member in one pass', function () {
    ($this->seedJobStream)('abandoned', 7200);
    $this->redis->execute('ZADD', $this->prefix.'jobs:active', (string) time(), 'abandoned');

    $counts = ($this->housekeeper)()->deepClean();

    expect($counts['job_streams'])->toBe(1)
        ->and($counts['index_members'])->toBe(1)
        ->and($this->redis->execute('ZRANGE', $this->prefix.'jobs:active', '0', '-1'))->toBe([]);
});

it('removes the legacy bucket hash only once its replacement exists', function () {
    $this->redis->execute('HSET', $this->prefix.'metrics:buckets', '29000000', '5');

    expect(($this->housekeeper)()->deepClean()['legacy_keys'])->toBe(0)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'metrics:buckets'))->toBe(1);

    // Once the minute rollup exists, the migration has run and the old hash
    // is safe to drop.
    $this->redis->execute('HSET', $this->prefix.'metrics:rollup:minute', '1756339200', '5,0');

    expect(($this->housekeeper)()->deepClean()['legacy_keys'])->toBe(1)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'metrics:buckets'))->toBe(0)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'metrics:rollup:minute'))->toBe(1);
});

it('removes worker hashes without a recent heartbeat', function () {
    $this->redis->execute('HSET', $this->prefix.'worker:live', 'last_heartbeat', (string) time());
    $this->redis->execute('HSET', $this->prefix.'worker:ghost', 'last_heartbeat', (string) (time() - 600));
    $this->redis->execute('HSET', $this->prefix.'worker:nameless', 'pid', '123');

    $counts = ($this->housekeeper)()->deepClean();

    expect($counts['legacy_keys'])->toBe(2)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'worker:live'))->toBe(1)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'worker:ghost'))->toBe(0)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'worker:nameless'))->toBe(0);
});

it('trims the dead-letter stream and stale consumers as part of the sweep', function () {
    $deadKey = $this->prefix.'dead-letter';
    $this->redis->execute('XADD', $deadKey, ((time() - 3600) * 1000).'-0', 'payload', 'stale');
    $this->redis->execute('XADD', $deadKey, '*', 'payload', 'fresh');

    $stream = $this->prefix.'default';
    $this->redis->execute('XGROUP', 'CREATE', $stream, 'torque-test', '$', 'MKSTREAM');
    $this->redis->execute('XGROUP', 'CREATECONSUMER', $stream, 'torque-test', 'dead-worker');

    $counts = ($this->housekeeper)()->deepClean(consumerIdleSeconds: 0);

    expect($counts['dead_letter'])->toBe(1)
        ->and($counts['consumers'])->toBe(1)
        ->and((int) $this->redis->execute('XLEN', $deadKey))->toBe(1)
        ->and($this->redis->execute('XINFO', 'CONSUMERS', $stream, 'torque-test'))->toBe([]);
});

it('keeps consumers that are within the idle threshold', function () {
    $stream = $this->prefix.'default';
    $this->redis->execute('XGROUP', 'CREATE', $stream, 'torque-test', '$', 'MKSTREAM');
    $this->redis->execute('XGROUP', 'CREATECONSUMER', $stream, 'torque-test', 'fresh-worker');

    expect(($this->housekeeper)()->deepClean()['consumers'])->toBe(0)
        ->and($this->redis->execute('XINFO', 'CONSUMERS', $stream, 'torque-test'))->toHaveCount(1);
});

it('reports every category and changes nothing on a dry run', function () {
    ($this->seedJobStream)('abandoned', 7200);
    $this->redis->execute('ZADD', $this->prefix.'jobs:recent', (string) time(), 'vanished');
    $this->redis->execute('HSET', $this->prefix.'worker:ghost', 'last_heartbeat', (string) (time() - 600));
    $this->redis->execute('XADD', $this->prefix.'dead-letter', ((time() - 3600) * 1000).'-0', 'payload', 'stale');

    $counts = ($this->housekeeper)()->deepClean(dryRun: true);

    expect(array_keys($counts))
        ->toBe(['job_streams', 'index_members', 'dead_letter', 'consumers', 'legacy_keys'])
        ->and($counts['job_streams'])->toBe(1)
        ->and($counts['index_members'])->toBe(1)
        ->and($counts['legacy_keys'])->toBe(1)
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'job:abandoned'))->toBe(1)
        ->and($this->redis->execute('ZRANGE', $this->prefix.'jobs:recent', '0', '-1'))->toBe(['vanished'])
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'worker:ghost'))->toBe(1)
        ->and((int) $this->redis->execute('XLEN', $this->prefix.'dead-letter'))->toBe(1);
});

it('reports zeroes on a clean install', function () {
    expect(($this->housekeeper)()->deepClean())->toBe([
        'job_streams' => 0,
        'index_members' => 0,
        'dead_letter' => 0,
        'consumers' => 0,
        'legacy_keys' => 0,
    ]);
});
