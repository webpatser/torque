<?php

declare(strict_types=1);

namespace Webpatser\Torque\Pool;

use Fledge\Async\Sync\LocalSemaphore;
use Fledge\Async\Sync\Semaphore;
use SplQueue;

/**
 * Generic async-safe connection pool backed by a LocalSemaphore.
 *
 * When all connections are checked out, `checkout()` suspends the calling
 * Fiber until a slot is freed — no busy-waiting, no exceptions.
 *
 * The factory closure is called lazily: connections are only created when
 * the pool has no idle connections and hasn't hit maxSize yet.
 */
final class ConnectionPool
{
    private SplQueue $idle;

    private int $created = 0;

    private Semaphore $semaphore;

    /** Total number of connections created by this pool. */
    public int $size {
        get => $this->created;
    }

    /** Number of connections currently sitting idle in the pool. */
    public int $idleCount {
        get => $this->idle->count();
    }

    /**
     * @param \Closure(): mixed $factory  Creates a new connection when the pool needs to grow.
     * @param int                $maxSize  Maximum number of concurrent connections.
     */
    public function __construct(
        private readonly \Closure $factory,
        public private(set) int $maxSize = 10,
    ) {
        $this->idle = new SplQueue();
        $this->semaphore = new LocalSemaphore($maxSize);
    }

    /**
     * Check out a connection from the pool.
     *
     * If all slots are in use, this suspends the current Fiber until one
     * is returned. The caller MUST release the PooledConnection when done
     * (or let its destructor handle it).
     */
    #[\NoDiscard]
    public function checkout(): PooledConnection
    {
        $lock = $this->semaphore->acquire();

        $connection = !$this->idle->isEmpty()
            ? $this->idle->dequeue()
            : $this->createConnection();

        return new PooledConnection($connection, $lock, $this);
    }

    /**
     * Return a raw connection to the idle queue.
     *
     * Called by PooledConnection::release() — not intended for direct use.
     *
     * @internal
     */
    public function release(mixed $connection): void
    {
        $this->idle->enqueue($connection);
    }

    /**
     * Create a new connection via the factory.
     */
    private function createConnection(): mixed
    {
        $this->created++;

        return ($this->factory)();
    }
}
