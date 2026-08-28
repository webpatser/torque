<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

use function Fledge\Async\Redis\createRedisClient;

// Mirrors the setup used by TorqueFlushCommandTest: a live Redis, prefixed keys, flushed after each test.

beforeEach(function () {
    config(['torque.redis.prefix' => 'torque-test:', 'torque.dead_letter.ttl' => 60, 'torque.streams' => ['default' => []]]);
    $this->redis = createRedisClient(config('torque.redis.uri'));
    $this->redis->execute('DEL', 'torque-test:dead-letter', 'torque-test:default');

    foreach ($this->redis->execute('KEYS', 'torque-test:job*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('trims dead-letter entries older than the ttl and caps the stream', function () {
    $old = (string) ((time() - 3600) * 1000).'-0';
    $this->redis->execute('XADD', 'torque-test:dead-letter', $old, 'payload', 'x');
    for ($i = 0; $i < 5; $i++) {
        $this->redis->execute('XADD', 'torque-test:dead-letter', '*', 'payload', 'y');
    }

    Artisan::call('torque:prune', ['--dead-letter-max' => 3]);

    expect((int) $this->redis->execute('XLEN', 'torque-test:dead-letter'))->toBeLessThanOrEqual(5)
        ->and($this->redis->execute('XRANGE', 'torque-test:dead-letter', $old, $old))->toBe([]);
});

it('deletes idle consumers without pending messages and keeps busy ones', function () {
    $group = config('torque.consumer_group');
    $this->redis->execute('XGROUP', 'CREATE', 'torque-test:default', $group, '$', 'MKSTREAM');
    $this->redis->execute('XGROUP', 'CREATECONSUMER', 'torque-test:default', $group, 'dead-worker');
    $this->redis->execute('XADD', 'torque-test:default', '*', 'payload', 'job');
    $this->redis->execute('XREADGROUP', 'GROUP', $group, 'busy-worker', 'COUNT', '1', 'STREAMS', 'torque-test:default', '>');

    Artisan::call('torque:prune', ['--consumer-idle' => 0]);

    $names = array_map(fn ($c) => array_is_list($c) ? $c[1] : $c['name'], $this->redis->execute('XINFO', 'CONSUMERS', 'torque-test:default', $group));

    expect($names)->toBe(['busy-worker']);
});

/*
 * --deep adds the upgrade sweep (orphaned job streams, stale index members,
 * legacy metric keys) to the routine trim, and reports every category.
 */

it('sweeps upgrade leftovers with --deep', function () {
    $this->redis->execute('XADD', 'torque-test:job:abandoned', ((time() - 7200) * 1000).'-0', 'type', 'started');
    $this->redis->execute('ZADD', 'torque-test:jobs:recent', (string) time(), 'vanished');

    Artisan::call('torque:prune', ['--deep' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Orphaned job streams')
        ->and($output)->toContain('Stale index members')
        ->and($output)->toContain('Legacy metric keys')
        ->and((int) $this->redis->execute('EXISTS', 'torque-test:job:abandoned'))->toBe(0)
        ->and($this->redis->execute('ZRANGE', 'torque-test:jobs:recent', '0', '-1'))->toBe([]);
});

it('previews the deep sweep without touching anything', function () {
    $this->redis->execute('XADD', 'torque-test:job:abandoned', ((time() - 7200) * 1000).'-0', 'type', 'started');

    Artisan::call('torque:prune', ['--deep' => true, '--dry-run' => true]);

    expect(Artisan::output())->toContain('(dry run)')
        ->and((int) $this->redis->execute('EXISTS', 'torque-test:job:abandoned'))->toBe(1);
});

it('keeps a job stream that is still running during a deep sweep', function () {
    $this->redis->execute('XADD', 'torque-test:job:live', (string) (time() * 1000).'-0', 'type', 'started');

    Artisan::call('torque:prune', ['--deep' => true]);

    expect((int) $this->redis->execute('EXISTS', 'torque-test:job:live'))->toBe(1);
});
