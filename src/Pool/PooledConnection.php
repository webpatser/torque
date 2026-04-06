<?php

declare(strict_types=1);

namespace Webpatser\Torque\Pool;

use Amp\Sync\Lock;

/**
 * Wrapper around a checked-out connection that auto-releases on destruct.
 *
 * Not readonly because the lock must be nulled after release to prevent
 * double-release scenarios.
 */
final class PooledConnection
{
    /** Ergonomic read-only access to the underlying connection. */
    public mixed $raw {
        get => $this->connection;
    }

    public function __construct(
        private mixed $connection,
        private ?Lock $lock,
        private ConnectionPool $pool,
    ) {}

    /**
     * Return the connection to the pool and release the semaphore slot.
     *
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function release(): void
    {
        if ($this->lock === null) {
            return;
        }

        $this->pool->release($this->connection);
        $this->lock->release();
        $this->lock = null;
    }

    /**
     * Safety net: release the connection if the wrapper is garbage-collected
     * without an explicit release() call.
     */
    public function __destruct()
    {
        $this->release();
    }
}
