<?php

declare(strict_types=1);

use Webpatser\Torque\Pool\MysqlPool;

it('accepts a DSN and exposes it via asymmetric visibility', function () {
    try {
        $pool = new MysqlPool(
            dsn: 'host=localhost user=root password= db=test',
            maxConnections: 5,
        );
    } catch (\Throwable) {
        $this->markTestSkipped('MySQL is not available or DSN is invalid');
    }

    expect($pool->dsn)->toBe('host=localhost user=root password= db=test');
    expect($pool->maxConnections)->toBe(5);

    $pool->close();
});

it('exposes maxConnections via asymmetric visibility', function () {
    try {
        $pool = new MysqlPool(
            dsn: 'host=localhost user=root password= db=test',
        );
    } catch (\Throwable) {
        $this->markTestSkipped('MySQL is not available or DSN is invalid');
    }

    expect($pool->maxConnections)->toBe(20);

    $pool->close();
});

it('passes callback to the inner pool via use()', function () {
    try {
        $pool = new MysqlPool(
            dsn: 'host=localhost user=root password= db=test',
        );
    } catch (\Throwable) {
        $this->markTestSkipped('MySQL is not available or DSN is invalid');
    }

    $result = $pool->use(fn ($inner) => $inner instanceof \Amp\Mysql\MysqlConnectionPool);

    expect($result)->toBeTrue();

    $pool->close();
});
