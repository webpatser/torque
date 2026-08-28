<?php

declare(strict_types=1);

namespace Webpatser\Torque\Worker;

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Events\WorkerPausing;
use Illuminate\Queue\Events\WorkerResuming;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Revolt\EventLoop;
use Webpatser\Torque\Events\JobPermanentlyFailed;
use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Job\CoroutineContext;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsCollector;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Pool\RedisPool;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Torque;

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

    /**
     * Jobs currently being processed per stream, keyed by queue name. Shared
     * across this worker's Fibers (single-threaded event loop, mutated only
     * between awaits) to enforce per-stream `max_concurrency`.
     *
     * @var array<string, int>
     */
    private array $streamActive = [];

    /**
     * Messages already delivered to this consumer by a multi-stream XREADGROUP
     * but not yet handed to a Fiber. Redis returns up to COUNT entries per
     * stream, so one read can deliver more than one message; anything beyond
     * the first is queued here and served before the next XREADGROUP, otherwise
     * it would sit in the PEL until retry_after lets another worker steal it.
     *
     * @var list<array{stream: string, id: string, payload: string}>
     */
    private array $prefetched = [];

    /** Limits — set once in run(), checked by timers to know when to cancel. */
    private int $maxJobs = 10_000;

    private int $maxLifetime = 3600;

    private int $startTime = 0;

    /** @var array<string, mixed> */
    private readonly array $config;

    /** This worker's XREADGROUP consumer name (`{host}-{pid}-{hex}`). */
    public private(set) string $consumerId;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->consumerId = gethostname().'-'.getmypid().'-'.bin2hex(random_bytes(4));
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
        $connectionName = Torque::CONNECTION;
        $streams = $this->config['streams'] ?? [];

        // Pool for job operations (delete, release, etc.)
        $redisPool = new RedisPool($redisUri, size: $poolSize);

        // Each Fiber gets its own reader Redis connection since XREADGROUP BLOCK
        // holds the connection for the duration of the wait.
        // We create them lazily inside the Fiber loop instead of sharing one.

        $metrics = new MetricsCollector(totalSlots: $concurrency);
        $metricsPublisher = new MetricsPublisher(redisUri: $redisUri, prefix: $prefix, settings: $this->config['metrics'] ?? []);

        $cluster = (bool) ($this->config['redis']['cluster'] ?? false);

        $streamQueue = new StreamQueue(
            redisUri: $redisUri,
            default: $queues[0] ?? 'default',
            retryAfter: (int) ($streams[$queues[0]]['retry_after'] ?? 90),
            blockFor: 0,
            prefix: $prefix,
            consumerGroup: $consumerGroup,
            cluster: $cluster,
            serializer: (string) ($this->config['serializer'] ?? 'json'),
        );

        $events = app(Dispatcher::class);
        $queueManager = app('queue');

        // Mirror queue:work's failed-job bookkeeping (WorkCommand::listenForEvents):
        // the failed-jobs provider write is a listener concern of the worker command
        // in Laravel, not of Job::fail() itself, so without this listener every
        // failure lands in the dead-letter stream but queue:failed and queue:retry
        // stay empty. Guarded so a broken failer write is loud but never fatal.
        $events->listen(JobFailed::class, static function (JobFailed $event): void {
            try {
                app('queue.failer')->log(
                    $event->connectionName,
                    $event->job->getQueue(),
                    self::failerPayload($event->job->getRawBody()),
                    $event->exception,
                );
            } catch (\Throwable $e) {
                fwrite(STDERR, "[torque:worker] failed-jobs log threw: {$e->getMessage()}\n");
            }
        });

        $deadLetterConfig = $this->config['dead_letter'] ?? [];
        $deadLetterHandler = new DeadLetterHandler(
            redisUri: $redisUri,
            ttl: (int) ($deadLetterConfig['ttl'] ?? 604800),
            prefix: $prefix,
            maxEntries: (int) ($deadLetterConfig['max_entries'] ?? 100000),
        );

        // Failure-storm breaker. Shared across the fleet through Redis, so a
        // dead dependency pauses the affected stream once for everyone
        // instead of every worker discovering it separately.
        $circuitBreaker = new CircuitBreaker(
            redisUri: $redisUri,
            prefix: $prefix,
            config: (array) ($this->config['circuit_breaker'] ?? []),
            streams: $streams,
            cluster: $cluster,
            events: $events,
        );

        // Build cluster-safe stream key (matches StreamQueue::getStreamKey).
        $buildStreamKey = static function (string $queue) use ($prefix, $cluster): string {
            if ($cluster && ! str_contains($queue, '{')) {
                $queue = '{'.$queue.'}';
            }

            return $prefix.$queue;
        };

        // Ensure consumer groups exist for all configured queues.
        foreach ($queues as $queue) {
            $streamQueue->ensureConsumerGroup($buildStreamKey($queue), $consumerGroup);
        }

        // Set error handler so uncaught Fiber exceptions are logged instead of exit(255).
        EventLoop::setErrorHandler(function (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] Uncaught in event loop: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
            fwrite(STDERR, $e->getTraceAsString()."\n");
        });

        fwrite(STDERR, "[torque:worker] Setup complete, entering event loop\n");

        $this->maxJobs = $maxJobs;
        $this->maxLifetime = $maxLifetime;
        $this->startTime = time();
        $startTime = $this->startTime;

        // Shared pause flag updated by a timer instead of per-Fiber EXISTS calls.
        $pauseState = new \stdClass;
        $pauseState->paused = false;
        // Framework-paused queue names (queue:pause / queue:pause --all), kept
        // fresh by the same 2s timer that polls Torque's own pause key.
        $pauseState->frameworkPausedQueues = [];
        // The effective per-queue pause set the reader fibers consume: the
        // framework's paused queues plus any stream whose circuit breaker is
        // open. One representation, one code path, whatever the source.
        $pauseState->pausedQueues = [];

        // Per-slot job-start tracker for the stalled-job watchdog. Indexed by
        // Fiber index; the value is the unix timestamp when the slot picked up
        // its current job, or absent when the slot is idle.
        /** @var array<int, int> $slotStarts */
        $slotStarts = [];
        $stallThreshold = (int) ($this->config['stall_warn_seconds'] ?? 300);

        // Per-slot StreamJob tracker. Maintained in lockstep with $slotStarts so
        // SIGTERM/SIGINT can reach the user command via $job->getResolvedJob().
        /** @var array<int, StreamJob> $slotJobs */
        $slotJobs = [];

        // Install signal handlers for graceful shutdown.
        // On SIGTERM/SIGINT we (1) flag the worker to stop, (2) dispatch
        // Laravel's WorkerInterrupted event once, and (3) forward the signal
        // to every in-flight command implementing Interruptible. This matches
        // the behaviour Illuminate\Queue\Worker added in laravel/framework
        // 13.7.0 (PRs #59833, #59848), adapted for N concurrent fibers.
        $primaryQueue = $queues[0] ?? 'default';
        $shutdownHandler = function (int $signal) use ($events, $connectionName, $primaryQueue, &$slotJobs) {
            $this->stopRequested = true;
            $this->notifyInterrupted($signal, $slotJobs, $events, $connectionName, $primaryQueue);
        };

        EventLoop::onSignal(SIGTERM, fn () => $shutdownHandler(SIGTERM));
        EventLoop::onSignal(SIGINT, fn () => $shutdownHandler(SIGINT));

        $masterPidForPause = posix_getppid();
        $pauseFetchFailed = false;
        EventLoop::repeat(2.0, function () use ($redisPool, $prefix, $pauseState, $events, $connectionName, $primaryQueue, $masterPidForPause, $queueManager, $queues, $circuitBreaker, &$pauseFetchFailed) {
            // Framework pause state (queue:pause / queue:pause --all) lives in
            // the cache store, read synchronously outside the pool callback so
            // pool-connection hold time is unchanged. On a failed read we keep
            // the last known set: a cache restart must not silently resume a
            // deliberately paused fleet.
            $fetched = self::fetchFrameworkPausedQueues($queueManager, $connectionName, $queues);

            if ($fetched !== null) {
                $pauseState->frameworkPausedQueues = $fetched;
                $pauseFetchFailed = false;
            } elseif (! $pauseFetchFailed) {
                $pauseFetchFailed = true;
                fwrite(STDERR, "[torque:worker] Framework pause state unreadable (cache store down?), keeping last known state\n");
            }

            // A tripped circuit breaker is expressed as a paused queue, so it
            // reaches the reader fibers through the same set the framework's
            // per-queue pauses use: only that stream stops being polled, and
            // a worker serving nothing else pauses fully.
            $pauseState->pausedQueues = self::mergePausedQueues(
                $pauseState->frameworkPausedQueues,
                $circuitBreaker->openQueues($queues),
            );

            $redisPool->use(function (mixed $redis) use ($prefix, $pauseState, $events, $connectionName, $primaryQueue, $masterPidForPause, $queues) {
                $value = $redis->execute('GET', $prefix.'paused');
                $ttl = (int) $redis->execute('TTL', $prefix.'paused');

                $torquePaused = self::shouldPauseFor(
                    $value === null ? null : (string) $value,
                    $ttl,
                    $masterPidForPause,
                );

                self::applyPauseTransition(
                    self::shouldFullyPause($torquePaused, $pauseState->pausedQueues, $queues),
                    $pauseState,
                    $events,
                    $connectionName,
                    $primaryQueue,
                );
            });
        });

        // Parent-death watchdog: the master exec'd this process, so at boot the
        // parent IS the master. When the master dies without SIGTERMing us (kill -9,
        // OOM), we get reparented and would otherwise keep consuming as an orphan
        // forever. Detect the reparenting and drain out through the normal stop path.
        $masterPid = posix_getppid();
        EventLoop::repeat(2.0, function () use ($masterPid) {
            if (! $this->stopRequested && posix_getppid() !== $masterPid) {
                fwrite(STDERR, "[torque:worker] Master (PID {$masterPid}) is gone, shutting down orphaned worker\n");
                $this->stopRequested = true;
            }
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
                $circuitBreaker,
                $pauseState,
                $concurrency,
                $buildStreamKey,
                &$slotStarts,
                &$slotJobs,
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

                    // Per-stream concurrency cap: exclude streams that already
                    // have max_concurrency jobs in flight in this worker. The
                    // cap is approximate under bursts (fibers that passed this
                    // filter may still be awaiting their read), overshooting by
                    // at most the number of concurrently-polling fibers.
                    $pollQueues = self::eligibleQueues($queues, $streams, $this->streamActive, $pauseState->pausedQueues);

                    $loopCount++;

                    if ($pollQueues === []) {
                        \Fledge\Async\delay($pollInterval);

                        continue;
                    }

                    // Non-blocking poll for new messages.
                    $message = $this->readNextMessage($fiberRedis, $pollQueues, $prefix, $consumerGroup, $buildStreamKey);

                    // Periodically re-check PEL for any orphaned messages (~every 25s).
                    if ($message === null && $loopCount % 50 === 0) {
                        $message = $this->readPendingMessage($fiberRedis, $pollQueues, $prefix, $consumerGroup, $buildStreamKey);
                    }

                    // Drain pending on first iteration (crash recovery).
                    if ($message === null && $loopCount === 1) {
                        $message = $this->readPendingMessage($fiberRedis, $pollQueues, $prefix, $consumerGroup, $buildStreamKey);
                    }

                    // Still nothing: steal from dead consumers.
                    if ($message === null) {
                        $message = $this->stealMessage($fiberRedis, $pollQueues, $prefix, $consumerGroup, $streams, $buildStreamKey);
                    }

                    if ($message === null) {
                        // No work: yield to event loop and wait before polling again.
                        \Fledge\Async\delay($pollInterval);

                        // Liveness check: PING the per-Fiber reader connection every
                        // ~30 idle iterations (~60s at default pollInterval). On a
                        // half-open socket (common after NAT timeout, redis client
                        // output buffer kill, or a server restart), XREADGROUP can
                        // block forever waiting for a reply that never comes. PING
                        // surfaces the dead client so we can recreate it.
                        if ($loopCount % 30 === 0) {
                            try {
                                $fiberRedis->execute('PING');
                            } catch (\Throwable $e) {
                                fwrite(STDERR, "[torque:worker] Fiber {$fiberIndex} reader Redis dead ({$e->getMessage()}); reconnecting.\n");
                                try {
                                    $fiberRedis = createRedisClient($redisUri);
                                } catch (\Throwable $reconnect) {
                                    fwrite(STDERR, "[torque:worker] Fiber {$fiberIndex} reconnect failed: {$reconnect->getMessage()}\n");
                                }
                            }
                        }

                        continue;
                    }

                    $metrics->recordJobStarted();
                    $jobStartTime = hrtime(true);
                    $slotStarts[$fiberIndex] = time();

                    $queueName = $this->resolveQueueName($message['stream'], $prefix);

                    if (! StreamQueue::isValidPayload($message['payload'])) {
                        // Corrupt payload, unrecognized format: acknowledge and discard.
                        $streamQueue->deleteAndAcknowledge($queueName, $message['id']);
                        unset($slotStarts[$fiberIndex]);
                        CoroutineContext::flush();
                        $this->jobsProcessed++;

                        continue;
                    }

                    $streamJob = new StreamJob(
                        container: app(),
                        streamQueue: $streamQueue,
                        rawBody: $message['payload'],
                        messageId: $message['id'],
                        connectionName: $connectionName,
                        queue: $queueName,
                    );
                    $slotJobs[$fiberIndex] = $streamJob;
                    $this->streamActive[$queueName] = ($this->streamActive[$queueName] ?? 0) + 1;

                    try {
                        $this->processMessage($streamJob, $events, $connectionName);
                        $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                        $metrics->recordJobCompleted($durationMs, $queueName, rescue(fn (): string => $streamJob->resolveName(), null, false));

                        // A job that released itself for a later attempt is
                        // neutral for the breaker, exactly like a retry after
                        // an exception; only a real completion counts.
                        if (! $streamJob->isReleased() && ! $streamJob->hasFailed()) {
                            $circuitBreaker->recordSuccess($queueName);
                        }
                    } catch (\Throwable $e) {
                        $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                        $metrics->recordJobFailed($durationMs, $queueName, rescue(fn (): string => $streamJob->resolveName(), null, false));
                        $this->handleFailure($streamJob, $message, $e, $events, $connectionName, $streams, $deadLetterHandler, $circuitBreaker);
                    } finally {
                        $this->streamActive[$queueName] = max(0, ($this->streamActive[$queueName] ?? 1) - 1);
                        unset($slotStarts[$fiberIndex], $slotJobs[$fiberIndex]);
                        CoroutineContext::flush();
                        $this->jobsProcessed++;
                    }
                }
            });
        }

        // Delayed job migration timer: checks every second for matured delayed jobs.
        // Keeps running during the drain window so delayed jobs don't pile up while
        // a hung Fiber prevents the worker from exiting cleanly.
        EventLoop::repeat(1.0, function () use ($redisPool, $queues, $buildStreamKey) {
            $this->migrateDelayedJobs($redisPool, $queues, $buildStreamKey);
        });

        // Metrics publishing timer — pushes worker snapshot to Redis for the dashboard.
        // Keeps publishing during the drain window so the dashboard does not blink
        // to "0 workers" before the hard-exit deadline fires.
        $metricsInterval = (float) ($this->config['metrics']['publish_interval'] ?? 1);
        EventLoop::repeat($metricsInterval, function () use ($metricsPublisher, $metrics) {
            $metricsPublisher->publishWorkerMetrics($this->consumerId, $metrics->snapshot());
        });

        // Stalled-job watchdog: log a WARN line for any slot that has been
        // processing the same job for longer than the configured threshold.
        // Fires every 30s; threshold defaults to 300s. Catches hung user jobs
        // (e.g. external HTTP calls without a timeout) before they block the
        // worker through its full lifetime.
        EventLoop::repeat(30.0, function () use (&$slotStarts, $stallThreshold) {
            $now = time();
            foreach ($slotStarts as $idx => $started) {
                $age = $now - $started;
                if ($age >= $stallThreshold) {
                    fwrite(STDERR, "[torque:worker] WARN slot {$idx} processing same job for {$age}s (threshold {$stallThreshold}s)\n");
                }
            }
        });

        // Hard-exit deadline timer.
        //
        // Once limits are reached (max jobs, max lifetime, or stop signal), give
        // Fibers up to drain_grace seconds to finish in-flight work, then call
        // exit(0) so the master sees SIGCHLD and respawns. Without this, a single
        // Fiber suspended inside processMessage() or a half-open Redis socket
        // would keep the EventLoop running indefinitely and the worker would
        // never rotate.
        $drainGrace = (int) ($this->config['drain_grace_seconds'] ?? 10);
        $drainStartedAt = null;
        EventLoop::repeat(1.0, function () use (&$drainStartedAt, $drainGrace, $metricsPublisher, $metrics, $redisUri, $queues, $consumerGroup, $buildStreamKey) {
            if (! $this->hasReachedLimits()) {
                return;
            }

            if ($drainStartedAt === null) {
                $drainStartedAt = time();
                fwrite(STDERR, "[torque:worker] Limits reached; draining for up to {$drainGrace}s.\n");
            }

            if (time() - $drainStartedAt < $drainGrace) {
                return;
            }

            try {
                $metricsPublisher->publishWorkerMetrics($this->consumerId, $metrics->snapshot());
                $metricsPublisher->removeWorkerMetrics($this->consumerId);
            } catch (\Throwable) {
                // Best-effort cleanup; do not block the hard exit.
            }

            $this->releaseConsumer($redisUri, $queues, $consumerGroup, $buildStreamKey);

            fwrite(STDERR, "[torque:worker] Drain window expired; forcing exit.\n");
            exit(0);
        });

        try {
            EventLoop::run();
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] EventLoop crashed: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
            fwrite(STDERR, $e->getTraceAsString()."\n");
        }

        // Remove this worker's metrics key from Redis so it doesn't linger as a ghost.
        try {
            $metricsPublisher->removeWorkerMetrics($this->consumerId);
        } catch (\Throwable) {
            // Best-effort cleanup — don't prevent shutdown.
        }

        $this->releaseConsumer($redisUri, $queues, $consumerGroup, $buildStreamKey);

        $this->isRunning = false;
    }

    /**
     * Delete this worker's consumer from every stream it served.
     *
     * Every worker start mints a fresh `{host}-{pid}-{hex}` consumer name, so
     * without this each rotation leaves one behind forever (scrpr 2026-08-27:
     * 124k consumers per stream). A consumer with pending entries is left
     * alone: XGROUP DELCONSUMER discards its PEL, and those entries must stay
     * claimable by the next worker's XAUTOCLAIM instead.
     *
     * Best-effort throughout, like the metrics cleanup next to it: shutdown
     * must never hang on Redis. Names left behind by a crashed worker are
     * swept later by {@see StreamHousekeeper}.
     *
     * @param  string[]  $queues
     * @param  \Closure(string): string  $buildStreamKey
     */
    public function releaseConsumer(
        string $redisUri,
        array $queues,
        string $consumerGroup,
        \Closure $buildStreamKey,
    ): void {
        try {
            $redis = createRedisClient($redisUri);
        } catch (\Throwable) {
            return;
        }

        foreach ($queues as $queue) {
            $streamKey = $buildStreamKey($queue);

            try {
                $pending = $redis->execute('XPENDING', $streamKey, $consumerGroup, '-', '+', '1', $this->consumerId);

                if (is_array($pending) && $pending !== []) {
                    continue;
                }

                $redis->execute('XGROUP', 'DELCONSUMER', $streamKey, $consumerGroup, $this->consumerId);
            } catch (\Throwable) {
                continue;
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
        $messages = $this->parseXreadgroupMessages($result);

        if ($messages === []) {
            return null;
        }

        $first = array_shift($messages);

        foreach ($messages as $message) {
            $this->prefetched[] = $message;
        }

        return $first;
    }

    /**
     * Every message in an XREADGROUP reply, in stream order.
     *
     * @return list<array{stream: string, id: string, payload: string}>
     */
    private function parseXreadgroupMessages(mixed $result): array
    {
        if ($result === null || ! is_array($result)) {
            return [];
        }

        $parsed = [];

        foreach ($result as $streamData) {
            if ($streamData === null) {
                continue;
            }

            $streamKey = (string) $streamData[0];

            foreach ($streamData[1] ?? [] as $message) {
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

                $parsed[] = [
                    'stream' => $streamKey,
                    'id' => $messageId,
                    'payload' => $payload,
                ];
            }
        }

        return $parsed;
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
            } catch (RedisException) {
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
     * Dispatch WorkerInterrupted and forward the signal to in-flight Interruptible jobs.
     *
     * Mirrors Illuminate\Queue\Worker's signal handling (laravel/framework 13.7.0,
     * PRs #59833 and #59848), broadened to N concurrent fibers: each slot's
     * StreamJob is inspected and any user command implementing Interruptible
     * receives interrupted($signal). Public so the behaviour can be exercised
     * in tests without spinning up the event loop.
     *
     * @param  array<int, StreamJob>  $slotJobs
     */
    public function notifyInterrupted(
        int $signal,
        array $slotJobs,
        Dispatcher $events,
        string $connectionName,
        ?string $queue,
    ): void {
        try {
            $events->dispatch(new WorkerInterrupted($signal, $connectionName, $queue));
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] WorkerInterrupted dispatch failed: {$e->getMessage()}\n");
        }

        foreach ($slotJobs as $job) {
            $resolved = $job->getResolvedJob();

            if (! $resolved instanceof CallQueuedHandler) {
                continue;
            }

            $command = $resolved->getRunningCommand();

            if (! $command instanceof Interruptible) {
                continue;
            }

            try {
                $command->interrupted($signal);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[torque:worker] Job interrupted({$signal}) threw: {$e->getMessage()}\n");
            }
        }
    }

    /**
     * Decide whether this worker should pause for the current pause key.
     *
     * The key's TTL discriminates the two pause kinds:
     * - A TTL'd key is a drain-scoped pause written by a draining master as
     *   `drain:<masterPid>`. It only applies to that master's own workers,
     *   so a takeover's old fleet drains without pausing the replacement.
     *   Legacy-format drain values (timestamps) also carry a TTL and are
     *   ignored the same way: under an old-code master its drain degrades to
     *   the plain SIGTERM-after-grace stop, which is safe.
     * - A TTL-less key is a deliberate `torque:pause` and pauses every fleet.
     *
     * Static and pure for unit testing. `$ttl` uses Redis semantics: -2 key
     * missing, -1 no expiry, >0 seconds remaining.
     */
    public static function shouldPauseFor(?string $value, int $ttl, int $masterPid): bool
    {
        if ($value === null || $ttl === -2) {
            return false;
        }

        if ($ttl === -1) {
            return true;
        }

        return $value === 'drain:'.$masterPid;
    }

    /**
     * Combined pause decision: Torque's own switch OR the framework pausing
     * every queue this worker serves. `QueueManager::getPausedQueues()` returns
     * the full queue list when the global `illuminate:queues:paused` flag is
     * set, so "all queues paused" covers both the global switch and every
     * queue being paused individually. Static and pure for unit testing.
     *
     * @param  list<string>  $frameworkPaused
     * @param  list<string>  $queues
     */
    public static function shouldFullyPause(bool $torquePaused, array $frameworkPaused, array $queues): bool
    {
        if ($torquePaused) {
            return true;
        }

        return $queues !== [] && array_diff($queues, $frameworkPaused) === [];
    }

    /**
     * Read the framework's paused-queue state (queue:pause per-queue keys plus
     * the 13.25 global flag) in a single cache round-trip. Returns null when
     * the state could not be read so the caller keeps the last known set
     * instead of spuriously pausing or resuming. Honors Worker::$pausable the
     * same way the stock queue worker does.
     *
     * @param  list<string>  $queues
     * @return list<string>|null
     */
    public static function fetchFrameworkPausedQueues(
        QueueManager $manager,
        string $connectionName,
        array $queues,
    ): ?array {
        if (! Worker::$pausable) {
            return [];
        }

        try {
            return array_values($manager->getPausedQueues($connectionName, $queues));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Combine the framework's paused queues with the circuit breaker's open
     * ones into the single set the reader fibers consume.
     *
     * Static and pure for unit testing.
     *
     * @param  list<string>  $frameworkPaused
     * @param  list<string>  $circuitOpen
     * @return list<string>
     */
    public static function mergePausedQueues(array $frameworkPaused, array $circuitOpen): array
    {
        return array_values(array_unique([...$frameworkPaused, ...$circuitOpen]));
    }

    /**
     * Reconcile the pause flag with the latest Redis state and dispatch the
     * matching Laravel event when it transitions.
     *
     * Static so unit tests can drive it without a live event loop. The poller
     * runs every 2 seconds, so without the transition guard listeners would
     * receive the event repeatedly while the worker stays paused; the Laravel
     * 13.8 Worker dispatches once per signal and we match that semantic.
     */
    public static function applyPauseTransition(
        bool $isPaused,
        \stdClass $pauseState,
        Dispatcher $events,
        string $connectionName,
        ?string $queue,
    ): void {
        if ($isPaused === $pauseState->paused) {
            return;
        }

        $pauseState->paused = $isPaused;

        try {
            $events->dispatch($isPaused
                ? new WorkerPausing($connectionName, $queue)
                : new WorkerResuming($connectionName, $queue));
        } catch (\Throwable $e) {
            $name = $isPaused ? 'WorkerPausing' : 'WorkerResuming';
            fwrite(STDERR, "[torque:worker] {$name} dispatch failed: {$e->getMessage()}\n");
        }
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
        if ($this->prefetched !== []) {
            return array_shift($this->prefetched);
        }

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
     * Fire the job and dispatch the surrounding processing events.
     */
    private function processMessage(
        StreamJob $job,
        Dispatcher $events,
        string $connectionName,
    ): void {
        $events->dispatch(new JobProcessing($connectionName, $job));

        $job->fire();

        // If the job handler did not explicitly delete, release, or fail the job,
        // treat it as successfully completed.
        if (! $job->isDeleted() && ! $job->isReleased() && ! $job->hasFailed()) {
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
        StreamJob $job,
        array $message,
        \Throwable $exception,
        Dispatcher $events,
        string $connectionName,
        array $streams,
        DeadLetterHandler $deadLetterHandler,
        ?CircuitBreaker $circuitBreaker = null,
    ): void {
        $queueName = $job->getQueue();

        $events->dispatch(new JobExceptionOccurred($connectionName, $job, $exception));

        $streamConfig = $streams[$queueName] ?? [];
        $maxRetries = (int) ($streamConfig['max_retries'] ?? 3);

        // A dontRetry()/dontRetryWhen() verdict from the application's exception
        // handler (Laravel v13.17.0+) forces immediate failure, no matter how
        // many attempts remain — matching Illuminate\Queue\Worker.
        if (! $this->shouldStopRetries($exception) && $job->attempts() < $maxRetries) {
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

            // Only permanent failures feed the breaker; a release/retry is
            // neutral, since a job that is going to be retried says nothing
            // yet about the health of the stream's dependencies.
            $circuitBreaker?->recordFailure($queueName);

            // Mark as failed via Laravel's base Job — this ACKs/DELs the
            // stream message and calls the job's failed() callback.
            try {
                $job->fail($exception);
            } catch (\Throwable $failError) {
                // fail() already deleted the message from the stream.
                // If it throws after that (e.g. failed() callback or DB),
                // the job is already in dead-letter — safe to swallow, but
                // never silently: a broken failed_jobs write would otherwise
                // hide every failure from queue:failed and retry tooling.
                fwrite(STDERR, "[torque:worker] job->fail() threw: {$failError->getMessage()} in {$failError->getFile()}:{$failError->getLine()}\n");
            }

            // No manual JobFailed dispatch here: Job::fail() already dispatches it
            // in its finally block, and a second event would double-write the
            // failed-jobs row now that the worker registers the logging listener.

            // Fire a domain event so users can hook in custom notification logic.
            try {
                $decoded = StreamQueue::decodePayload($message['payload']);
            } catch (\Throwable) {
                $decoded = [];
            }
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
     * Determine if the application's exception handler wants this exception to
     * stop job retries (Laravel v13.17.0 dontRetry / dontRetryWhen).
     *
     * Guarded by method_exists so handlers from older framework versions —
     * which have no shouldStopRetries() — keep the attempt-count behaviour.
     */
    private function shouldStopRetries(\Throwable $exception): bool
    {
        $handler = app(ExceptionHandler::class);

        return method_exists($handler, 'shouldStopRetries')
            && $handler->shouldStopRetries($exception);
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
                $delayedKey = $streamKey.':delayed';

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
    /**
     * Filter the poll list down to streams below their max_concurrency cap.
     *
     * A stream with `max_concurrency` in its config is skipped while this
     * worker already has that many of its jobs in flight; streams without a
     * cap are always eligible. The cap is per worker process: a fleet of N
     * workers allows up to N * max_concurrency simultaneous jobs fleet-wide.
     *
     * @param  list<string>  $queues
     * @param  array<string, array<string, mixed>>  $streams
     * @param  array<string, int>  $active
     * @param  list<string>  $paused  Framework-paused queue names to skip.
     * @return list<string>
     */
    public static function eligibleQueues(array $queues, array $streams, array $active, array $paused = []): array
    {
        return array_values(array_filter($queues, function (string $queue) use ($streams, $active, $paused): bool {
            if (in_array($queue, $paused, true)) {
                return false;
            }

            $cap = $streams[$queue]['max_concurrency'] ?? null;

            if ($cap === null) {
                return true;
            }

            return ($active[$queue] ?? 0) < (int) $cap;
        }));
    }

    private function resolveQueueName(string $streamKey, string $prefix): string
    {
        if (str_starts_with($streamKey, $prefix)) {
            return substr($streamKey, strlen($prefix));
        }

        return $streamKey;
    }

    /**
     * The payload the framework's failed-job provider should store.
     *
     * Every shipped provider (`database-uuids` in particular, which reads
     * `json_decode($payload, true)['uuid']`) and `queue:retry` assume JSON. An
     * igbinary body is re-encoded so `failed_jobs`, `queue:failed` and
     * `queue:retry` keep working with `TORQUE_SERIALIZER=igbinary`; the retry
     * push runs through {@see StreamQueue::transcodeIncomingPayload()}, which
     * turns it back into igbinary on the wire. JSON bodies pass untouched.
     */
    public static function failerPayload(string $rawBody): string
    {
        if ($rawBody === '' || $rawBody[0] === '{') {
            return $rawBody;
        }

        return json_encode(StreamQueue::decodePayload($rawBody), JSON_THROW_ON_ERROR);
    }
}
