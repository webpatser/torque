<?php

declare(strict_types=1);

namespace Webpatser\Torque\Pool;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Pre-configured connection pool for amphp/redis clients.
 *
 * Usage:
 *
 *     $pool = new RedisPool('redis://127.0.0.1:6379', size: 5);
 *
 *     // Option A: manual checkout/release
 *     $conn = $pool->checkout();
 *     $conn->raw->set('key', 'value');
 *     $conn->release();
 *
 *     // Option B: scoped one-shot (auto-releases)
 *     $value = $pool->use(fn ($redis) => $redis->get('key'));
 */
final class RedisPool
{
    private ConnectionPool $inner;

    public function __construct(
        public private(set) string $uri,
        int $size = 10,
    ) {
        $this->inner = new ConnectionPool(
            factory: fn () => createRedisClient($this->uri),
            maxSize: $size,
        );
    }

    /**
     * Check out a pooled Redis connection.
     *
     * The caller is responsible for releasing it (or letting the destructor
     * handle it).
     */
    #[\NoDiscard]
    public function checkout(): PooledConnection
    {
        return $this->inner->checkout();
    }

    /**
     * Execute a callback with a checked-out connection, then auto-release.
     *
     * Ideal for one-shot operations where you don't need to hold the
     * connection across multiple calls.
     *
     *     $pool->use(fn ($redis) => $redis->set('key', 'value'));
     *
     * @template T
     *
     * @param \Closure(mixed): T $callback  Receives the raw Redis client.
     *
     * @return T
     */
    public function use(\Closure $callback): mixed
    {
        $conn = $this->inner->checkout();

        try {
            return $callback($conn->raw);
        } finally {
            $conn->release();
        }
    }
}
