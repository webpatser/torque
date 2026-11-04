<?php

declare(strict_types=1);

namespace Webpatser\Torque\Worker;

use Illuminate\Queue\Worker;

/**
 * Read access to the callbacks registered with `Queue::popUsing()`.
 *
 * The stock worker keeps them in a protected static property, which this
 * subclass can reach. It is never instantiated and adds no state of its own,
 * so it reads the very array `Worker::popUsing()` writes to.
 *
 * @internal
 */
final class PopCallbacks extends Worker
{
    /**
     * The callback registered for a worker name, called by stock with
     * `($popJobCallback, $queue)`.
     */
    public static function for(string $workerName): ?callable
    {
        return self::$popCallbacks[$workerName] ?? null;
    }

    // Never built: the stock constructor needs a whole worker's dependencies.
    private function __construct() {}
}
