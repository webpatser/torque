<?php

declare(strict_types=1);

use function Amp\Redis\createRedisClient;

beforeEach(function () {
    // Point the torque config at the test Redis database so the command
    // and our assertions use the same server/prefix.
    $this->testRedisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
    $this->testPrefix = 'torque-pause-test:';

    config()->set('torque.redis.uri', $this->testRedisUri);
    config()->set('torque.redis.prefix', $this->testPrefix);

    // Clean up before each test.
    try {
        $redis = createRedisClient($this->testRedisUri);
        $redis->execute('DEL', $this->testPrefix . 'paused');
    } catch (\Amp\Redis\RedisException) {
        // Will be caught per-test.
    }
});

afterEach(function () {
    try {
        $redis = createRedisClient($this->testRedisUri);
        $redis->execute('DEL', $this->testPrefix . 'paused');
    } catch (\Amp\Redis\RedisException) {
        // Ignore.
    }
});

it('pauses by setting Redis key', function () {
    try {
        $this->artisan('torque:pause', ['action' => 'pause'])
            ->assertSuccessful();

        $redis = createRedisClient($this->testRedisUri);
        $exists = (int) $redis->execute('EXISTS', $this->testPrefix . 'paused');

        expect($exists)->toBe(1);
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('continues by removing Redis key', function () {
    try {
        // Set paused state first.
        $redis = createRedisClient($this->testRedisUri);
        $redis->execute('SET', $this->testPrefix . 'paused', (string) time());

        $this->artisan('torque:pause', ['action' => 'continue'])
            ->assertSuccessful();

        $exists = (int) $redis->execute('EXISTS', $this->testPrefix . 'paused');

        expect($exists)->toBe(0);
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('toggles the paused state on when currently running', function () {
    try {
        $this->artisan('torque:pause', ['action' => 'toggle'])
            ->assertSuccessful();

        $redis = createRedisClient($this->testRedisUri);
        $exists = (int) $redis->execute('EXISTS', $this->testPrefix . 'paused');

        expect($exists)->toBe(1);
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('toggles the paused state off when currently paused', function () {
    try {
        // Set paused state first.
        $redis = createRedisClient($this->testRedisUri);
        $redis->execute('SET', $this->testPrefix . 'paused', (string) time());

        $this->artisan('torque:pause', ['action' => 'toggle'])
            ->assertSuccessful();

        $exists = (int) $redis->execute('EXISTS', $this->testPrefix . 'paused');

        expect($exists)->toBe(0);
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('rejects invalid action', function () {
    try {
        $this->artisan('torque:pause', ['action' => 'invalid'])
            ->assertFailed()
            ->expectsOutputToContain('Invalid action');
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('reports already paused when pause is called twice', function () {
    try {
        $this->artisan('torque:pause', ['action' => 'pause']);

        $this->artisan('torque:pause', ['action' => 'pause'])
            ->assertSuccessful()
            ->expectsOutputToContain('already paused');
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('reports already running when continue is called while not paused', function () {
    try {
        $this->artisan('torque:pause', ['action' => 'continue'])
            ->assertSuccessful()
            ->expectsOutputToContain('already running');
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
