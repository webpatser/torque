<?php

declare(strict_types=1);

namespace Webpatser\Torque\Events;

/**
 * Dispatched when a stream's circuit breaker closes and pickup resumes.
 *
 * `$reason` is `probe` when a half-open probe job succeeded, or `manual` when
 * an operator forced it closed via `torque:pause continue` or `queue:resume`.
 */
final class QueueCircuitClosed
{
    public function __construct(
        public readonly string $queue,
        public readonly string $reason,
    ) {}
}
