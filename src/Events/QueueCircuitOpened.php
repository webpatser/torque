<?php

declare(strict_types=1);

namespace Webpatser\Torque\Events;

/**
 * Dispatched when a stream's failure rate trips its circuit breaker.
 *
 * The stream is paused for `cooldown` seconds (workers stop picking its jobs
 * up, other streams keep running) and is then probed again. Listeners can use
 * this to page an operator: a tripped breaker almost always means a dependency
 * of that queue is down, not that the jobs are individually broken.
 */
final class QueueCircuitOpened
{
    public function __construct(
        public readonly string $queue,
        public readonly int $failures,
        public readonly int $samples,
        public readonly float $ratio,
        public readonly int $cooldown,
        public readonly int $resumesAt,
    ) {}
}
