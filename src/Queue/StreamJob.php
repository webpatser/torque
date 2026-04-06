<?php

declare(strict_types=1);

namespace Webpatser\Torque\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * Represents a single job read from a Redis Stream via XREADGROUP.
 */
class StreamJob extends Job implements JobContract
{
    /**
     * The decoded payload, cached to avoid repeated json_decode calls.
     *
     * @var array<string, mixed>
     */
    private readonly array $decoded;

    public function __construct(
        Container $container,
        public private(set) StreamQueue $streamQueue,
        public private(set) string $rawBody,
        public private(set) string $messageId,
        string $connectionName,
        string $queue,
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->decoded = $this->payload();
    }

    /**
     * Get the job identifier.
     *
     * Prefers the UUID embedded in the payload (set by Laravel's queue
     * serializer), falling back to the Redis Stream message ID.
     */
    #[\Override]
    public function getJobId(): string
    {
        return $this->decoded['uuid'] ?? $this->messageId;
    }

    /**
     * Get the raw body string for the job.
     */
    #[\Override]
    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * The payload stores a zero-based attempt counter that is incremented on
     * each release. We add one because the current execution counts as an
     * attempt.
     */
    #[\Override]
    public function attempts(): int
    {
        return ($this->decoded['attempts'] ?? 0) + 1;
    }

    /**
     * Delete the job from the queue.
     *
     * Marks the job as deleted in the base class, then acknowledges and
     * removes the message from the Redis Stream so it never gets redelivered.
     */
    #[\Override]
    public function delete(): void
    {
        parent::delete();

        $this->streamQueue->deleteAndAcknowledge($this->queue, $this->messageId);
    }

    /**
     * Release the job back onto the queue after an optional delay.
     *
     * The base class fires the "released" event. The StreamQueue handles
     * ACKing the original message and re-enqueuing with an incremented
     * attempt counter.
     *
     * @param  int  $delay  Seconds to delay before the job becomes available again.
     */
    #[\Override]
    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->streamQueue->release($this->queue, $this, (int) $delay);
    }

    /**
     * Get the Redis Stream message ID.
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }
}
