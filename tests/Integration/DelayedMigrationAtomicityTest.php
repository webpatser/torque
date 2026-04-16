<?php

declare(strict_types=1);

use function Fledge\Async\Redis\createRedisClient;

/*
|--------------------------------------------------------------------------
| Delayed Migration Atomicity Test
|--------------------------------------------------------------------------
|
| Verifies that the Lua-based delayed job migration is atomic: calling it
| from multiple "workers" (connections) in rapid succession must never
| produce duplicate jobs in the stream.
|
*/

$testPrefix = 'torque-atomicity-test-' . bin2hex(random_bytes(4)) . ':';

$luaMigrateDelayed = <<<'LUA'
local entries = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 100)
if #entries == 0 then return 0 end
for i, payload in ipairs(entries) do
    redis.call('ZREM', KEYS[1], payload)
    redis.call('XADD', KEYS[2], '*', 'payload', payload)
end
return #entries
LUA;

afterEach(function () use ($testPrefix) {
    try {
        $redis = createRedisClient(env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'));
        $redis->execute('DEL', $testPrefix . 'default');
        $redis->execute('DEL', $testPrefix . 'default:delayed');
    } catch (\Fledge\Async\Redis\RedisException) {
        // Best-effort cleanup.
    }
});

it('does not duplicate jobs when multiple workers migrate simultaneously', function () use ($testPrefix, $luaMigrateDelayed) {
    $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
    $delayedKey = $testPrefix . 'default:delayed';
    $streamKey = $testPrefix . 'default';

    try {
        $redis = createRedisClient($redisUri);

        // Clean slate.
        $redis->execute('DEL', $delayedKey);
        $redis->execute('DEL', $streamKey);

        // Seed 50 delayed jobs that are already matured (timestamp in the past).
        $now = time();
        for ($i = 0; $i < 50; $i++) {
            $payload = json_encode([
                'uuid' => "atomicity-test-{$i}",
                'displayName' => 'TestJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [],
                'attempts' => 0,
            ]);
            $redis->execute('ZADD', $delayedKey, (string) ($now - 60), $payload);
        }

        expect((int) $redis->execute('ZCARD', $delayedKey))->toBe(50);

        // Simulate two workers calling the migration script "simultaneously".
        // Because EVAL is atomic, only one will see the entries.
        $migrated1 = $redis->execute('EVAL', $luaMigrateDelayed, '2', $delayedKey, $streamKey, (string) $now);
        $migrated2 = $redis->execute('EVAL', $luaMigrateDelayed, '2', $delayedKey, $streamKey, (string) $now);

        // Exactly 50 jobs should have been migrated in total.
        expect((int) $migrated1 + (int) $migrated2)->toBe(50);

        // The stream should contain exactly 50 entries, not 100.
        $streamLen = (int) $redis->execute('XLEN', $streamKey);
        expect($streamLen)->toBe(50);

        // The delayed set should be empty.
        $remaining = (int) $redis->execute('ZCARD', $delayedKey);
        expect($remaining)->toBe(0);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('handles empty delayed set gracefully', function () use ($testPrefix, $luaMigrateDelayed) {
    $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
    $delayedKey = $testPrefix . 'default:delayed';
    $streamKey = $testPrefix . 'default';

    try {
        $redis = createRedisClient($redisUri);

        $redis->execute('DEL', $delayedKey);
        $redis->execute('DEL', $streamKey);

        $migrated = $redis->execute('EVAL', $luaMigrateDelayed, '2', $delayedKey, $streamKey, (string) time());

        expect((int) $migrated)->toBe(0);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('only migrates jobs with matured timestamps', function () use ($testPrefix, $luaMigrateDelayed) {
    $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
    $delayedKey = $testPrefix . 'default:delayed';
    $streamKey = $testPrefix . 'default';

    try {
        $redis = createRedisClient($redisUri);

        $redis->execute('DEL', $delayedKey);
        $redis->execute('DEL', $streamKey);

        $now = time();

        // 3 matured jobs (past timestamp).
        for ($i = 0; $i < 3; $i++) {
            $redis->execute('ZADD', $delayedKey, (string) ($now - 10), json_encode(['uuid' => "matured-{$i}"]));
        }

        // 2 future jobs (should NOT be migrated).
        for ($i = 0; $i < 2; $i++) {
            $redis->execute('ZADD', $delayedKey, (string) ($now + 3600), json_encode(['uuid' => "future-{$i}"]));
        }

        $migrated = (int) $redis->execute('EVAL', $luaMigrateDelayed, '2', $delayedKey, $streamKey, (string) $now);

        expect($migrated)->toBe(3);
        expect((int) $redis->execute('XLEN', $streamKey))->toBe(3);
        expect((int) $redis->execute('ZCARD', $delayedKey))->toBe(2);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
