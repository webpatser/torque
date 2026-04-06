<?php

declare(strict_types=1);

namespace Webpatser\Torque\Worker;

use Amp\Redis\RedisClient;
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

use function Amp\async;
use function Amp\Redis\createRedisClient;

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
    public private(set) bool $isRunning = false;

    public private(set) int $jobsProcessed = 0;

    private bool $stopRequested = false;

    /** Whether a graceful stop has been requested (via SIGTERM/SIGINT or max limits). */
    public bool $shouldStop {
        get => $this->stopRequested;
    }

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
        $blockFor = (int) ($this->config['block_for'] ?? 2000);
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

        $streamQueue = new StreamQueue(
            redisUri: $redisUri,
            default: $queues[0] ?? 'default',
            retryAfter: (int) ($streams[$queues[0]]['retry_after'] ?? 90),
            blockFor: $blockFor,
            prefix: $prefix,
            consumerGroup: $consumerGroup,
        );

        $events = app(Dispatcher::class);

        $deadLetterConfig = $this->config['dead_letter'] ?? [];
        $deadLetterHandler = new DeadLetterHandler(
            redisUri: $redisUri,
            deadLetterStream: $deadLetterConfig['stream'] ?? 'torque:stream:dead-letter',
            ttl: (int) ($deadLetterConfig['ttl'] ?? 604800),
        );

        // Ensure consumer groups exist for all configured queues.
        foreach ($queues as $queue) {
            $streamKey = $prefix . $queue;
            $streamQueue->ensureConsumerGroup($streamKey, $consumerGroup);
        }

        // Claim stale messages from crashed workers via XAUTOCLAIM.
        $setupRedis = createRedisClient($redisUri);
        $this->claimStaleMessages($setupRedis, $queues, $prefix, $consumerGroup, $streams);

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

        $startTime = time();

        // Main poll loop — spawn N Fibers (one per concurrency slot).
        // Each Fiber loops: read → process → repeat. When XREADGROUP BLOCK waits,
        // the Fiber suspends and others can run. The number of Fibers IS the
        // concurrency limit — no semaphore needed.
        // Phase 1: Process pending messages in a single Fiber (recovery from crashes).
        // Runs as the first async task. Once pending recovery is complete,
        // it spawns the N reader Fibers for new messages.
        async(function () use (
            $redisUri, $queues, $prefix, $consumerGroup, $streamQueue, $events,
            $connectionName, $streams, $metrics, $deadLetterHandler, $maxJobs,
            $maxLifetime, $startTime, $blockFor, $concurrency,
        ) {
            $recoveryRedis = createRedisClient($redisUri);
            $this->processPendingMessages($recoveryRedis, $queues, $prefix, $consumerGroup, $streamQueue, $events, $connectionName, $streams, $metrics, $deadLetterHandler, $maxJobs, $maxLifetime, $startTime);
            fwrite(STDERR, "[torque:worker] Pending recovery complete, starting {$concurrency} reader Fibers\n");

            // Phase 2: Spawn N Fibers for new messages.
            for ($i = 0; $i < $concurrency; $i++) {
            async(function () use (
                $redisUri,
                $queues,
                $prefix,
                $consumerGroup,
                $blockFor,
                $maxJobs,
                $maxLifetime,
                $startTime,
                $streamQueue,
                $events,
                $connectionName,
                $streams,
                $metrics,
                $deadLetterHandler,
            ) {
                // Each Fiber gets its own dedicated Redis connection for blocking reads.
                $fiberRedis = createRedisClient($redisUri);

                // Read new messages in a loop.
                while (true) {
                    if ($this->stopRequested || $this->jobsProcessed >= $maxJobs || (time() - $startTime) >= $maxLifetime) {
                        return;
                    }

                    // Check if paused.
                    try {
                        $isPaused = $fiberRedis->execute('EXISTS', $prefix . 'paused');
                        if ($isPaused) {
                            \Amp\delay(1.0);
                            continue;
                        }
                    } catch (\Throwable) {
                        \Amp\delay(1.0);
                        continue;
                    }

                    // Fire Looping event.
                    $events->dispatch(new Looping($connectionName, $queues[0] ?? 'default'));

                    // Read next message — this blocks the Fiber (not the event loop)
                    // during the XREADGROUP BLOCK wait.
                    $message = $this->readNextMessage($fiberRedis, $queues, $prefix, $consumerGroup, $blockFor);

                    if ($message === null) {
                        continue;
                    }

                    // Process the job in this Fiber.
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
        }); // End of Phase 1+2 async wrapper.

        // Delayed job migration timer — checks every second for matured delayed jobs.
        EventLoop::repeat(1.0, function (string $id) use ($redisPool, $queues, $prefix) {
            if ($this->stopRequested) {
                EventLoop::cancel($id);
                return;
            }

            $this->migrateDelayedJobs($redisPool, $queues, $prefix);
        });

        // Metrics publishing timer — pushes worker snapshot to Redis for the dashboard.
        $metricsInterval = (float) ($this->config['metrics']['publish_interval'] ?? 1);
        EventLoop::repeat($metricsInterval, function (string $id) use ($metricsPublisher, $metrics) {
            if ($this->stopRequested) {
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

        $this->isRunning = false;
    }

    /**
     * Process pending messages that were delivered but never acknowledged.
     *
     * Uses '0-0' as the XREADGROUP ID to fetch messages already in this
     * consumer's PEL. This handles crash recovery — messages from dead
     * consumers that were auto-claimed to us.
     */
    private function processPendingMessages(
        RedisClient $redis,
        array $queues,
        string $prefix,
        string $consumerGroup,
        StreamQueue $streamQueue,
        Dispatcher $events,
        string $connectionName,
        array $streams,
        MetricsCollector $metrics,
        DeadLetterHandler $deadLetterHandler,
        int $maxJobs,
        int $maxLifetime,
        int $startTime,
    ): void {
        fwrite(STDERR, "[torque:worker] Processing pending messages for " . implode(',', $queues) . "\n");

        while (true) {
            if ($this->stopRequested || $this->jobsProcessed >= $maxJobs || (time() - $startTime) >= $maxLifetime) {
                return;
            }

            // Read pending (already-delivered) messages using '0-0' instead of '>'.
            $args = ['GROUP', $consumerGroup, $this->consumerId, 'COUNT', '1', 'STREAMS'];
            foreach ($queues as $queue) {
                $args[] = $prefix . $queue;
            }
            foreach ($queues as $queue) {
                $args[] = '0-0';
            }

            $result = $redis->execute('XREADGROUP', ...$args);

            if ($result === null) {
                return; // No more pending messages.
            }

            // Check if any stream returned messages.
            $hasMessages = false;
            foreach ($result as $streamData) {
                if (is_array($streamData[1] ?? null) && $streamData[1] !== []) {
                    $hasMessages = true;
                    break;
                }
            }

            if (!$hasMessages) {
                return; // All pending messages processed.
            }

            // Parse and process the first available message.
            $message = $this->parseXreadgroupResponse($result);
            if ($message === null) {
                return;
            }

            fwrite(STDERR, "[torque:worker] Pending job: {$message['id']} from {$message['stream']}\n");

            $metrics->recordJobStarted();
            $jobStartTime = hrtime(true);

            try {
                $this->processMessage($message, $streamQueue, $events, $connectionName, $streams, $prefix);
                $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                $metrics->recordJobCompleted($durationMs);
                fwrite(STDERR, "[torque:worker] Pending job done: {$message['id']} ({$durationMs}ms)\n");
            } catch (\Throwable $e) {
                $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                $metrics->recordJobFailed($durationMs);
                fwrite(STDERR, "[torque:worker] Pending job FAILED: {$message['id']}: {$e->getMessage()}\n");
                $this->handleFailure($message, $e, $streamQueue, $events, $connectionName, $streams, $prefix, $deadLetterHandler);
            } finally {
                CoroutineContext::flush();
                $this->jobsProcessed++;
            }
        }
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

        $streamData = $result[0] ?? null;
        if ($streamData === null) {
            return null;
        }

        $streamKey = (string) $streamData[0];
        $messages = $streamData[1] ?? [];

        if ($messages === []) {
            return null;
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
            return null;
        }

        return [
            'stream' => $streamKey,
            'id' => $messageId,
            'payload' => $payload,
        ];
    }

    /**
     * Read the next message from any configured stream using XREADGROUP.
     *
     * Uses the dedicated reader Redis client (not the pool) to avoid tying up
     * pooled connections during BLOCK waits.
     *
     * @param  string[]  $queues
     * @return array{stream: string, id: string, payload: string}|null
     */
    private function readNextMessage(
        RedisClient $readerRedis,
        array $queues,
        string $prefix,
        string $consumerGroup,
        int $blockFor,
    ): ?array {
        $args = ['GROUP', $consumerGroup, $this->consumerId, 'COUNT', '1', 'BLOCK', (string) $blockFor, 'STREAMS'];

        foreach ($queues as $queue) {
            $args[] = $prefix . $queue;
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
            // Exhausted all retries — mark as permanently failed.
            $job->fail($exception);
            $events->dispatch(new JobFailed($connectionName, $job, $exception));

            // Move to dead-letter stream for inspection / retry via dashboard.
            $deadLetterHandler->handle(
                queue: $queueName,
                payload: $message['payload'],
                messageId: $message['id'],
                exception: $exception,
            );

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
    private function migrateDelayedJobs(RedisPool $redisPool, array $queues, string $prefix): void
    {
        $redisPool->use(function (mixed $redis) use ($queues, $prefix) {
            $now = (string) time();

            foreach ($queues as $queue) {
                $delayedKey = $prefix . $queue . ':delayed';
                $streamKey = $prefix . $queue;

                /** @var array|null $entries */
                $entries = $redis->execute('ZRANGEBYSCORE', $delayedKey, '-inf', $now, 'LIMIT', '0', '100');

                if (!is_array($entries) || $entries === []) {
                    continue;
                }

                foreach ($entries as $payload) {
                    $redis->execute('ZREM', $delayedKey, $payload);
                    $redis->execute('XADD', $streamKey, '*', 'payload', $payload);
                }
            }
        });
    }

    /**
     * Claim stale messages from crashed workers via XAUTOCLAIM.
     *
     * Messages that have been pending longer than retry_after (in ms) are
     * reassigned to this consumer so they can be reprocessed.
     *
     * @param  string[]  $queues
     * @param  array<string, array<string, mixed>>  $streams
     */
    private function claimStaleMessages(
        RedisClient $readerRedis,
        array $queues,
        string $prefix,
        string $consumerGroup,
        array $streams,
    ): void {
        foreach ($queues as $queue) {
            $streamKey = $prefix . $queue;
            $streamConfig = $streams[$queue] ?? [];
            $retryAfter = (int) ($streamConfig['retry_after'] ?? 60);

            // Loop to claim all stale messages, not just 100.
            $cursor = '0-0';
            do {
                try {
                    // Use min-idle-time of 0 to claim ALL pending messages
                    // from dead consumers, not just stale ones.
                    $result = $readerRedis->execute(
                        'XAUTOCLAIM',
                        $streamKey,
                        $consumerGroup,
                        $this->consumerId,
                        '0',
                        $cursor,
                        'COUNT',
                        '100',
                    );

                    // XAUTOCLAIM returns [nextCursor, [[id, [fields...]], ...], [deletedIds...]]
                    $cursor = is_array($result) ? (string) ($result[0] ?? '0-0') : '0-0';
                    $claimed = is_array($result) && is_array($result[1] ?? null) ? count($result[1]) : 0;
                } catch (\Amp\Redis\RedisException) {
                    // Stream or group may not exist yet — safe to ignore.
                    break;
                }
            } while ($cursor !== '0-0' && $claimed > 0);
        }
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
