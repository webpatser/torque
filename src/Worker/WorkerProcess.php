<?php

declare(strict_types=1);

namespace Webpatser\Torque\Worker;

use Fledge\Async\Redis\RedisClient;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Revolt\EventLoop;
use Webpatser\Torque\Metrics\MetricsCollector;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Pool\RedisPool;
use Webpatser\Torque\Events\JobPermanentlyFailed;
use Webpatser\Torque\Job\CoroutineContext;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;

use function Fledge\Async\async;
use function Fledge\Async\Redis\createRedisClient;

/**
 * The heart of Torque. Runs inside a forked child process with a Revolt event loop.
 *
 * Each worker reads jobs from one or more Redis Streams via XREADGROUP, then
 * dispatches them concurrently using Fibers gated by a semaphore. A dedicated
 * (non-pooled) Redis connection is used for blocking XREADGROUP reads so pool
 * connections are never tied up during BLOCK waits.
 */
final class WorkerProcess
{
    /**
     * Lua script for atomic delayed job migration.
     *
     * Atomically reads matured entries from the delayed sorted set, removes them,
     * and adds them to the stream. This prevents race conditions where multiple
     * workers migrate the same delayed jobs simultaneously.
     *
     * KEYS[1] = delayed sorted set key
     * KEYS[2] = stream key
     * ARGV[1] = current timestamp
     */
    private const LUA_MIGRATE_DELAYED = <<<'LUA'
local entries = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 100)
if #entries == 0 then return 0 end
for i, payload in ipairs(entries) do
    redis.call('ZREM', KEYS[1], payload)
    redis.call('XADD', KEYS[2], '*', 'payload', payload)
end
return #entries
LUA;

    public private(set) bool $isRunning = false;

    public private(set) int $jobsProcessed = 0;

    private bool $stopRequested = false;

    /** Whether a graceful stop has been requested (via SIGTERM/SIGINT or max limits). */
    public bool $shouldStop {
        get => $this->stopRequested;
    }

    /** Limits — set once in run(), checked by timers to know when to cancel. */
    private int $maxJobs = 10_000;

    private int $maxLifetime = 3600;

    private int $startTime = 0;

    /** @var array<string, mixed> */
    private readonly array $config;

    private readonly string $consumerId;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->consumerId = gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Main entry point — blocks until the worker is stopped or limits are reached.
     *
     * Creates the Revolt event loop, installs signal handlers, and registers
     * the polling and delayed-job-migration timers.
     */
    public function run(): void
    {
        CoroutineContext::init();

        $this->isRunning = true;

        $redisUri = $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
        $prefix = $this->config['redis']['prefix'] ?? 'torque:';
        $consumerGroup = $this->config['consumer_group'] ?? 'torque';
        $concurrency = (int) ($this->config['coroutines_per_worker'] ?? 50);
        $pollInterval = ((int) ($this->config['block_for'] ?? 2000)) / 1000.0;
        $maxJobs = (int) ($this->config['max_jobs_per_worker'] ?? 10_000);
        $maxLifetime = (int) ($this->config['max_worker_lifetime'] ?? 3600);
        $poolSize = (int) ($this->config['pools']['redis']['size'] ?? 30);
        /** @var string[] $queues */
        $queues = (array) ($this->config['queues'] ?? ['default']);
        $connectionName = 'torque';
        $streams = $this->config['streams'] ?? [];

        // Pool for job operations (delete, release, etc.)
        $redisPool = new RedisPool($redisUri, size: $poolSize);

        // Each Fiber gets its own reader Redis connection since XREADGROUP BLOCK
        // holds the connection for the duration of the wait.
        // We create them lazily inside the Fiber loop instead of sharing one.

        $metrics = new MetricsCollector(totalSlots: $concurrency);
        $metricsPublisher = new MetricsPublisher(redisUri: $redisUri, prefix: $prefix);

        $cluster = (bool) ($this->config['redis']['cluster'] ?? false);

        $streamQueue = new StreamQueue(
            redisUri: $redisUri,
            default: $queues[0] ?? 'default',
            retryAfter: (int) ($streams[$queues[0]]['retry_after'] ?? 90),
            blockFor: 0,
            prefix: $prefix,
            consumerGroup: $consumerGroup,
            cluster: $cluster,
        );

        $events = app(Dispatcher::class);

        $deadLetterConfig = $this->config['dead_letter'] ?? [];
        $deadLetterHandler = new DeadLetterHandler(
            redisUri: $redisUri,
            ttl: (int) ($deadLetterConfig['ttl'] ?? 604800),
            prefix: $prefix,
        );

        // Build cluster-safe stream key (matches StreamQueue::getStreamKey).
        $buildStreamKey = static function (string $queue) use ($prefix, $cluster): string {
            if ($cluster && !str_contains($queue, '{')) {
                $queue = '{' . $queue . '}';
            }
            return $prefix . $queue;
        };

        // Ensure consumer groups exist for all configured queues.
        foreach ($queues as $queue) {
            $streamQueue->ensureConsumerGroup($buildStreamKey($queue), $consumerGroup);
        }

        // Set error handler so uncaught Fiber exceptions are logged instead of exit(255).
        EventLoop::setErrorHandler(function (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] Uncaught in event loop: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        });

        fwrite(STDERR, "[torque:worker] Setup complete, entering event loop\n");

        // Install signal handlers for graceful shutdown.
        EventLoop::onSignal(SIGTERM, function () {
            $this->stopRequested = true;
        });
        EventLoop::onSignal(SIGINT, function () {
            $this->stopRequested = true;
        });

        $this->maxJobs = $maxJobs;
        $this->maxLifetime = $maxLifetime;
        $this->startTime = time();
        $startTime = $this->startTime;

        // Shared pause flag updated by a timer instead of per-Fiber EXISTS calls.
        $pauseState = new \stdClass();
        $pauseState->paused = false;

        EventLoop::repeat(2.0, function (string $id) use ($redisPool, $prefix, $pauseState) {
            if ($this->hasReachedLimits()) {
                EventLoop::cancel($id);
                return;
            }
            $redisPool->use(function (mixed $redis) use ($prefix, $pauseState) {
                $pauseState->paused = (bool) $redis->execute('EXISTS', $prefix . 'paused');
            });
        });

        // Spawn N reader Fibers (one per concurrency slot).
        // Each Fiber loops: poll (non-blocking) -> process -> yield -> repeat.
        // Non-blocking XREADGROUP avoids reliance on the async client's BLOCK
        // notification chain, which stalls with many simultaneous connections.
        fwrite(STDERR, "[torque:worker] Starting {$concurrency} reader Fibers\n");

        for ($i = 0; $i < $concurrency; $i++) {
            $fiberIndex = $i;
            (void) async(function () use (
                $fiberIndex,
                $redisUri,
                $queues,
                $prefix,
                $consumerGroup,
                $pollInterval,
                $maxJobs,
                $maxLifetime,
                $startTime,
                $streamQueue,
                $events,
                $connectionName,
                $streams,
                $metrics,
                $deadLetterHandler,
                $pauseState,
                $concurrency,
                $buildStreamKey,
            ) {
                // Stagger Fiber startup to spread polling across time.
                \Fledge\Async\delay($fiberIndex * ($pollInterval / $concurrency));

                $fiberRedis = createRedisClient($redisUri);
                $loopCount = 0;

                while (true) {
                    if ($this->stopRequested || $this->jobsProcessed >= $maxJobs || (time() - $startTime) >= $maxLifetime) {
                        return;
                    }

                    if ($pauseState->paused) {
                        \Fledge\Async\delay(1.0);
                        continue;
                    }

                    $events->dispatch(new Looping($connectionName, $queues[0] ?? 'default'));

                    // Non-blocking poll for new messages.
                    $message = $this->readNextMessage($fiberRedis, $queues, $prefix, $consumerGroup, $buildStreamKey);

                    // Periodically re-check PEL for any orphaned messages (~every 25s).
                    $loopCount++;
                    if ($message === null && $loopCount % 50 === 0) {
                        $message = $this->readPendingMessage($fiberRedis, $queues, $prefix, $consumerGroup, $buildStreamKey);
                    }

                    // Drain pending on first iteration (crash recovery).
                    if ($message === null && $loopCount === 1) {
                        $message = $this->readPendingMessage($fiberRedis, $queues, $prefix, $consumerGroup, $buildStreamKey);
                    }

                    // Still nothing: steal from dead consumers.
                    if ($message === null) {
                        $message = $this->stealMessage($fiberRedis, $queues, $prefix, $consumerGroup, $streams, $buildStreamKey);
                    }

                    if ($message === null) {
                        // No work: yield to event loop and wait before polling again.
                        \Fledge\Async\delay($pollInterval);
                        continue;
                    }

                    $metrics->recordJobStarted();
                    $jobStartTime = hrtime(true);

                    try {
                        $this->processMessage($message, $streamQueue, $events, $connectionName, $streams, $prefix);
                        $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                        $metrics->recordJobCompleted($durationMs);
                    } catch (\Throwable $e) {
                        $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                        $metrics->recordJobFailed($durationMs);
                        $this->handleFailure($message, $e, $streamQueue, $events, $connectionName, $streams, $prefix, $deadLetterHandler);
                    } finally {
                        CoroutineContext::flush();
                        $this->jobsProcessed++;
                    }
                }
            });
        }

        // Delayed job migration timer: checks every second for matured delayed jobs.
        EventLoop::repeat(1.0, function (string $id) use ($redisPool, $queues, $buildStreamKey) {
            if ($this->hasReachedLimits()) {
                EventLoop::cancel($id);
                return;
            }

            $this->migrateDelayedJobs($redisPool, $queues, $buildStreamKey);
        });

        // Metrics publishing timer — pushes worker snapshot to Redis for the dashboard.
        $metricsInterval = (float) ($this->config['metrics']['publish_interval'] ?? 1);
        EventLoop::repeat($metricsInterval, function (string $id) use ($metricsPublisher, $metrics) {
            if ($this->hasReachedLimits()) {
                EventLoop::cancel($id);
                return;
            }

            $metricsPublisher->publishWorkerMetrics($this->consumerId, $metrics->snapshot());
        });

        try {
            EventLoop::run();
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] EventLoop crashed: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }

        // Remove this worker's metrics key from Redis so it doesn't linger as a ghost.
        try {
            $metricsPublisher->removeWorkerMetrics($this->consumerId);
        } catch (\Throwable) {
            // Best-effort cleanup — don't prevent shutdown.
        }

        $this->isRunning = false;
    }

/**
     * Parse the nested XREADGROUP response into a flat message array.
     *
     * @return array{stream: string, id: string, payload: string}|null
     */
    private function parseXreadgroupResponse(mixed $result): ?array
    {
        if ($result === null) {
            return null;
        }

        foreach ($result as $streamData) {
            if ($streamData === null) {
                continue;
            }

            $streamKey = (string) $streamData[0];
            $messages = $streamData[1] ?? [];

            if ($messages === []) {
                continue;
            }

            $message = $messages[0];
            $messageId = (string) $message[0];
            $fields = $message[1];

            $payload = null;
            for ($i = 0, $count = count($fields); $i < $count; $i += 2) {
                if ((string) $fields[$i] === 'payload') {
                    $payload = (string) $fields[$i + 1];
                    break;
                }
            }

            if ($payload === null) {
                continue;
            }

            return [
                'stream' => $streamKey,
                'id' => $messageId,
                'payload' => $payload,
            ];
        }

        return null;
    }

    /**
     * Read a pending message (already delivered but not ACKed) from any stream.
     *
     * Uses '0-0' as the ID to fetch messages in this consumer's PEL.
     * Non-blocking — returns null immediately when no pending messages remain.
     *
     * @param  string[]  $queues
     * @return array{stream: string, id: string, payload: string}|null
     */
    /**
     * @param  string[]  $queues
     * @param  \Closure(string): string  $buildStreamKey
     */
    private function readPendingMessage(
        RedisClient $readerRedis,
        array $queues,
        string $prefix,
        string $consumerGroup,
        \Closure $buildStreamKey,
    ): ?array {
        $args = ['GROUP', $consumerGroup, $this->consumerId, 'COUNT', '1', 'STREAMS'];

        foreach ($queues as $queue) {
            $args[] = $buildStreamKey($queue);
        }

        foreach ($queues as $queue) {
            $args[] = '0-0';
        }

        $result = $readerRedis->execute('XREADGROUP', ...$args);

        return $this->parseXreadgroupResponse($result);
    }

    /**
     * Steal one stale message from another consumer via XAUTOCLAIM.
     *
     * Only claims messages that have been idle longer than the stream's
     * retry_after setting. Returns the claimed message or null.
     *
     * @param  string[]  $queues
     * @param  array<string, array<string, mixed>>  $streams
     * @return array{stream: string, id: string, payload: string}|null
     */
    /**
     * @param  string[]  $queues
     * @param  array<string, array<string, mixed>>  $streams
     * @param  \Closure(string): string  $buildStreamKey
     */
    private function stealMessage(
        RedisClient $readerRedis,
        array $queues,
        string $prefix,
        string $consumerGroup,
        array $streams,
        \Closure $buildStreamKey,
    ): ?array {
        foreach ($queues as $queue) {
            $streamKey = $buildStreamKey($queue);
            // Use the stream's retry_after as the idle threshold for stealing.
            // This prevents stealing from consumers that are still alive but
            // processing slow jobs (e.g. exports, slow scraping).
            $streamConfig = $streams[$queue] ?? [];
            $minIdleMs = ((int) ($streamConfig['retry_after'] ?? 60)) * 1000;

            try {
                $result = $readerRedis->execute(
                    'XAUTOCLAIM',
                    $streamKey,
                    $consumerGroup,
                    $this->consumerId,
                    (string) $minIdleMs,
                    '0-0',
                    'COUNT',
                    '1',
                );

                // XAUTOCLAIM returns [nextCursor, [[id, [fields...]], ...], [deletedIds...]]
                $messages = is_array($result) && is_array($result[1] ?? null) ? $result[1] : [];

                if ($messages === []) {
                    continue;
                }

                $message = $messages[0];
                $messageId = (string) $message[0];
                $fields = $message[1];

                $payload = null;
                for ($i = 0, $count = count($fields); $i < $count; $i += 2) {
                    if ((string) $fields[$i] === 'payload') {
                        $payload = (string) $fields[$i + 1];
                        break;
                    }
                }

                if ($payload === null) {
                    continue;
                }

                return [
                    'stream' => $streamKey,
                    'id' => $messageId,
                    'payload' => $payload,
                ];
            } catch (\Fledge\Async\Redis\RedisException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Check if the worker should stop due to any reason: signal, max jobs, or max lifetime.
     */
    private function hasReachedLimits(): bool
    {
        return $this->stopRequested
            || $this->jobsProcessed >= $this->maxJobs
            || (time() - $this->startTime) >= $this->maxLifetime;
    }

    /**
     * Poll for the next message from any configured stream using XREADGROUP.
     *
     * Non-blocking: returns immediately with a message or null. The caller
     * is responsible for yielding (delay) when no work is available.
     *
     * @param  string[]  $queues
     * @param  \Closure(string): string  $buildStreamKey
     * @return array{stream: string, id: string, payload: string}|null
     */
    private function readNextMessage(
        RedisClient $readerRedis,
        array $queues,
        string $prefix,
        string $consumerGroup,
        \Closure $buildStreamKey,
    ): ?array {
        $args = ['GROUP', $consumerGroup, $this->consumerId, 'COUNT', '1', 'STREAMS'];

        foreach ($queues as $queue) {
            $args[] = $buildStreamKey($queue);
        }

        foreach ($queues as $queue) {
            $args[] = '>';
        }

        $result = $readerRedis->execute('XREADGROUP', ...$args);

        return $this->parseXreadgroupResponse($result);
    }

    /**
     * Process a single message: validate, create a StreamJob, fire events, execute.
     *
     * @param  array{stream: string, id: string, payload: string}  $message
     * @param  array<string, array<string, mixed>>  $streams
     */
    private function processMessage(
        array $message,
        StreamQueue $streamQueue,
        Dispatcher $events,
        string $connectionName,
        array $streams,
        string $prefix,
    ): void {
        if (!json_validate($message['payload'])) {
            // Corrupt payload — acknowledge and discard.
            $queueName = $this->resolveQueueName($message['stream'], $prefix);
            $streamQueue->deleteAndAcknowledge($queueName, $message['id']);
            return;
        }

        $queueName = $this->resolveQueueName($message['stream'], $prefix);

        $job = new StreamJob(
            container: app(),
            streamQueue: $streamQueue,
            rawBody: $message['payload'],
            messageId: $message['id'],
            connectionName: $connectionName,
            queue: $queueName,
        );

        $events->dispatch(new JobProcessing($connectionName, $job));

        $job->fire();

        // If the job handler did not explicitly delete, release, or fail the job,
        // treat it as successfully completed.
        if (!$job->isDeleted() && !$job->isReleased() && !$job->hasFailed()) {
            $job->delete();
        }

        $events->dispatch(new JobProcessed($connectionName, $job));
    }

    /**
     * Handle a job that threw an exception during processing.
     *
     * Releases the job with exponential backoff if attempts remain, otherwise
     * marks it as permanently failed.
     *
     * @param  array{stream: string, id: string, payload: string}  $message
     * @param  array<string, array<string, mixed>>  $streams
     */
    private function handleFailure(
        array $message,
        \Throwable $exception,
        StreamQueue $streamQueue,
        Dispatcher $events,
        string $connectionName,
        array $streams,
        string $prefix,
        DeadLetterHandler $deadLetterHandler,
    ): void {
        $queueName = $this->resolveQueueName($message['stream'], $prefix);

        $job = new StreamJob(
            container: app(),
            streamQueue: $streamQueue,
            rawBody: $message['payload'],
            messageId: $message['id'],
            connectionName: $connectionName,
            queue: $queueName,
        );

        $events->dispatch(new JobExceptionOccurred($connectionName, $job, $exception));

        $streamConfig = $streams[$queueName] ?? [];
        $maxRetries = (int) ($streamConfig['max_retries'] ?? 3);

        if ($job->attempts() < $maxRetries) {
            // Exponential backoff: 2^attempts seconds (2, 4, 8, 16, ...).
            $backoffSeconds = (int) (2 ** $job->attempts());
            $job->release($backoffSeconds);
        } else {
            // Write to dead-letter stream FIRST — $job->fail() may throw
            // (e.g. job's failed() callback, DB write for failed_jobs table,
            // or event listener), and we must not lose the failed job.
            $deadLetterHandler->handle(
                queue: $queueName,
                payload: $message['payload'],
                messageId: $message['id'],
                exception: $exception,
            );

            // Mark as failed via Laravel's base Job — this ACKs/DELs the
            // stream message and calls the job's failed() callback.
            try {
                $job->fail($exception);
            } catch (\Throwable) {
                // fail() already deleted the message from the stream.
                // If it throws after that (e.g. failed() callback or DB),
                // the job is already in dead-letter — safe to swallow.
            }

            $events->dispatch(new JobFailed($connectionName, $job, $exception));

            // Fire a domain event so users can hook in custom notification logic.
            $decoded = json_decode($message['payload'], true);
            $failedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

            $events->dispatch(new JobPermanentlyFailed(
                jobName: $decoded['displayName'] ?? $decoded['job'] ?? 'Unknown',
                queue: $queueName,
                payload: $message['payload'],
                exceptionClass: $exception::class,
                exceptionMessage: $exception->getMessage(),
                failedAt: $failedAt,
            ));
        }
    }

    /**
     * Migrate delayed jobs whose scheduled time has arrived.
     *
     * Reads matured entries from the sorted set (score <= now) and moves them
     * into the main stream via XADD.
     *
     * @param  string[]  $queues
     */
    /**
     * @param  string[]  $queues
     * @param  \Closure(string): string  $buildStreamKey
     */
    private function migrateDelayedJobs(RedisPool $redisPool, array $queues, \Closure $buildStreamKey): void
    {
        $redisPool->use(function (mixed $redis) use ($queues, $buildStreamKey) {
            $now = (string) time();

            foreach ($queues as $queue) {
                $streamKey = $buildStreamKey($queue);
                $delayedKey = $streamKey . ':delayed';

                /** @var int|null $migrated */
                $migrated = $redis->execute(
                    'EVAL',
                    self::LUA_MIGRATE_DELAYED,
                    '2',
                    $delayedKey,
                    $streamKey,
                    $now,
                );

                if (is_int($migrated) && $migrated > 0) {
                    fwrite(STDERR, "[torque:worker] Migrated {$migrated} delayed jobs from {$queue}\n");
                }
            }
        });
    }

    /**
     * Resolve the queue name by stripping the prefix from a stream key.
     */
    private function resolveQueueName(string $streamKey, string $prefix): string
    {
        if (str_starts_with($streamKey, $prefix)) {
            return substr($streamKey, strlen($prefix));
        }

        return $streamKey;
    }
}
