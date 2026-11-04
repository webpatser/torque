<?php

declare(strict_types=1);

namespace Webpatser\Torque\Worker;

use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobInterrupted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Events\WorkerPausing;
use Illuminate\Queue\Events\WorkerResuming;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Revolt\EventLoop;
use Webpatser\Torque\Events\JobPermanentlyFailed;
use Webpatser\Torque\Job\AttemptContextRepository;
use Webpatser\Torque\Job\AttemptDeferredCallbacks;
use Webpatser\Torque\Job\AttemptScope;
use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Job\CoroutineContext;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Job\QuiescentReset;
use Webpatser\Torque\Metrics\MetricsCollector;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Pool\RedisPool;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Torque;

use function Fledge\Async\async;
use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Redis\createRedisConnector;

/**
 * The heart of Torque. Runs inside a forked child process with a Revolt event loop.
 *
 * Each worker reads jobs from one or more Redis Streams via XREADGROUP, then
 * dispatches them concurrently using Fibers gated by a semaphore. A dedicated
 * (non-pooled) Redis connection is used for the non-blocking XREADGROUP reads
 * so pool connections are never tied up by polling.
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

    /**
     * XAUTOCLAIM round trips one poll may spend per stream. Each looks at about
     * ten PEL entries at COUNT 1; the cursor carries on from there at the next poll.
     */
    private const STEAL_SCANS_PER_POLL = 8;

    /** Seconds a slot's read waits for a reply when `redis.read_timeout` is not set. */
    private const DEFAULT_READ_TIMEOUT = 30.0;

    public private(set) bool $isRunning = false;

    public private(set) int $jobsProcessed = 0;

    private bool $stopRequested = false;

    /** True while a queue:restart cache read is awaiting its reply, so ticks do not overlap. */
    private bool $restartChecking = false;

    /**
     * Set once the process is going down: on the worker's own exit paths and,
     * through a shutdown function, on any other exit such as a job's exit() or
     * dd(). Exiting destroys the suspended job Fibers, and PHP runs their
     * `finally` blocks while doing so, after the shutdown functions. Those jobs
     * were abandoned, so they must not be reported as attempted.
     */
    private bool $exiting = false;

    /** Whether a graceful stop has been requested (via SIGTERM/SIGINT or max limits). */
    public bool $shouldStop {
        get => $this->stopRequested;
    }

    /**
     * Jobs currently being processed per stream plus reads in flight on it,
     * keyed by queue name. Shared across this worker's Fibers (single-threaded
     * event loop, mutated only between awaits) to enforce per-stream
     * `max_concurrency`.
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

    /**
     * Messages a slot of this worker is processing right now, keyed
     * `{stream}|{id}`. Every slot shares one consumer name, so this
     * consumer's PEL lists them as pending; the pending read must skip them
     * or a second slot would run the same message concurrently.
     *
     * @var array<string, true>
     */
    private array $inFlight = [];

    /**
     * Pending re-reads currently awaiting their XREADGROUP reply.
     */
    private int $pendingReads = 0;

    /**
     * Messages whose slot finished while a pending re-read was awaiting
     * Redis, keyed `{stream}|{id}`. The reply may have been built before the
     * XACK landed, so it can still list a message that is done; without this
     * set the read would hand the acked job to a second slot. Cleared once no
     * pending read is outstanding.
     *
     * @var array<string, true>
     */
    private array $finishedDuringRead = [];

    /**
     * Where the next XAUTOCLAIM of each stream continues, keyed by stream key.
     * Starting every scan at 0-0 would look at the same first entries forever:
     * Redis stops a COUNT 1 scan after about ten entries that are not idle yet,
     * so a dead message behind those would never be reached. Slots overwriting
     * each other's cursor can only skip entries until the scan wraps around.
     *
     * @var array<string, string>
     */
    private array $stealCursors = [];

    /** Limits — set once in run(), checked by timers to know when to cancel. */
    private int $maxJobs = 10_000;

    private int $maxLifetime = 3600;

    private int $startTime = 0;

    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * Cache store holding the crash-counting markers, resolved on first use.
     * False once resolution failed, so a missing store is not retried per job.
     */
    private CacheRepository|false|null $crashCache = null;

    /** This worker's XREADGROUP consumer name (`{host}-{pid}-{hex}`). */
    public private(set) string $consumerId;

    /**
     * Exceptions already handed to the exception handler, so one object that
     * surfaces on two paths (a listener rethrowing the job's exception) is
     * reported once. Entries go away with the exception.
     *
     * @var \WeakMap<\Throwable, true>
     */
    private \WeakMap $reported;

    /**
     * When a recurring worker-level failure was last announced, by a fixed key:
     * an event class or the read path. hrtime in seconds.
     *
     * @var array<string, int>
     */
    private array $lastAnnounced = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->reported = new \WeakMap;
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

        register_shutdown_function(function (): void {
            $this->exiting = true;
        });

        $this->isRunning = true;

        $redisUri = $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
        $prefix = $this->config['redis']['prefix'] ?? 'torque:';
        $consumerGroup = $this->config['consumer_group'] ?? 'torque';
        $concurrency = (int) ($this->config['coroutines_per_worker'] ?? 50);
        $pollInterval = ((int) ($this->config['block_for'] ?? 2000)) / 1000.0;
        $maxJobs = (int) ($this->config['max_jobs_per_worker'] ?? 10_000);
        // Jitter here and nowhere else: $this->maxLifetime and the per-Fiber
        // capture below both read this local, so one call keeps every limit
        // check in this process on the same deadline.
        $maxLifetime = self::jitteredLifetime(
            (int) ($this->config['max_worker_lifetime'] ?? 3600),
            (float) ($this->config['max_worker_lifetime_jitter'] ?? 0.1),
        );
        $poolSize = (int) ($this->config['pools']['redis']['size'] ?? 30);
        /** @var list<string> $queues */
        $queues = (array) ($this->config['queues'] ?? ['default']);
        $connectionName = Torque::CONNECTION;
        $streams = $this->config['streams'] ?? [];

        // Pool for job operations (delete, release, etc.)
        $redisPool = new RedisPool($redisUri, size: $poolSize);

        // Each Fiber gets its own reader Redis connection so concurrent reads
        // never share a socket mid-protocol.
        // We create them lazily inside the Fiber loop instead of sharing one.

        $metrics = new MetricsCollector(totalSlots: $concurrency);
        $metricsPublisher = new MetricsPublisher(redisUri: $redisUri, prefix: $prefix, settings: $this->config['metrics'] ?? []);

        $cluster = (bool) ($this->config['redis']['cluster'] ?? false);

        // One name per queue from here on, whichever way the config wrote it:
        // the hash tag belongs to the Redis key, which $buildStreamKey adds back.
        $queues = array_values(array_unique(array_map(
            static fn (string $queue): string => self::bareQueueName($queue, $cluster),
            $queues,
        )));
        $streams = self::bareStreamConfigs($streams, $cluster);

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

        // Context and defer() callbacks belong to the attempt that registered
        // them; the framework's shared instances assume one job at a time.
        AttemptContextRepository::install(app());
        AttemptDeferredCallbacks::install(app());

        // Not a job's exception, so like a failed read it is reported whatever
        // Worker::$reportJobExceptions says, and at most once a minute: it fails
        // again at every quiet moment until someone fixes it.
        $quiescentReset = QuiescentReset::for(app(), function (\Throwable $e): void {
            if ($this->mayAnnounce('quiescent reset', 60)) {
                $this->reportSafely($e);
            }
        });

        if ($concurrency > 1 && ($shared = QuiescentReset::userScopedBindings(app())) !== []) {
            fwrite(STDERR, "[torque:worker] WARN coroutines_per_worker={$concurrency}: these scoped bindings are shared by concurrent jobs and reset only when no job is in flight, which may never happen under sustained load: ".implode(', ', $shared)."\n");
        }

        // Mirror queue:work's failed-job bookkeeping (WorkCommand::listenForEvents):
        // the failed-jobs provider write is a listener concern of the worker command
        // in Laravel, not of Job::fail() itself, so without this listener every
        // failure lands in the dead-letter stream but queue:failed and queue:retry
        // stay empty. Guarded so a broken failer write is loud but never fatal.
        $failerSerializer = (string) ($this->config['serializer'] ?? 'json');
        $events->listen(JobFailed::class, function (JobFailed $event) use ($failerSerializer): void {
            try {
                app('queue.failer')->log(
                    $event->connectionName,
                    $event->job->getQueue(),
                    self::failerPayload($event->job->getRawBody(), $failerSerializer),
                    $event->exception,
                );
            } catch (\Throwable $e) {
                fwrite(STDERR, "[torque:worker] failed-jobs log threw: {$e->getMessage()}\n");

                if (Worker::$reportJobExceptions) {
                    $this->reportSafely($e);
                }
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

        // Timestamp of the last queue:restart at start-up; a later change means
        // a deploy asked for fresh code.
        $restartBaseline = null;
        $restartBaselineKnown = false;
        $restartReadFailed = false;

        if (Worker::$restartable && ($restartCache = $this->crashCache()) !== null) {
            try {
                $restartBaseline = $restartCache->get('illuminate:queue:restart');
                $restartBaselineKnown = true;
            } catch (\Throwable $e) {
                $restartReadFailed = true;
                fwrite(STDERR, "[torque:worker] queue:restart key unreadable ({$e->getMessage()}), ignoring\n");
            }
        }

        fwrite(STDERR, "[torque:worker] Setup complete, entering event loop\n");

        $this->maxJobs = $maxJobs;
        $this->maxLifetime = $maxLifetime;
        $this->startTime = time();
        $startTime = $this->startTime;

        // Shared pause state, mutated by the 2s timer and read by every reader Fiber.
        $pauseState = self::newPauseState();

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

        // Per-slot attempt scope, so a signal callback can run a job's interrupted()
        // in it. A slot's job is in $slotJobs before its scope exists, but it has
        // no resolved command until after that, so notifyInterrupted() skips it.
        /** @var array<int, AttemptScope> $slotScopes */
        $slotScopes = [];

        // Install signal handlers for graceful shutdown.
        // On SIGTERM/SIGINT we (1) flag the worker to stop, (2) dispatch
        // Laravel's WorkerInterrupted event once, (3) forward the signal
        // to every in-flight command implementing Interruptible and (4)
        // dispatch JobInterrupted for each one that was told. This matches
        // the behaviour Illuminate\Queue\Worker added in laravel/framework
        // 13.7.0 (PRs #59833, #59848) and extended in 13.31.0 with the
        // JobInterrupted event, adapted for N concurrent fibers.
        $primaryQueue = $queues[0] ?? 'default';
        $shutdownHandler = function (int $signal) use ($events, $connectionName, $primaryQueue, &$slotJobs, &$slotScopes) {
            $this->stopRequested = true;
            $this->notifyInterrupted($signal, $slotJobs, $events, $connectionName, $primaryQueue, $slotScopes);
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
        EventLoop::repeat(2.0, function () use ($masterPid, &$restartBaseline, &$restartBaselineKnown, &$restartReadFailed) {
            if (! $this->stopRequested && posix_getppid() !== $masterPid) {
                fwrite(STDERR, "[torque:worker] Master (PID {$masterPid}) is gone, shutting down orphaned worker\n");
                $this->stopRequested = true;
            }

            // queue:restart: the same timestamp comparison as the stock worker,
            // checked here so an idle worker notices too. A change drains out
            // through the normal stop path (exit 0, which the master respawns).
            if ($this->stopRequested || ! Worker::$restartable || $this->restartChecking
                || ($cache = $this->crashCache()) === null) {
                return;
            }

            $this->restartChecking = true;

            try {
                $current = $cache->get('illuminate:queue:restart');
                $restartReadFailed = false;

                if (! $restartBaselineKnown) {
                    // The start-up read failed: adopt the first good value, so
                    // an outage is never mistaken for a restart.
                    $restartBaseline = $current;
                    $restartBaselineKnown = true;
                } elseif ($current != $restartBaseline) {
                    fwrite(STDERR, "[torque:worker] Received queue:restart signal, draining and exiting.\n");
                    $this->stopRequested = true;
                }
            } catch (\Throwable $e) {
                if (! $restartReadFailed) {
                    $restartReadFailed = true;
                    fwrite(STDERR, "[torque:worker] queue:restart key unreadable ({$e->getMessage()}), ignoring\n");
                }
            } finally {
                $this->restartChecking = false;
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
                $quiescentReset,
                $cluster,
                &$slotStarts,
                &$slotJobs,
                &$slotScopes,
            ) {
                // Stagger Fiber startup to spread polling across time.
                \Fledge\Async\delay($fiberIndex * ($pollInterval / $concurrency));

                $fiberRedis = $this->createReaderClient($redisUri);
                $loopCount = 0;
                $readFailures = 0;

                while (true) {
                    if ($this->hasReachedLimits()) {
                        return;
                    }

                    if ($pauseState->paused) {
                        \Fledge\Async\delay(1.0);

                        continue;
                    }

                    // Fires on every poll of every slot, so a broken listener is
                    // announced once a minute, not once per poll.
                    $this->dispatchSafely($events, new Looping($connectionName, $queues[0] ?? 'default'), announceEverySeconds: 60);

                    // Per-stream concurrency cap: exclude streams that already
                    // have max_concurrency jobs or reads in flight in this
                    // worker. Each read below counts until it returns, so slots
                    // that poll at the same moment cannot pass this check on
                    // the same free place.
                    $pollQueues = self::eligibleQueues($queues, $streams, $this->streamActive, $pauseState->pausedQueues);

                    $loopCount++;

                    if ($pollQueues === []) {
                        \Fledge\Async\delay($pollInterval);

                        continue;
                    }

                    // Nothing suspends between the check above and this, or
                    // between the end of the read and the count for its job.
                    foreach ($pollQueues as $polled) {
                        $this->streamActive[$polled] = ($this->streamActive[$polled] ?? 0) + 1;
                    }

                    try {
                        try {
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
                        } finally {
                            // Before the back-off below: a slot with a broken
                            // connection must not hold other slots off the stream.
                            foreach ($pollQueues as $polled) {
                                $this->streamActive[$polled]--;
                            }
                        }

                        $readFailures = 0;
                    } catch (\Throwable $e) {
                        // A throw here would end this Fiber, and the slot with
                        // it, until the worker rotates. Redis errors can last
                        // (outage, NOGROUP after a flush), hence the back-off.
                        // The next limit check still stops the worker on time.
                        $this->backOffSlot($fiberIndex, 'failed to read', $e, ++$readFailures);

                        try {
                            $fiberRedis = $this->createReaderClient($redisUri);
                        } catch (\Throwable $reconnect) {
                            fwrite(STDERR, "[torque:worker] Fiber {$fiberIndex} reconnect failed: {$reconnect->getMessage()}\n");
                        }

                        continue;
                    }

                    if ($message === null) {
                        // No work: yield to event loop and wait before polling again.
                        \Fledge\Async\delay($pollInterval);

                        // Liveness check: PING the per-Fiber reader connection every
                        // ~30 idle iterations (~60s at default pollInterval). On a
                        // half-open socket (common after NAT timeout, redis client
                        // output buffer kill, or a server restart), a reply never
                        // comes. The reads above then end through the read timeout
                        // and the catch; this replaces a client whose PING fails,
                        // and is subject to the same timeout.
                        if ($loopCount % 30 === 0) {
                            try {
                                $fiberRedis->execute('PING');
                            } catch (\Throwable $e) {
                                fwrite(STDERR, "[torque:worker] Fiber {$fiberIndex} reader Redis dead ({$e->getMessage()}); reconnecting.\n");
                                try {
                                    $fiberRedis = $this->createReaderClient($redisUri);
                                } catch (\Throwable $reconnect) {
                                    fwrite(STDERR, "[torque:worker] Fiber {$fiberIndex} reconnect failed: {$reconnect->getMessage()}\n");
                                }
                            }
                        }

                        continue;
                    }

                    $inFlightKey = $message['stream'].'|'.$message['id'];
                    $this->inFlight[$inFlightKey] = true;

                    $metrics->recordJobStarted();
                    $jobStartTime = hrtime(true);
                    $slotStarts[$fiberIndex] = time();

                    $queueName = self::queueNameFromStreamKey($message['stream'], $prefix, $cluster);

                    $wireSerializer = (string) ($this->config['serializer'] ?? 'json');

                    try {
                        $streamJob = null;
                        $quarantineReason = null;

                        if (StreamQueue::isValidPayload($message['payload'], $wireSerializer)) {
                            try {
                                $streamJob = new StreamJob(
                                    container: app(),
                                    streamQueue: $streamQueue,
                                    rawBody: $message['payload'],
                                    messageId: $message['id'],
                                    connectionName: $connectionName,
                                    queue: $queueName,
                                );
                            } catch (\Throwable $undecodable) {
                                // The pre-flight check reads the header only, so a
                                // corrupt igbinary body gets here. Left alone it
                                // would come back at every pending read, forever.
                                fwrite(STDERR, "[torque:worker] Quarantining undecodable message {$message['id']}: {$undecodable->getMessage()}\n");
                                $quarantineReason = 'undecodable';
                            }
                        } elseif (StreamQueue::isSerializerMismatch($message['payload'], $wireSerializer)) {
                            // An igbinary entry the configured serializer refuses to
                            // decode (leftover from an earlier run) can be recovered
                            // after switching TORQUE_SERIALIZER back.
                            $quarantineReason = 'serializer_mismatch';
                        }

                        if ($streamJob === null) {
                            // Park first: if the write throws, the entry stays
                            // pending for reclaim, and so it does on a crash between
                            // the two calls.
                            if ($quarantineReason !== null) {
                                $deadLetterHandler->quarantine($queueName, $message['payload'], $message['id'], $quarantineReason);
                            }

                            // Corrupt payload, unrecognized format: acknowledge and discard.
                            // recordJobFailed() balances the recordJobStarted() above;
                            // without it every corrupt payload leaks one activeSlot for
                            // the life of the process, so the gauge never returns to 0.
                            $streamQueue->deleteAndAcknowledge($queueName, $message['id']);
                            $metrics->recordJobFailed(
                                (hrtime(true) - $jobStartTime) / 1_000_000,
                                $queueName,
                            );
                            unset($slotStarts[$fiberIndex]);
                            $this->releaseInFlight($inFlightKey);
                            CoroutineContext::flush();
                            $this->jobsProcessed++;

                            continue;
                        }
                    } catch (\Throwable $e) {
                        // No attempt started, and the message stays in the PEL for
                        // the pending read. Undo the claim so that read can take it
                        // and a drain does not wait for a job nobody is running.
                        $metrics->recordJobFailed((hrtime(true) - $jobStartTime) / 1_000_000, $queueName);
                        unset($slotStarts[$fiberIndex]);
                        $this->releaseInFlight($inFlightKey);
                        $this->backOffSlot($fiberIndex, 'failed to start a job', $e, 1);

                        continue;
                    }

                    $slotJobs[$fiberIndex] = $streamJob;
                    $this->streamActive[$queueName] = ($this->streamActive[$queueName] ?? 0) + 1;

                    // Locals, not properties: every slot runs its own attempt.
                    $attemptDuration = null; // Set by processMessage().
                    $attemptException = null;
                    $attemptEnded = false;

                    // After the claim above, so a second slot sees this one in
                    // flight, and with $slotStarts set, so a drain waits for it.
                    $quiescentReset->beforeAttempt(count($this->inFlight));

                    // Before JobProcessing: its Context hydrate flushes, and that
                    // must reach this attempt only. Ends after JobAttempted.
                    $slotScopes[$fiberIndex] = AttemptScope::begin();

                    try {
                        $this->processMessage($streamJob, $events, $connectionName, $attemptDuration);
                        $attemptEnded = true;
                        $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                        $metrics->recordJobCompleted($durationMs, $queueName, rescue(fn (): string => $streamJob->resolveName(), null, false));

                        // A job that released itself for a later attempt is
                        // neutral for the breaker, exactly like a retry after
                        // an exception; only a real completion counts.
                        if (! $streamJob->isReleased() && ! $streamJob->hasFailed()) {
                            $circuitBreaker->recordSuccess($queueName);
                        }
                    } catch (\Throwable $e) {
                        $attemptEnded = true;
                        $attemptException = $e;
                        $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                        $metrics->recordJobFailed($durationMs, $queueName, rescue(fn (): string => $streamJob->resolveName(), null, false));

                        try {
                            $this->handleFailure($streamJob, $message, $e, $events, $connectionName, $streams, $deadLetterHandler, $circuitBreaker);
                        } catch (\Throwable $handlingFailure) {
                            // Redis gone during the release or dead-letter write.
                            // An unacknowledged message stays in the PEL for
                            // reclaim; the slot must outlive this either way.
                            fwrite(STDERR, "[torque:worker] Handling a job failure threw: {$handlingFailure->getMessage()}\n");
                            $this->reportSafely($handlingFailure);
                        }
                    } finally {
                        // The command is done: a signal arriving while a
                        // listener below suspends must not interrupt it.
                        // $slotStarts stays set so a drain still waits.
                        unset($slotJobs[$fiberIndex], $slotScopes[$fiberIndex]);

                        // PHP also runs this block when it destroys the Fiber at
                        // shutdown, whatever the attempt was doing. That job was
                        // abandoned, so it reports nothing. After a job's own
                        // exit() it also keeps its marker, since its redelivery
                        // is a crash; the forced exit clears markers itself.
                        // @phpstan-ignore booleanAnd.leftAlwaysTrue (that unwind is not a Throwable, so analysis cannot see it)
                        if ($attemptEnded && ! $this->exiting) {
                            // The attempt ran to an end (completed, released or
                            // failed), so it is not a crash: drop its marker.
                            $this->forgetProcessingMarker($streamJob);
                            $this->dispatchJobAttempted($events, $connectionName, $streamJob, $attemptException, $attemptDuration);

                            // Last, as in the stock worker's runJob(), and before
                            // the scope ends so the reporter sees this job's Context.
                            if ($attemptException !== null && Worker::$reportJobExceptions) {
                                $this->reportSafely($attemptException);
                            }
                        }

                        AttemptScope::end();
                        $quiescentReset->attemptFinished();
                        $this->streamActive[$queueName] = max(0, ($this->streamActive[$queueName] ?? 1) - 1);
                        unset($slotStarts[$fiberIndex]);
                        $this->releaseInFlight($inFlightKey);
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
        // Keeps publishing while draining so the dashboard does not blink to
        // "0 workers" before the exit. An idle worker now exits within a tick, so
        // this normally publishes once more; it still matters for a worker held
        // open by an in-flight job for the full grace.
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
        EventLoop::repeat(1.0, function () use (&$drainStartedAt, &$slotStarts, &$slotJobs, $drainGrace, $metricsPublisher, $metrics, $redisUri, $queues, $consumerGroup, $buildStreamKey) {
            if (! $this->hasReachedLimits()) {
                return;
            }

            if ($drainStartedAt === null) {
                $drainStartedAt = time();
                fwrite(STDERR, "[torque:worker] Limits reached; draining for up to {$drainGrace}s.\n");
            }

            // The reader Fibers stop polling once limits are reached, so the
            // drain is complete as soon as no slot is processing a job. Waiting
            // out the whole window with idle slots kept a rotated worker (and
            // with it a draining master) parked for the full grace period.
            $idle = self::drainComplete($slotStarts);

            if (! $idle && time() - $drainStartedAt < $drainGrace) {
                return;
            }

            try {
                $metricsPublisher->publishWorkerMetrics($this->consumerId, $metrics->snapshot());
                $metricsPublisher->removeWorkerMetrics($this->consumerId);
            } catch (\Throwable) {
                // Best-effort cleanup; do not block the hard exit.
            }

            // A forced exit abandons the in-flight jobs on purpose, the way the
            // stock worker's timeout kill does. Their redelivery is not a crash,
            // so drop their processing markers before going down.
            foreach ($slotJobs as $abandoned) {
                $this->forgetProcessingMarker($abandoned);
            }

            $this->releaseConsumer($redisUri, $queues, $consumerGroup, $buildStreamKey);

            fwrite(STDERR, $idle
                ? "[torque:worker] Drain complete; no jobs in flight, exiting.\n"
                : "[torque:worker] Drain window expired; forcing exit.\n");

            // Set last: the cleanup above suspends, and a job finishing in that
            // gap ran to a real end and still reports it.
            $this->exiting = true;
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

        // The loop stopped or crashed: any Fiber still suspended is destroyed
        // when the process ends, just as on the forced exit.
        $this->exiting = true;
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

                // Reading a PEL lists an entry trimmed from the stream with nil
                // fields; trimmedPendingEntries() reports those.
                $payload = is_array($message[1] ?? null) ? self::payloadField($message[1]) : null;

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
     * The entries of an XREADGROUP reply on a PEL that no longer exist in the
     * stream, such as after StreamQueue::clear(): an id with no fields.
     *
     * Static and pure for unit testing.
     *
     * @return list<array{stream: string, id: string}>
     */
    public static function trimmedPendingEntries(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $trimmed = [];

        foreach ($result as $streamData) {
            foreach ($streamData[1] ?? [] as $message) {
                if (isset($message[0]) && ($message[1] ?? []) === []) {
                    $trimmed[] = ['stream' => (string) $streamData[0], 'id' => (string) $message[0]];
                }
            }
        }

        return $trimmed;
    }

    /**
     * The `payload` value in a flat [field, value, ...] stream entry.
     *
     * @param  array<int, mixed>  $fields
     */
    private static function payloadField(array $fields): ?string
    {
        for ($i = 0, $count = count($fields); $i + 1 < $count; $i += 2) {
            if ((string) $fields[$i] === 'payload') {
                return (string) $fields[$i + 1];
            }
        }

        return null;
    }

    /**
     * Read a pending message (already delivered but not ACKed) from any stream.
     *
     * Uses '0-0' as the ID to fetch messages in this consumer's PEL.
     * Non-blocking: returns null immediately when no pending messages remain.
     *
     * @param  string[]  $queues
     * @param  \Closure(string): string  $buildStreamKey
     * @return array{stream: string, id: string, payload: string}|null
     */
    private function readPendingMessage(
        RedisClient $readerRedis,
        array $queues,
        string $prefix,
        string $consumerGroup,
        \Closure $buildStreamKey,
    ): ?array {
        // The PEL also holds the messages other slots are running and the
        // prefetched ones waiting for a slot. Read enough entries to get past
        // all of them, then take the first one nobody here owns.
        $count = count($this->inFlight) + count($this->finishedDuringRead) + count($this->prefetched) + 1;

        $args = ['GROUP', $consumerGroup, $this->consumerId, 'COUNT', (string) $count, 'STREAMS'];

        foreach ($queues as $queue) {
            $args[] = $buildStreamKey($queue);
        }

        foreach ($queues as $queue) {
            $args[] = '0-0';
        }

        $this->pendingReads++;

        try {
            $result = $readerRedis->execute('XREADGROUP', ...$args);

            // Built after the await: another fiber may have claimed a message
            // while this one was waiting on Redis, or finished one the reply
            // still lists. The caller marks the returned message in flight
            // before its next suspension point.
            $taken = self::takenKeys($this->inFlight, $this->finishedDuringRead, $this->prefetched);
        } finally {
            if (--$this->pendingReads === 0) {
                $this->finishedDuringRead = [];
            }
        }

        // Return before suspending: the caller must mark this message in flight
        // while $taken is still true.
        $message = self::firstUnclaimed($this->parseXreadgroupMessages($result), $taken);

        if ($message !== null) {
            return $message;
        }

        // Nobody can ever run a trimmed entry, so nothing but an ack ends it. One
        // a slot is still running is left alone: that job acknowledges it itself.
        foreach (self::trimmedPendingEntries($result) as $trimmed) {
            if (! isset($taken[$trimmed['stream'].'|'.$trimmed['id']])) {
                $readerRedis->execute('XACK', $trimmed['stream'], $consumerGroup, $trimmed['id']);
            }
        }

        return null;
    }

    /**
     * Drop a message from the in-flight set once its slot is done with it.
     * While a pending re-read is awaiting Redis, remember the message so that
     * read cannot hand it out again from a reply built before the XACK.
     */
    private function releaseInFlight(string $key): void
    {
        unset($this->inFlight[$key]);

        if ($this->pendingReads > 0) {
            $this->finishedDuringRead[$key] = true;
        }
    }

    /**
     * Every `{stream}|{id}` the pending read must skip: messages in flight
     * now, messages finished while the read was awaiting Redis, and the
     * prefetched ones waiting for a slot.
     *
     * Static and pure for unit testing.
     *
     * @param  array<string, true>  $inFlight
     * @param  array<string, true>  $finishedDuringRead
     * @param  list<array{stream: string, id: string, payload: string}>  $prefetched
     * @return array<string, true>
     */
    public static function takenKeys(array $inFlight, array $finishedDuringRead, array $prefetched): array
    {
        $taken = $inFlight + $finishedDuringRead;

        foreach ($prefetched as $queued) {
            $taken[$queued['stream'].'|'.$queued['id']] = true;
        }

        return $taken;
    }

    /**
     * The first pending message that no slot of this worker owns yet.
     *
     * Static and pure for unit testing.
     *
     * @param  list<array{stream: string, id: string, payload: string}>  $messages
     * @param  array<string, true>  $taken  Keys `{stream}|{id}`.
     * @return array{stream: string, id: string, payload: string}|null
     */
    public static function firstUnclaimed(array $messages, array $taken): ?array
    {
        foreach ($messages as $message) {
            if (! isset($taken[$message['stream'].'|'.$message['id']])) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Steal one stale message from another consumer via XAUTOCLAIM.
     *
     * Only claims messages that have been idle longer than the stream's
     * retry_after setting. Returns the claimed message or null.
     *
     * XAUTOCLAIM cannot exclude a consumer, so this worker's own long-running
     * jobs come back too, already claimed and with their idle time reset. Those
     * are skipped, not run: they stay with this consumer, which is running them
     * and acknowledges them when done. The scan then carries on past them, so
     * they cannot hide a dead consumer's message further along the PEL.
     *
     * @param  string[]  $queues
     * @param  array<string, array<string, mixed>>  $streams
     * @param  \Closure(string): string  $buildStreamKey
     * @return array{stream: string, id: string, payload: string}|null
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
                for ($scan = 0; $scan < self::STEAL_SCANS_PER_POLL; $scan++) {
                    // A drain only waits for slots running a job, not for this
                    // scan, so releaseConsumer() may be under way. A claim landing
                    // between its XPENDING and DELCONSUMER drops the entry from
                    // every PEL for good. A read already on the wire can still do
                    // that; only making the release atomic would rule it out.
                    if ($this->hasReachedLimits()) {
                        return null;
                    }

                    // Counts as a pending read: this reply can also list a message
                    // a slot finishes while it is on its way.
                    $this->pendingReads++;

                    try {
                        $result = $readerRedis->execute(
                            'XAUTOCLAIM',
                            $streamKey,
                            $consumerGroup,
                            $this->consumerId,
                            (string) $minIdleMs,
                            $this->stealCursors[$streamKey] ?? '0-0',
                            'COUNT',
                            '1',
                        );

                        $taken = self::takenKeys($this->inFlight, $this->finishedDuringRead, $this->prefetched);
                    } finally {
                        if (--$this->pendingReads === 0) {
                            $this->finishedDuringRead = [];
                        }
                    }

                    $claim = self::parseClaimReply($result, $streamKey);
                    $this->stealCursors[$streamKey] = $claim['cursor'];

                    // Return before suspending: the caller must mark this message
                    // in flight while $taken is still true.
                    $message = self::firstUnclaimed($claim['messages'], $taken);

                    if ($message !== null) {
                        return $message;
                    }

                    // Claimed by the call above, and nobody can ever run it.
                    foreach ($claim['trimmed'] as $trimmedId) {
                        $readerRedis->execute('XACK', $streamKey, $consumerGroup, $trimmedId);
                    }

                    if ($claim['cursor'] === '0-0') {
                        break;
                    }
                }
            } catch (RedisException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Parse an XAUTOCLAIM reply: [nextCursor, [[id, [fields...]], ...], [deletedIds...]].
     *
     * Redis 7 removes an entry that left the stream from the PEL itself and lists
     * its id under deletedIds, so there is nothing to do for those. Below 7 it
     * stays in the PEL and comes back here as nil, without so much as an id if
     * the whole entry is nil. That one still moved to this consumer, so the next
     * pending read lists it and acknowledges it.
     *
     * Static and pure for unit testing.
     *
     * @return array{cursor: string, messages: list<array{stream: string, id: string, payload: string}>, trimmed: list<string>}
     */
    public static function parseClaimReply(mixed $result, string $streamKey): array
    {
        $claim = ['cursor' => '0-0', 'messages' => [], 'trimmed' => []];

        if (! is_array($result)) {
            return $claim;
        }

        $claim['cursor'] = (string) ($result[0] ?? '0-0');

        foreach (is_array($result[1] ?? null) ? $result[1] : [] as $entry) {
            if (! isset($entry[0])) {
                continue;
            }

            $id = (string) $entry[0];
            $fields = $entry[1] ?? [];

            if ($fields === []) {
                $claim['trimmed'][] = $id;

                continue;
            }

            $payload = self::payloadField($fields);

            if ($payload !== null) {
                $claim['messages'][] = ['stream' => $streamKey, 'id' => $id, 'payload' => $payload];
            }
        }

        return $claim;
    }

    /**
     * True when no slot is processing a job, i.e. a draining worker may exit now.
     *
     * @param  array<int, int>  $slotStarts  Fiber index => start timestamp of its current job.
     */
    public static function drainComplete(array $slotStarts): bool
    {
        return $slotStarts === [];
    }

    /**
     * Shorten a worker's lifetime by a random slice of up to $ratio.
     *
     * The master forks its whole fleet inside one second, so an unjittered
     * lifetime expires on every worker in the same second and the entire fleet
     * drains at once (scrpr 2026-08-31: 16 workers, one second apart at boot,
     * one second apart 24 h later). Subtracting a random slice turns that into
     * a rolling rotation.
     *
     * Only subtracts, never adds: {@see MasterProcess}
     * prunes stream consumers idle for longer than a full worker lifetime, and
     * that threshold assumes no worker outlives the configured value.
     */
    public static function jitteredLifetime(int $base, float $ratio): int
    {
        if ($base <= 1 || $ratio <= 0.0) {
            return $base;
        }

        $spread = (int) round($base * min($ratio, 1.0));

        if ($spread <= 0) {
            return $base;
        }

        return max(1, $base - random_int(0, min($spread, $base - 1)));
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
     * receives interrupted($signal). Each command that was told is then
     * announced with a JobInterrupted event carrying the StreamJob, as the
     * stock worker does since 13.31.0 (PR #61412); a command whose
     * interrupted() threw is not announced, matching upstream, where the
     * exception skips the dispatch. Public so the behaviour can be exercised
     * in tests without spinning up the event loop.
     *
     * The signal callback belongs to no attempt, so both run in the job's own
     * scope: a checkpoint job dispatched from interrupted() carries that job's
     * Context, not the ambient one.
     *
     * @param  array<int, StreamJob>  $slotJobs
     * @param  array<int, AttemptScope>  $slotScopes  Keyed like $slotJobs. A slot without one runs in the caller's scope.
     */
    public function notifyInterrupted(
        int $signal,
        array $slotJobs,
        Dispatcher $events,
        string $connectionName,
        ?string $queue,
        array $slotScopes = [],
    ): void {
        try {
            $events->dispatch(new WorkerInterrupted($signal, $connectionName, $queue));
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] WorkerInterrupted dispatch failed: {$e->getMessage()}\n");
        }

        foreach ($slotJobs as $slot => $job) {
            $resolved = $job->getResolvedJob();

            if (! $resolved instanceof CallQueuedHandler) {
                continue;
            }

            $command = $resolved->getRunningCommand();

            if (! $command instanceof Interruptible) {
                continue;
            }

            AttemptScope::within($slotScopes[$slot] ?? AttemptScope::current(), function () use ($command, $signal, $events, $job): void {
                try {
                    $command->interrupted($signal);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "[torque:worker] Job interrupted({$signal}) threw: {$e->getMessage()}\n");

                    return;
                }

                $this->dispatchJobInterrupted($events, $job, $signal);
            });
        }
    }

    /**
     * Announce a job that has just been told about a shutdown signal.
     *
     * The event class exists from laravel/framework 13.31.0 (PR #61412);
     * the `illuminate/*` floor is `^13.34`, so it is always available.
     */
    private function dispatchJobInterrupted(Dispatcher $events, StreamJob $job, int $signal): void
    {
        try {
            $events->dispatch(new JobInterrupted($job->getConnectionName(), $job, $signal));
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] JobInterrupted dispatch failed: {$e->getMessage()}\n");
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
     * Fresh shared pause state for one worker run.
     *
     * The return shape types the fields as the mutable state they are; built
     * inline, analysis would pin them to their initial literal values inside
     * the timer and reader closures.
     *
     * @return \stdClass&object{paused: bool, frameworkPausedQueues: list<string>, pausedQueues: list<string>}
     */
    private static function newPauseState(): \stdClass
    {
        return (object) [
            // Shared pause flag updated by a timer instead of per-Fiber EXISTS calls.
            'paused' => false,
            // Framework-paused queue names (queue:pause / queue:pause --all), kept
            // fresh by the same 2s timer that polls Torque's own pause key.
            'frameworkPausedQueues' => [],
            // The effective per-queue pause set the reader fibers consume: the
            // framework's paused queues plus any stream whose circuit breaker is
            // open. One representation, one code path, whatever the source.
            'pausedQueues' => [],
        ];
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
     *
     * @param  float|null  $duration  Receives the handler's run time in
     *                                milliseconds, also when it throws. Left
     *                                untouched when the attempt ends before fire().
     *
     * @param-out float|null $duration
     */
    // @phpstan-ignore paramOut.unusedType (the null is what a throw before fire() leaves behind)
    private function processMessage(
        StreamJob $job,
        Dispatcher $events,
        string $connectionName,
        &$duration = null,
    ): void {
        $events->dispatch(new JobProcessing($connectionName, $job));

        $this->guardAgainstRepeatedCrashes($job);

        // JobProcessed carries the handler's run time in milliseconds, as the
        // stock worker reports it since laravel/framework 13.34.0: only fire()
        // is timed, not the event dispatch or the stream acknowledgement.
        $startedAt = hrtime(true);

        try {
            $job->fire();
        } finally {
            $duration = round((hrtime(true) - $startedAt) / 1_000_000, 2);
        }

        // If the job handler did not explicitly delete, release, or fail the job,
        // treat it as successfully completed.
        if (! $job->isDeleted() && ! $job->isReleased() && ! $job->hasFailed()) {
            $job->delete();
        }

        $events->dispatch(new JobProcessed($connectionName, $job, $duration));
    }

    /**
     * Announce that an attempt has ended, whatever its outcome.
     *
     * Same semantics as Illuminate\Queue\Worker::process() as of
     * laravel/framework 13.40.0: once per attempt, after the job was released
     * or failed so listeners see its final state, carrying the exception (if
     * any) and the run time in milliseconds, which is null when the attempt
     * ended before fire(). Called from one place only, the reader Fiber's
     * `finally`, so a release or a permanent failure cannot fire it twice.
     *
     * Two exceptions to those semantics. A corrupt payload is discarded before a
     * job object exists, so there is nothing to announce (the stock worker has no
     * such path: it fails the job while decoding it in the attempt). A job the
     * worker abandons by exiting fires nothing, since it never ended.
     *
     * A throwing listener must not take the slot down with it. The stock worker
     * catches this in runJob() and reports it when job exception reporting is on;
     * here it would end the reader Fiber for good.
     */
    private function dispatchJobAttempted(
        Dispatcher $events,
        string $connectionName,
        StreamJob $job,
        ?\Throwable $exception,
        ?float $duration,
    ): void {
        $this->dispatchSafely($events, new JobAttempted($connectionName, $job, $exception, $duration));
    }

    /**
     * Dispatch a worker-lifecycle event whose listeners have no say in the job's
     * outcome, so a throw from one cannot end the slot or skip an acknowledgement.
     * Not for JobProcessing or JobProcessed: a throw from those fails the attempt,
     * as it does in the stock worker.
     *
     * @param  int  $announceEverySeconds  Limit for events that fire without a job,
     *                                     where a broken listener would otherwise
     *                                     flood the log and the error tracker.
     */
    private function dispatchSafely(Dispatcher $events, object $event, int $announceEverySeconds = 0): void
    {
        try {
            $events->dispatch($event);
        } catch (\Throwable $e) {
            if ($announceEverySeconds > 0 && ! $this->mayAnnounce($event::class, $announceEverySeconds)) {
                return;
            }

            $name = class_basename($event);

            fwrite(STDERR, "[torque:worker] {$name} dispatch failed: {$e->getMessage()}\n");

            if (Worker::$reportJobExceptions) {
                $this->reportSafely($e);
            }
        }
    }

    /**
     * Send an exception to the application's handler, once per exception object.
     * A broken handler is logged, never thrown into the slot.
     */
    private function reportSafely(\Throwable $exception): void
    {
        if (isset($this->reported[$exception])) {
            return;
        }

        $this->reported[$exception] = true;

        try {
            app(ExceptionHandler::class)->report($exception);
        } catch (\Throwable $reportFailure) {
            fwrite(STDERR, "[torque:worker] Reporting an exception threw: {$reportFailure->getMessage()}\n");
        }
    }

    /**
     * Whether a recurring failure under this key is due to be announced again.
     */
    private function mayAnnounce(string $key, int $everySeconds): bool
    {
        $now = (int) (hrtime(true) / 1_000_000_000);

        if (isset($this->lastAnnounced[$key]) && $now - $this->lastAnnounced[$key] < $everySeconds) {
            return false;
        }

        $this->lastAnnounced[$key] = $now;

        return true;
    }

    /**
     * Log why a slot is stepping back, report it like the stock worker reports a
     * failed pop (at most once a minute here: every slot hits the same outage),
     * and wait without blocking the other slots.
     */
    private function backOffSlot(int $fiberIndex, string $what, \Throwable $exception, int $failures): void
    {
        $seconds = self::slotBackoffSeconds($failures);

        fwrite(STDERR, "[torque:worker] Fiber {$fiberIndex} {$what}: {$exception->getMessage()}; retrying in {$seconds}s.\n");

        if ($this->mayAnnounce($what, 60)) {
            $this->reportSafely($exception);
        }

        \Fledge\Async\delay($seconds);
    }

    /**
     * The Redis client a slot reads with, which gives up on a reply after
     * `redis.read_timeout` seconds (zero or less waits forever).
     *
     * A read holds its place under `max_concurrency` until it returns, so without
     * a limit one reply lost to a half-open socket stops a stream with a cap of 1
     * in this worker until TCP gives up. `createRedisClient()` cannot set the
     * limit, and it does not read `?read_timeout=` from a URI.
     *
     * Slots never send BLOCK, so a healthy Redis answers at once and a timeout
     * means a dead socket or a server stalled for that long. Both end in the
     * slot's back-off and a new client, which also keeps a long stall from
     * turning into a reconnect loop.
     */
    public function createReaderClient(string $redisUri): RedisClient
    {
        $readTimeout = (float) ($this->config['redis']['read_timeout'] ?? self::DEFAULT_READ_TIMEOUT);

        return new RedisClient(new ReconnectingRedisLink(
            createRedisConnector($redisUri),
            readTimeout: $readTimeout > 0 ? $readTimeout : null,
        ));
    }

    /**
     * Wait before a slot retries after its nth consecutive failure: 0.5s, 1s, 2s,
     * doubling up to 30s. Static and pure for unit testing.
     */
    public static function slotBackoffSeconds(int $failures): float
    {
        return min(30.0, 0.5 * 2 ** min(max($failures, 1) - 1, 10));
    }

    /**
     * Count an unfinished earlier attempt as an exception for jobs marked
     * `#[CountCrashesAsExceptions]` (laravel/framework 13.34.0).
     *
     * Before the handler runs, a processing marker is stored under the job's
     * UUID; every attempt that ends in this process removes it again. Finding
     * the marker already there on pickup therefore means the previous worker
     * died mid-job (OOM, SIGKILL, host loss) and the message came back through
     * the PEL. That counts as one exception against `maxExceptions`, and once
     * the limit is reached the job fails with MaxAttemptsExceededException
     * instead of crashing the next worker too.
     *
     * The marker holds the attempt number. Fibers poll constantly, so a job
     * released with no delay can be picked up by another slot before the
     * releasing slot's cleanup has run; the new attempt sees a marker from a
     * different attempt, takes it over and is not counted as a crash. A crash
     * redelivers the same message, so the attempt number matches.
     *
     * Public so the behaviour can be exercised without the event loop.
     *
     * @throws MaxAttemptsExceededException
     */
    public function guardAgainstRepeatedCrashes(StreamJob $job): void
    {
        if (! $job->countsCrashesAsExceptions()
            || ($uuid = $job->uuid()) === null
            || ($maxExceptions = $job->maxExceptions()) === null
            || ($cache = $this->crashCache()) === null) {
            return;
        }

        $attempt = $job->attempts();

        try {
            $marker = 'job-processing:'.$uuid;
            $expires = now()->addDay();

            if ($cache->add($marker, $attempt, $expires)) {
                return;
            }

            if ((int) $cache->get($marker) !== $attempt) {
                $cache->put($marker, $attempt, $expires);

                return;
            }

            $counter = 'job-exceptions:'.$uuid;

            if (! $cache->has($counter)) {
                $cache->put($counter, 0, $expires);
            }

            $exceeded = (int) $cache->increment($counter) >= $maxExceptions;

            if ($exceeded) {
                $cache->forget($counter);
            }
        } catch (\Throwable $e) {
            // Crash counting is a safety net; a cache outage must not stop
            // the job from running.
            fwrite(STDERR, "[torque:worker] Crash marker check failed for {$uuid}: {$e->getMessage()}\n");

            return;
        }

        if ($exceeded) {
            throw MaxAttemptsExceededException::forJob($job);
        }
    }

    /**
     * Remove the job's processing marker, but only the one this attempt set,
     * so a slower slot finishing an earlier attempt never clears the marker
     * of a newer attempt that is already running elsewhere.
     */
    public function forgetProcessingMarker(StreamJob $job): void
    {
        if (! $job->countsCrashesAsExceptions()
            || ($uuid = $job->uuid()) === null
            || ($cache = $this->crashCache()) === null) {
            return;
        }

        try {
            $marker = 'job-processing:'.$uuid;

            if ((int) $cache->get($marker) === $job->attempts()) {
                $cache->forget($marker);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] Crash marker cleanup failed for {$uuid}: {$e->getMessage()}\n");
        }
    }

    /**
     * The application's default cache store, as the stock worker uses for its
     * crash markers, or null when none can be resolved.
     */
    private function crashCache(): ?CacheRepository
    {
        if ($this->crashCache === null) {
            try {
                $this->crashCache = app(CacheRepository::class);
            } catch (\Throwable) {
                $this->crashCache = false;
            }
        }

        return $this->crashCache ?: null;
    }

    /**
     * Handle a job that threw an exception during processing.
     *
     * Releases the job with exponential backoff if attempts remain, otherwise
     * marks it as permanently failed. A job that already released, deleted or
     * failed itself before throwing keeps that outcome, as in the stock worker's
     * handleJobException(), and is neither added again nor dead-lettered. That
     * includes a release on the last attempt: its copy runs once more and is the
     * one that can still reach the dead-letter stream.
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

        $this->dispatchSafely($events, new JobExceptionOccurred($connectionName, $job, $exception));

        if ($job->isDeleted() || $job->isReleased() || $job->hasFailed()) {
            // delete() and fail() have acknowledged the message by now, and so has
            // a release() that succeeded; after one that threw, the entry stays in
            // the stream and PEL for reclaim. markAsFailed() alone has not, and
            // nothing else would: drop it from the PEL, or it would run again once
            // retry_after has passed.
            if (! $job->isDeleted() && ! $job->isReleased()) {
                $job->delete();
            }

            return;
        }

        $streamConfig = $streams[$queueName] ?? [];
        $maxRetries = (int) ($streamConfig['max_retries'] ?? 3);

        // A dontRetry()/dontRetryWhen() verdict from the application's exception
        // handler (Laravel v13.17.0+) forces immediate failure, no matter how
        // many attempts remain — matching Illuminate\Queue\Worker.
        // So does a job whose crashes already used up its maxExceptions.
        $forceFail = $exception instanceof MaxAttemptsExceededException
            || $this->shouldStopRetries($exception);

        if (! $forceFail && $job->attempts() < $maxRetries) {
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

                if (Worker::$reportJobExceptions) {
                    $this->reportSafely($failError);
                }
            }

            // No manual JobFailed dispatch here: Job::fail() already dispatches it
            // in its finally block, and a second event would double-write the
            // failed-jobs row now that the worker registers the logging listener.

            // Fire a domain event so users can hook in custom notification logic.
            try {
                $decoded = StreamQueue::decodePayload($message['payload'], (string) ($this->config['serializer'] ?? 'json'));
            } catch (\Throwable) {
                $decoded = [];
            }
            $failedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

            $this->dispatchSafely($events, new JobPermanentlyFailed(
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

    /**
     * The queue name for a stream key: the prefix and cluster hash tag removed,
     * so it matches the config keys and the worker's own queue list.
     *
     * Static and pure for unit testing.
     */
    public static function queueNameFromStreamKey(string $streamKey, string $prefix, bool $cluster): string
    {
        $queue = str_starts_with($streamKey, $prefix) ? substr($streamKey, strlen($prefix)) : $streamKey;

        return self::bareQueueName($queue, $cluster);
    }

    /**
     * A queue name without the cluster hash tag wrapping it. A tag around only
     * part of the name stays: it is what getStreamKey() would keep as well, so
     * removing it would point at a different key.
     *
     * Static and pure for unit testing.
     */
    public static function bareQueueName(string $queue, bool $cluster): string
    {
        return $cluster && preg_match('/^\{([^{}]+)\}$/', $queue, $matches) === 1 ? $matches[1] : $queue;
    }

    /**
     * Stream settings keyed by bare queue name. An entry already under the bare
     * name wins over one under its tagged form.
     *
     * Static and pure for unit testing.
     *
     * @param  array<string, array<string, mixed>>  $streams
     * @return array<string, array<string, mixed>>
     */
    public static function bareStreamConfigs(array $streams, bool $cluster): array
    {
        $bare = [];

        foreach ($streams as $queue => $settings) {
            $name = self::bareQueueName((string) $queue, $cluster);
            $bare[$name] = $streams[$name] ?? $settings;
        }

        return $bare;
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
    public static function failerPayload(string $rawBody, ?string $serializer = null): string
    {
        if ($rawBody === '' || $rawBody[0] === '{') {
            return $rawBody;
        }

        return json_encode(StreamQueue::decodePayload($rawBody, $serializer), JSON_THROW_ON_ERROR);
    }
}
