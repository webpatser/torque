<?php

declare(strict_types=1);

namespace Webpatser\Torque\Events;

/**
 * Dispatched when a job has exhausted all retries and is moved to the dead-letter stream.
 *
 * Listeners can use this event to send notifications, log to external services,
 * or trigger any custom failure-handling logic.
 */
final class JobPermanentlyFailed
{
    public function __construct(
        public readonly string $jobName,
        public readonly string $queue,
        public readonly string $payload,
        public readonly string $exceptionClass,
        public readonly string $exceptionMessage,
        public readonly string $failedAt,
    ) {}
}
