<?php

declare(strict_types=1);

use Webpatser\Torque\Pool\ConnectionPool;
use Webpatser\Torque\Pool\PooledConnection;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

it('creates connections lazily via factory', function () {
    $created = 0;
    $pool = new ConnectionPool(
        factory: function () use (&$created) {
            $created++;
            return "connection-{$created}";
        },
        maxSize: 3,
    );

    expect($pool->size)->toBe(0);

    $conn = $pool->checkout();
    expect($pool->size)->toBe(1);
    expect($conn->raw)->toBe('connection-1');

    $conn->release();
});

it('reuses idle connections', function () {
    $created = 0;
    $pool = new ConnectionPool(
        factory: function () use (&$created) {
            $created++;
            return "connection-{$created}";
        },
        maxSize: 3,
    );

    $conn1 = $pool->checkout();
    expect($conn1->raw)->toBe('connection-1');
    $conn1->release();

    // Should reuse the returned connection, not create a new one.
    $conn2 = $pool->checkout();
    expect($conn2->raw)->toBe('connection-1');
    expect($pool->size)->toBe(1);

    $conn2->release();
});

it('tracks idle count correctly', function () {
    $pool = new ConnectionPool(
        factory: fn () => new stdClass(),
        maxSize: 5,
    );

    expect($pool->idleCount)->toBe(0);

    $conn = $pool->checkout();
    expect($pool->idleCount)->toBe(0);

    $conn->release();
    expect($pool->idleCount)->toBe(1);
});

it('exposes max size via property', function () {
    $pool = new ConnectionPool(fn () => null, maxSize: 7);

    expect($pool->maxSize)->toBe(7);
});

it('returns PooledConnection instances from checkout', function () {
    $pool = new ConnectionPool(fn () => 'conn', maxSize: 1);

    $conn = $pool->checkout();
    expect($conn)->toBeInstanceOf(PooledConnection::class);

    $conn->release();
});

it('allows multiple release calls without error', function () {
    $pool = new ConnectionPool(fn () => 'conn', maxSize: 1);

    $conn = $pool->checkout();
    $conn->release();
    $conn->release(); // Should be a no-op.

    expect($pool->idleCount)->toBe(1); // Not 2.
});

it('suspends fibers when pool is exhausted', function () {
    $pool = new ConnectionPool(fn () => new stdClass(), maxSize: 1);

    $conn1 = $pool->checkout();
    $order = [];

    // This fiber will be suspended because the pool is exhausted.
    $future = async(function () use ($pool, &$order) {
        $order[] = 'waiting';
        $conn2 = $pool->checkout();
        $order[] = 'acquired';
        $conn2->release();
    });

    // Let the event loop tick so the async fiber starts.
    delay(0.01);
    $order[] = 'releasing';
    $conn1->release();

    // Let the suspended fiber proceed.
    $future->await();

    expect($order)->toBe(['waiting', 'releasing', 'acquired']);
});
