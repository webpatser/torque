<?php

declare(strict_types=1);

use Webpatser\Torque\Pool\ConnectionPool;
use Webpatser\Torque\Pool\PooledConnection;

it('exposes the raw connection via property hook', function () {
    $pool = new ConnectionPool(fn () => 'raw-connection', maxSize: 1);

    $conn = $pool->checkout();

    expect($conn)->toBeInstanceOf(PooledConnection::class);
    expect($conn->raw)->toBe('raw-connection');

    $conn->release();
});

it('exposes complex objects via the raw property hook', function () {
    $object = new stdClass();
    $object->name = 'test';

    $pool = new ConnectionPool(fn () => $object, maxSize: 1);

    $conn = $pool->checkout();

    expect($conn->raw)->toBe($object);
    expect($conn->raw->name)->toBe('test');

    $conn->release();
});

it('returns connection to pool and releases lock on release()', function () {
    $pool = new ConnectionPool(fn () => 'conn', maxSize: 1);

    $conn = $pool->checkout();
    expect($pool->idleCount)->toBe(0);

    $conn->release();
    expect($pool->idleCount)->toBe(1);

    // The slot is free again, so a new checkout should succeed immediately.
    $conn2 = $pool->checkout();
    expect($conn2->raw)->toBe('conn');

    $conn2->release();
});

it('treats double release as a no-op', function () {
    $pool = new ConnectionPool(fn () => 'conn', maxSize: 1);

    $conn = $pool->checkout();
    $conn->release();
    $conn->release();
    $conn->release();

    // Connection should only be returned to the pool once.
    expect($pool->idleCount)->toBe(1);
});

it('calls release via __destruct as safety net', function () {
    $pool = new ConnectionPool(fn () => 'conn', maxSize: 1);

    $conn = $pool->checkout();
    expect($pool->idleCount)->toBe(0);

    // Simulate garbage collection by unsetting the variable.
    // The destructor should return the connection to the pool.
    unset($conn);

    expect($pool->idleCount)->toBe(1);

    // The semaphore slot should also be freed, allowing a new checkout.
    $conn2 = $pool->checkout();
    expect($conn2->raw)->toBe('conn');

    $conn2->release();
});

it('does not double-release when destruct follows explicit release', function () {
    $pool = new ConnectionPool(fn () => 'conn', maxSize: 1);

    $conn = $pool->checkout();
    $conn->release();

    // Destruct after explicit release should be a no-op.
    unset($conn);

    expect($pool->idleCount)->toBe(1);
});
