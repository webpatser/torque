<?php

declare(strict_types=1);

use Webpatser\Torque\Pool\PooledConnection;
use Webpatser\Torque\Pool\RedisPool;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

beforeEach(function () {
    try {
        $this->pool = new RedisPool(
            uri: 'redis://127.0.0.1:6379/15',
            size: 3,
        );

        // Verify connectivity by checking out and releasing a connection.
        $conn = $this->pool->checkout();
        $conn->release();
    } catch (\Throwable) {
        $this->markTestSkipped('Redis is not available at 127.0.0.1:6379');
    }
});

it('exposes the uri via asymmetric visibility', function () {
    expect($this->pool->uri)->toBe('redis://127.0.0.1:6379/15');
});

it('checkout returns a PooledConnection', function () {
    $conn = $this->pool->checkout();

    expect($conn)->toBeInstanceOf(PooledConnection::class);
    expect($conn->raw)->not->toBeNull();

    $conn->release();
});

it('use() executes callback and auto-releases', function () {
    $result = $this->pool->use(function ($redis) {
        // Execute a simple SET/GET round-trip.
        $redis->execute('SET', 'torque-test:pool-test', 'hello');

        return (string) $redis->execute('GET', 'torque-test:pool-test');
    });

    expect($result)->toBe('hello');

    // Clean up.
    $this->pool->use(fn ($redis) => $redis->execute('DEL', 'torque-test:pool-test'));
});

it('use() releases even when callback throws', function () {
    try {
        $this->pool->use(function () {
            throw new RuntimeException('deliberate failure');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('deliberate failure');
    }

    // Pool should still be usable after the failed callback.
    $conn = $this->pool->checkout();
    expect($conn)->toBeInstanceOf(PooledConnection::class);

    $conn->release();
});

it('respects pool size limit', function () {
    $pool = new RedisPool(
        uri: 'redis://127.0.0.1:6379/15',
        size: 2,
    );

    $conn1 = $pool->checkout();
    $conn2 = $pool->checkout();

    $acquired = false;

    // Third checkout should suspend because only 2 slots exist.
    $future = async(function () use ($pool, &$acquired) {
        $conn3 = $pool->checkout();
        $acquired = true;
        $conn3->release();
    });

    // Give the event loop a tick — the third checkout should still be waiting.
    \Fledge\Async\delay(0.05);
    expect($acquired)->toBeFalse();

    // Release one slot to unblock the waiting fiber.
    $conn1->release();
    $future->await();

    expect($acquired)->toBeTrue();

    $conn2->release();
});
