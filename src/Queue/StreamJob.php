<?php

declare(strict_types=1);

namespace Webpatser\Torque\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\TimeoutExceededException;
use Webpatser\Torque\Support\Narrow;

/**
 * Represents a single job read from a Redis Stream via XREADGROUP.
 *
 * @internal Not part of the public API.
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

    /** Set when the attempt timed out: its outcome is no longer up to the job. */
    private bool $abandoned = false;

    /**
     * The one Fiber that may still settle an abandoned attempt, until it is done.
     *
     * @var \Fiber<mixed, mixed, mixed, mixed>|null
     */
    private ?\Fiber $settler = null;

    /** @var (\Closure(): int)|null */
    private ?\Closure $attemptsInFlight = null;

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
        return Narrow::string($this->decoded['uuid'] ?? null, $this->messageId);
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
        return Narrow::int($this->decoded['attempts'] ?? null) + 1;
    }

    /**
     * Get the number of attempts that count against the job's tries: every attempt
     * except those the job released itself out of.
     */
    public function triesUsed(): int
    {
        return $this->attempts() - $this->releases();
    }

    /**
     * Get the number of times the job released itself.
     *
     * Each release raises `attempts` too, so fewer attempts than releases means
     * something outside Torque reset the attempts (stock `queue:retry` does): this
     * is a new job, and the counters it carries belong to the old one.
     */
    public function releases(): int
    {
        $releases = Narrow::int($this->decoded['torque_releases'] ?? null);

        return Narrow::int($this->decoded['attempts'] ?? null) < $releases ? 0 : $releases;
    }

    /**
     * Get the timestamp the job's self-releases must end at, null when it has none.
     */
    public function releaseDeadline(): ?int
    {
        $deadline = $this->decoded['torque_release_deadline'] ?? null;

        return $deadline === null || $this->releases() === 0 ? null : Narrow::int($deadline);
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
        if ($this->outcomeIsNotOurs()) {
            return;
        }

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
        if ($this->outcomeIsNotOurs()) {
            return;
        }

        parent::release($delay);

        $this->streamQueue->release($this->queue, $this, (int) $delay);

        $this->releaseSucceeded = true;
    }

    /**
     * Put the job back on the queue for another try after a failed attempt.
     *
     * For the worker: unlike release() this is not the job releasing itself, so it
     * counts as a try and leaves the release counters alone.
     *
     * @param  int  $delay  Seconds to delay before the job becomes available again.
     */
    public function retryAfterFailure(int $delay = 0): void
    {
        if ($this->outcomeIsNotOurs()) {
            return;
        }

        parent::release($delay);

        $this->streamQueue->retryAfterFailure($this->queue, $this, $delay);

        $this->releaseSucceeded = true;
    }

    /**
     * Whether the job was released. To an abandoned job's own Fiber it always
     * looks released, so CallQueuedHandler finishing late neither starts the next
     * job in a chain, nor records a batch success, nor gives up a unique lock.
     */
    #[\Override]
    public function isReleased(): bool
    {
        return $this->released || $this->outcomeIsNotOurs();
    }

    /**
     * On a timeout the base class rolls the failed-jobs connection, and for a
     * Batchable job the batch connection, back to level 0, so the failure can be
     * recorded outside the transaction the job hung in. In a process running other
     * attempts that connection is theirs too: a transaction of theirs would lose
     * its writes so far, autocommit the rest and report success. So only the sole
     * attempt gets it. The others run the rest of the base class's fail() as is.
     */
    #[\Override]
    public function fail($e = null): void
    {
        if ($this->outcomeIsNotOurs()) {
            return;
        }

        if (! $e instanceof TimeoutExceededException || $this->isSoleAttempt()) {
            parent::fail($e);

            return;
        }

        $this->markAsFailed();

        if ($this->isDeleted()) {
            return;
        }

        $this->warnAboutOpenTransaction();

        try {
            $this->delete();

            $this->failed($e);
        } finally {
            $this->container->make(Dispatcher::class)->dispatch(new JobFailed($this->connectionName, $this, $e));
        }
    }

    /**
     * With the rollback skipped, the failure record joins any transaction open on
     * the failed-jobs connection, and if that is the one the job hung in, it never
     * commits. Nothing here can save the record without hurting the other attempts,
     * so an operator at least gets to know. A warning must not stop the job failing.
     */
    private function warnAboutOpenTransaction(): void
    {
        try {
            $database = Narrow::nullableString($this->container->make('config')->get('queue.failed.database'));
            $connection = $this->container->make('db')->connection($database);

            if ($connection->transactionLevel() > 0) {
                fwrite(STDERR, "[torque:worker] WARN job {$this->messageId} timed out while connection {$connection->getName()} still has a transaction open; its failed_jobs row and batch counts are lost unless that transaction commits. Its dead-letter entry survives.\n");
            }
        } catch (\Throwable) {
            // No database to ask, so nothing to warn about.
        }
    }

    #[\Override]
    public function markAsFailed(): void
    {
        if ($this->outcomeIsNotOurs()) {
            return;
        }

        parent::markAsFailed();
    }

    /**
     * Take the attempt away from the job, because it timed out. From here on only
     * the calling Fiber can delete, release or fail it, until abandonmentSettled().
     * The job's own Fiber is not stopped: throwing into it could return a
     * connection to the pool in the middle of a reply. It runs on, or never
     * resumes, and whatever it does to this object changes nothing.
     *
     * @param  (\Closure(): int)|null  $attemptsInFlight  Counts the worker's attempts in flight, this one included. Asked when the job fails, as others may have ended by then.
     */
    public function abandon(?\Closure $attemptsInFlight = null): void
    {
        $this->abandoned = true;
        $this->settler = \Fiber::getCurrent();
        $this->attemptsInFlight = $attemptsInFlight;
    }

    /**
     * Let this Fiber settle the abandoned attempt instead, so the worker can run a
     * callback it will not wait for forever: once the worker takes the attempt back,
     * whatever that callback still does changes nothing.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>|null  $fiber
     */
    public function settleFrom(?\Fiber $fiber): void
    {
        $this->settler = $fiber;
    }

    /**
     * The worker has settled the attempt, so nobody can change its outcome any more.
     */
    public function abandonmentSettled(): void
    {
        $this->settler = null;
    }

    public function isAbandoned(): bool
    {
        return $this->abandoned;
    }

    private function outcomeIsNotOurs(): bool
    {
        return $this->abandoned && \Fiber::getCurrent() !== $this->settler;
    }

    private function isSoleAttempt(): bool
    {
        return $this->attemptsInFlight === null || ($this->attemptsInFlight)() <= 1;
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
        return Narrow::nullableInt($this->decoded['maxExceptions'] ?? null);
    }

    /**
     * Get the number of attempts the job declares, from the cached payload.
     */
    #[\Override]
    public function maxTries(): ?int
    {
        return Narrow::nullableInt($this->decoded['maxTries'] ?? null);
    }

    /**
     * Get the backoff the job declares, from the cached payload.
     *
     * Unlike Laravel's base implementation this does not fall back to the
     * payload's `delay`: a job dispatched with a delay has declared no backoff,
     * and its retries must not wait that delay again.
     *
     * The shape stays what Laravel's stock worker expects, since it runs
     * `explode(',', $job->backoff())` on it: Laravel writes "1,5,10" for
     * [1, 5, 10], an int stays an int, and an array is joined the same way.
     */
    #[\Override]
    public function backoff(): int|string|null
    {
        $backoff = $this->decoded['backoff'] ?? null;

        if (is_int($backoff)) {
            return $backoff;
        }

        if (is_array($backoff)) {
            return implode(',', array_map(static fn (mixed $seconds): string => Narrow::string($seconds), $backoff));
        }

        return Narrow::nullableString($backoff);
    }

    /**
     * Get the timestamp the job declares retries end at, from the cached payload.
     */
    #[\Override]
    public function retryUntil(): ?int
    {
        return Narrow::nullableInt($this->decoded['retryUntil'] ?? null);
    }

    /**
     * Get the seconds the job declares it may run, from the cached payload.
     */
    #[\Override]
    public function timeout(): ?int
    {
        return Narrow::nullableInt($this->decoded['timeout'] ?? null);
    }

    /**
     * Whether the job declares that a timeout fails it, from the cached payload.
     */
    #[\Override]
    public function shouldFailOnTimeout(): bool
    {
        return Narrow::bool($this->decoded['failOnTimeout'] ?? null);
    }

    /**
     * Whether the job opted into counting worker crashes as exceptions
     * (`#[CountCrashesAsExceptions]`, laravel/framework 13.34.0).
     */
    public function countsCrashesAsExceptions(): bool
    {
        return Narrow::bool($this->decoded['countCrashesAsExceptions'] ?? null);
    }
}
