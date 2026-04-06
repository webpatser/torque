<?php

declare(strict_types=1);

namespace Webpatser\Torque\Worker;

use Amp\Redis\RedisClient;
use Amp\Sync\LocalSemaphore;
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

        // Dedicated non-pooled connection for XREADGROUP — avoids tying up pool
        // connections during BLOCK waits.
        $readerRedis = createRedisClient($redisUri);

        $metrics = new MetricsCollector(totalSlots: $concurrency);
        $metricsPublisher = new MetricsPublisher(redisUri: $redisUri, prefix: $prefix);

        $semaphore = new LocalSemaphore($concurrency);

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
        $this->claimStaleMessages($readerRedis, $queues, $prefix, $consumerGroup, $streams);

        // Install signal handlers for graceful shutdown.
        EventLoop::onSignal(SIGTERM, function () {
            $this->stopRequested = true;
        });
        EventLoop::onSignal(SIGINT, function () {
            $this->stopRequested = true;
        });

        $startTime = time();

        // Main poll timer — runs as fast as the event loop allows.
        EventLoop::repeat(0.0, function (string $id) use (
            $semaphore,
            $readerRedis,
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
            if ($this->stopRequested || $this->jobsProcessed >= $maxJobs || (time() - $startTime) >= $maxLifetime) {
                EventLoop::cancel($id);
                return;
            }

            // Check if paused.
            $isPaused = $readerRedis->execute('EXISTS', $prefix . 'paused');
            if ($isPaused) {
                // Don't read new jobs while paused, but let the event loop continue
                // so in-flight jobs can complete.
                return;
            }

            // Fire Looping event — listeners can set $event->shouldStop to request a stop.
            $events->dispatch(new Looping($connectionName, $queues[0] ?? 'default'));

            $lock = $semaphore->acquire();

            $message = $this->readNextMessage($readerRedis, $queues, $prefix, $consumerGroup, $blockFor);

            if ($message === null) {
                $lock->release();
                return;
            }

            async(function () use ($message, $lock, $streamQueue, $events, $connectionName, $streams, $prefix, $metrics, $deadLetterHandler) {
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
                    $lock->release();
                    $this->jobsProcessed++;
                }
            });
        });

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

        EventLoop::run();

        $this->isRunning = false;
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

        // XREADGROUP returns null when the block timeout expires with no message.
        if ($result === null) {
            return null;
        }

        // Response shape: [[streamKey, [[messageId, [field, value, ...]]]]]
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

        // Fields are a flat list: ['payload', '{json}', ...]
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

            try {
                $readerRedis->execute(
                    'XAUTOCLAIM',
                    $streamKey,
                    $consumerGroup,
                    $this->consumerId,
                    (string) ($retryAfter * 1000),
                    '0-0',
                    'COUNT',
                    '10',
                );
            } catch (\Amp\Redis\RedisException) {
                // Stream or group may not exist yet — safe to ignore.
            }
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
