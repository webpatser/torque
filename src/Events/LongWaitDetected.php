<?php

declare(strict_types=1);

namespace Webpatser\Torque\Events;

/**
 * Dispatched by the master when the oldest waiting job of a queue has waited
 * longer than its `waits` threshold. Once per episode, see LongWaitGate.
 *
 * Listeners do not run in the master. They run in a short-lived child process
 * (`torque:long-wait-alert`), a fresh boot of the app, so register them in a service
 * provider. A listener that hangs delays nothing but its own alert: it has a share
 * of the child's limit, 60 seconds by default, and is then ended. State set up in the
 * master at runtime is not there. A listener that throws is logged, the notifications
 * still go out, and the alert is retried after five minutes instead of fifteen while
 * the queue stays over, so a listener may run again for the same episode.
 */
final class LongWaitDetected
{
    public function __construct(
        public readonly string $queue,
        public readonly int $seconds,
        public readonly int $threshold,
    ) {}
}
