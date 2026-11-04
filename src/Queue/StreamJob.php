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

    private bool $releaseSucceeded = false;

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
     * Decode the job payload.
     *
     * Overrides Laravel's base implementation (which assumes JSON) so igbinary
     * payloads decoded by {@see StreamQueue::decodePayload} flow through the
     * standard CallQueuedHandler pipeline.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function payload(): array
    {
        return StreamQueue::decodePayload($this->rawBody);
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
     * The base class only sets the released flag, also when the write below
     * then throws. The StreamQueue handles ACKing the original message and
     * re-enqueuing with an incremented attempt counter.
     *
     * @param  int  $delay  Seconds to delay before the job becomes available again.
     */
    #[\Override]
    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->streamQueue->release($this->queue, $this, (int) $delay);

        $this->releaseSucceeded = true;
    }

    /**
     * Whether a release actually reached the queue. isReleased() also says yes
     * after one that threw, when the entry is still in the PEL for reclaim.
     */
    public function releaseSucceeded(): bool
    {
        return $this->releaseSucceeded;
    }

    /**
     * Get the Redis Stream message ID.
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /**
     * Get the UUID from the payload decoded at construction.
     *
     * Same result as Laravel's base implementation, without decoding the
     * raw body a second time.
     */
    #[\Override]
    public function uuid(): ?string
    {
        $uuid = $this->decoded['uuid'] ?? null;

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * Get the maximum number of exceptions allowed, from the cached payload.
     */
    #[\Override]
    public function maxExceptions(): ?int
    {
        $max = $this->decoded['maxExceptions'] ?? null;

        return $max === null ? null : (int) $max;
    }

    /**
     * Get the number of attempts the job declares, from the cached payload.
     */
    #[\Override]
    public function maxTries(): ?int
    {
        $max = $this->decoded['maxTries'] ?? null;

        return $max === null ? null : (int) $max;
    }

    /**
     * Get the backoff the job declares, from the cached payload.
     *
     * Unlike Laravel's base implementation this does not fall back to the
     * payload's `delay`: a job dispatched with a delay has declared no backoff,
     * and its retries must not wait that delay again.
     *
     * @return int|int[]|string|null
     */
    #[\Override]
    public function backoff(): mixed
    {
        return $this->decoded['backoff'] ?? null;
    }

    /**
     * Get the timestamp the job declares retries end at, from the cached payload.
     */
    #[\Override]
    public function retryUntil(): ?int
    {
        $until = $this->decoded['retryUntil'] ?? null;

        return $until === null ? null : (int) $until;
    }

    /**
     * Whether the job opted into counting worker crashes as exceptions
     * (`#[CountCrashesAsExceptions]`, laravel/framework 13.34.0).
     */
    public function countsCrashesAsExceptions(): bool
    {
        return (bool) ($this->decoded['countCrashesAsExceptions'] ?? false);
    }
}
