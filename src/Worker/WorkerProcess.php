<?php

declare(strict_types=1);

namespace Webpatser\Torque\Worker;

use Fledge\Async\CancelledException;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Fledge\Async\TimeoutCancellation;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobInterrupted;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleased;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Events\WorkerPausing;
use Illuminate\Queue\Events\WorkerQueuePaused;
use Illuminate\Queue\Events\WorkerQueueResumed;
use Illuminate\Queue\Events\WorkerResuming;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\WorkerStopReason;
use Revolt\EventLoop;
use Webpatser\Torque\Events\JobPermanentlyFailed;
use Webpatser\Torque\Job\AttemptAuthManager;
use Webpatser\Torque\Job\AttemptContextRepository;
use Webpatser\Torque\Job\AttemptDeferredCallbacks;
use Webpatser\Torque\Job\AttemptLogManager;
use Webpatser\Torque\Job\AttemptMemoizedCache;
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
use Webpatser\Torque\Support\DrainSettings;
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

    /** Longest exponential retry delay in seconds: release() loops raise attempts, and with it 2**attempts, to days. */
    private const int MAX_EXPONENTIAL_BACKOFF = 3600;

    /** Seconds an exponential retry stays ahead of retryUntil: the delayed job migration runs once a second. */
    private const int RETRY_DEADLINE_MARGIN = 2;

    /** Seconds a slot's read waits for a reply when `redis.read_timeout` is not set. */
    private const DEFAULT_READ_TIMEOUT = 30.0;

    /** Entries $neverStarted remembers; one that is forgotten is charged at its next read. */
    private const int MAX_NEVER_STARTED = 10000;

    /** Seconds a stopping worker spends handing back entries it never started. */
    private const float RETURN_UNSTARTED_TIMEOUT = 1.0;

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
     * Reads of new messages awaiting their reply, by sequence number. Redis has
     * put what such a read delivers in the PEL, where a pending read can see it,
     * before the slot that will run it knows about it.
     *
     * @var array<int, true>
     */
    private array $newReads = [];

    private int $newReadSequence = 0;

    /**
     * Entries this worker read and never started, keyed `{stream}|{id}`: held back
     * by a popUsing callback, or left in the PEL by a failed start. The value says
     * whether the read that found the entry still owes a redelivery count, so
     * reading it again changes nothing: an entry delivered for the first time is
     * not charged, and one a dead worker had still is, once. Process-local, so a
     * crash loses it and the next worker charges these entries.
     *
     * @var array<string, bool>
     */
    private array $neverStarted = [];

    /**
     * Entries in $neverStarted whose quarantine failed, kept from the hand-back at
     * exit: as a new entry each would be a first delivery for the next worker at once,
     * at every rotation. In the PEL it keeps its delivery count and the pace of
     * retry_after. Forgetting one only means it is handed back, so nothing is lost.
     *
     * @var array<string, bool>
     */
    private array $quarantineFailed = [];

    /**
     * Entries a stopping worker handed back, keyed `{stream}|{id}`. A reply built
     * before the hand-back can still list one, and running it would run it twice:
     * it now exists under a new id, for another worker.
     *
     * @var array<string, true>
     */
    private array $handedBack = [];

    /** Timed out attempts being settled right now. */
    private int $settlesRunning = 0;

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

    /** Why the worker is going down; the first reason to be set wins. */
    private ?WorkerStopReason $stopReason = null;

    /** Set by the first requested stop that is not the memory limit, even after a memory stop. */
    private bool $stopHasGrace = false;

    /** True while a drain tick runs, which can suspend, so the next tick stays out. */
    private bool $drainTickRunning = false;

    /** Set before WorkerStopping is dispatched, so the event fires once per process. */
    private bool $stoppingAnnounced = false;

    /** Unix time the last job finished, for WorkerStopping. */
    private ?int $lastJobProcessedAt = null;

    /** The lowest period the `intervals` config can give a timer. */
    private const float MIN_INTERVAL_SECONDS = 0.05;

    /** hrtime seconds of the last WorkerIdle, so idle slots do not flood listeners. */
    private ?float $lastIdleAt = null;

    /** Limits — set once in run(), checked by timers to know when to cancel. */
    private int $maxJobs = 10_000;

    private int $maxLifetime = 3600;

    private int $startTime = 0;

    /** Memory limit in MB; 0 is off. */
    private int $memoryLimit = 0;

    /** True while `artisan down` is active and `force` is off; refreshed once a second. */
    private bool $down = false;

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
     * The dead-letter `reason` of an exception that ends a job, where it has one.
     *
     * @var \WeakMap<\Throwable, string>
     */
    private \WeakMap $failureReasons;

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
        $this->failureReasons = new \WeakMap;
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

        // Context, defer() callbacks, log context, guards and Cache::memo belong to
        // the attempt using them; the framework's shared instances assume one job
        // at a time.
        AttemptContextRepository::install(app());
        AttemptDeferredCallbacks::install(app());
        AttemptLogManager::install(app());
        AttemptAuthManager::install(app());
        AttemptMemoizedCache::install(app());

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

        $this->announceLimitSettings($streams);
        $this->announceTimeoutSettings($streams);

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

        $this->maxJobs = $maxJobs;
        $this->maxLifetime = $maxLifetime;
        $this->memoryLimit = max(0, (int) ($this->config['memory_limit'] ?? 512));
        $this->announceMemorySettings();
        $this->announceDrainGrace();
        $this->startTime = time();

        // Maintenance mode is read once a second for the whole worker, not per
        // slot per poll: the file driver would hit the disk 50 times a poll.
        // Read once now too, so no slot claims a job in the first second.
        $timerPeriod = fn (string $key, float $default): float => self::intervalSeconds($this->config, $key, $default);
        $pausedPollSeconds = $timerPeriod('paused_poll_seconds', 1.0);
        $force = (bool) ($this->config['force'] ?? false);
        $this->refreshDownState($force);
        EventLoop::repeat($timerPeriod('down_check_seconds', 1.0), fn () => $this->refreshDownState($force));
        $startTime = $this->startTime;

        // Built once for the event payloads. `timeout` carries the stock default:
        // Torque's own come from the job and stream. `memory` is 0 while the limit
        // is off.
        $workerOptions = new WorkerOptions(
            name: 'torque',
            backoff: 0,
            memory: $this->memoryLimit,
            timeout: 60,
            sleep: max(1, (int) ceil($pollInterval)),
            maxTries: max(1, (int) ($streams[$queues[0] ?? 'default']['max_retries'] ?? 3)),
            force: $force,
            maxJobs: $maxJobs,
            maxTime: $maxLifetime,
        );

        $this->dispatchSafely($events, new WorkerStarting($connectionName, $queues[0] ?? 'default', $workerOptions));

        fwrite(STDERR, "[torque:worker] Setup complete, entering event loop\n");

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
            $this->requestStop(WorkerStopReason::Interrupted);
            $this->notifyInterrupted($signal, $slotJobs, $events, $connectionName, $primaryQueue, $slotScopes);
        };

        EventLoop::onSignal(SIGTERM, fn () => $shutdownHandler(SIGTERM));
        EventLoop::onSignal(SIGINT, fn () => $shutdownHandler(SIGINT));

        $masterPidForPause = posix_getppid();
        $pauseFetchFailed = false;
        EventLoop::repeat($timerPeriod('pause_check_seconds', 2.0), function () use ($redisPool, $prefix, $pauseState, $events, $connectionName, $primaryQueue, $masterPidForPause, $queueManager, $queues, $circuitBreaker, &$pauseFetchFailed) {
            // Framework pause state (queue:pause / queue:pause --all) lives in
            // the cache store, read synchronously outside the pool callback so
            // pool-connection hold time is unchanged. On a failed read we keep
            // the last known set: a cache restart must not silently resume a
            // deliberately paused fleet.
            $fetched = self::fetchFrameworkPausedQueues($queueManager, $connectionName, $queues);

            if ($fetched !== null) {
                $queueDiff = self::diffPausedQueues($pauseState->frameworkPausedQueues, $fetched);
                $pauseState->frameworkPausedQueues = $fetched;
                $pauseFetchFailed = false;

                // Framework pauses only: a tripped circuit breaker has its own
                // events. State is updated first, so a suspending listener
                // cannot make the next tick announce the same change again.
                foreach ($queueDiff['paused'] as $pausedQueue) {
                    $this->dispatchSafely($events, new WorkerQueuePaused($connectionName, $pausedQueue), announceEverySeconds: 60);
                }

                foreach ($queueDiff['resumed'] as $resumedQueue) {
                    $this->dispatchSafely($events, new WorkerQueueResumed($connectionName, $resumedQueue), announceEverySeconds: 60);
                }
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
        EventLoop::repeat($timerPeriod('watchdog_check_seconds', 2.0), function () use ($masterPid, &$restartBaseline, &$restartBaselineKnown, &$restartReadFailed) {
            if (! $this->stopHasGrace && posix_getppid() !== $masterPid) {
                fwrite(STDERR, "[torque:worker] Master (PID {$masterPid}) is gone, shutting down orphaned worker\n");
                $this->requestStop(WorkerStopReason::Interrupted);
            }

            // queue:restart: the same timestamp comparison as the stock worker,
            // checked here so an idle worker notices too. A change drains out
            // through the normal stop path (exit 0, which the master respawns).
            if ($this->stopHasGrace || ! Worker::$restartable || $this->restartChecking
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
                } elseif ($restartBaseline !== null && $current === null) {
                    // cache:clear or an eviction, not a deploy. Unlike the stock
                    // worker this does not drain: a clear would otherwise restart
                    // the whole fleet. The next queue:restart still differs from null.
                    $restartBaseline = null;
                    fwrite(STDERR, "[torque:worker] queue:restart key is gone (cache:clear or eviction), keeping the worker running and watching for the next queue:restart.\n");
                } elseif ($current != $restartBaseline) {
                    fwrite(STDERR, "[torque:worker] Received queue:restart signal, draining and exiting.\n");
                    $this->requestStop(WorkerStopReason::ReceivedRestartSignal);
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

        // Blocked-loop heartbeat for the master's watchdog. A timer on purpose: it
        // stops exactly when the loop does. The first beat comes before any slot
        // can claim a job, so a job blocking the very first tick is still seen.
        if (MasterProcess::loopStallKillSeconds($this->config) > 0) {
            $heartbeatFile = MasterProcess::heartbeatPath((int) getmypid());

            register_shutdown_function(static function () use ($heartbeatFile): void {
                @unlink($heartbeatFile);
            });

            $this->writeHeartbeat($heartbeatFile);
            EventLoop::repeat(1.0, fn () => $this->writeHeartbeat($heartbeatFile));
        }

        // Spawn N reader Fibers (one per concurrency slot).
        // Each Fiber loops: poll (non-blocking) -> process -> yield -> repeat.
        // Non-blocking XREADGROUP avoids reliance on the async client's BLOCK
        // notification chain, which stalls with many simultaneous connections.
        fwrite(STDERR, "[torque:worker] Starting {$concurrency} reader Fibers\n");

        for ($i = 0; $i < $concurrency; $i++) {
            $fiberIndex = $i;
            (void) async(function () use (
                $fiberIndex,
                $pausedPollSeconds,
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
                $workerOptions,
                &$slotStarts,
                &$slotJobs,
                &$slotScopes,
            ) {
                // Stagger Fiber startup to spread polling across time.
                \Fledge\Async\delay($fiberIndex * ($pollInterval / $concurrency));

                $fiberRedis = $this->createReaderClient($redisUri);
                $loopCount = 0;
                $readFailures = 0;
                $recheckPending = false;

                while (true) {
                    if ($this->hasReachedLimits()) {
                        return;
                    }

                    // Like a paused worker: no Looping, no polling, no claiming.
                    if ($this->down || $pauseState->paused) {
                        \Fledge\Async\delay($pausedPollSeconds);

                        continue;
                    }

                    // Fires on every poll of every slot, so a broken listener is
                    // announced once a minute, not once per poll. A listener
                    // returning false skips this poll, as in the stock worker.
                    if ($this->untilSafely($events, new Looping($connectionName, $queues[0] ?? 'default', $workerOptions), announceEverySeconds: 60)) {
                        \Fledge\Async\delay($pollInterval);

                        continue;
                    }

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

                    $prebuilt = null;
                    $redelivered = false;

                    try {
                        try {
                            // Inside the reservation above: a listener may suspend.
                            // Fires on every read of every slot, like Looping, so a
                            // broken listener is announced once a minute.
                            $this->dispatchSafely($events, new JobPopping($connectionName, implode(',', $pollQueues)), announceEverySeconds: 60);

                            // The slot's read: new messages, then the pending list, then
                            // dead consumers' messages. Non-blocking.
                            $readChain = function (array $readQueues) use ($fiberRedis, $prefix, $consumerGroup, $buildStreamKey, $streams, $loopCount, &$redelivered, &$recheckPending): ?array {
                                $found = $this->readNextMessage($fiberRedis, $readQueues, $prefix, $consumerGroup, $buildStreamKey);

                                // Whatever the reads below find was delivered before.
                                $redelivered = $found === null;

                                // Periodically re-check PEL for any orphaned messages (~every 25s),
                                // and right after a popUsing callback handed a message back.
                                if ($found === null && ($loopCount % 50 === 0 || $recheckPending)) {
                                    $found = $this->readPendingMessage($fiberRedis, $readQueues, $prefix, $consumerGroup, $buildStreamKey);

                                    if ($found === null) {
                                        $recheckPending = false;
                                    }
                                }

                                // Drain pending on first iteration (crash recovery).
                                if ($found === null && $loopCount === 1) {
                                    $found = $this->readPendingMessage($fiberRedis, $readQueues, $prefix, $consumerGroup, $buildStreamKey);
                                }

                                // Still nothing: steal from dead consumers.
                                if ($found === null) {
                                    $found = $this->stealMessage($fiberRedis, $readQueues, $prefix, $consumerGroup, $streams, $buildStreamKey);
                                }

                                // This worker has had it before and not started it,
                                // so what that first read found still stands. A steal
                                // from another consumer has already dropped the entry.
                                if ($found !== null) {
                                    $redelivered = $this->neverStarted[$found['stream'].'|'.$found['id']] ?? $redelivered;
                                }

                                return $found;
                            };

                            $popCallback = PopCallbacks::for($workerOptions->name);

                            if ($popCallback === null) {
                                $message = $readChain($pollQueues);
                            } else {
                                // Queue::popUsing(): the callback decides the job. It is
                                // handed this slot's read for the queues it names (only
                                // ones this poll may read, so max_concurrency holds).
                                $handed = [];

                                $popJob = function (string $names) use (&$handed, &$redelivered, $pollQueues, $readChain, $prefix, $cluster, $streamQueue, $connectionName): ?StreamJob {
                                    $wanted = array_values(array_intersect(explode(',', $names), $pollQueues));

                                    if ($wanted === []) {
                                        return null;
                                    }

                                    $read = $readChain($wanted);

                                    if ($read === null) {
                                        return null;
                                    }

                                    // Claimed before any suspension, as for an ordinary read;
                                    // given back below if the callback does not use it.
                                    $key = $read['stream'].'|'.$read['id'];
                                    $this->inFlight[$key] = true;

                                    $job = null;
                                    $wire = (string) ($this->config['serializer'] ?? 'json');

                                    if (StreamQueue::isValidPayload($read['payload'], $wire)) {
                                        try {
                                            $job = new StreamJob(
                                                container: app(),
                                                streamQueue: $streamQueue,
                                                rawBody: $read['payload'],
                                                messageId: $read['id'],
                                                connectionName: $connectionName,
                                                queue: self::queueNameFromStreamKey($read['stream'], $prefix, $cluster),
                                            );
                                        } catch (\Throwable) {
                                            // Left to the ordinary path below, which quarantines it.
                                        }
                                    }

                                    $handed[] = ['job' => $job, 'message' => $read, 'redelivered' => $redelivered, 'key' => $key];

                                    return $job;
                                };

                                try {
                                    $returned = $popCallback($popJob, implode(',', $pollQueues));
                                } catch (\Throwable $callbackFailure) {
                                    foreach ($handed as $entry) {
                                        $this->releaseInFlight($entry['key']);
                                        $this->neverStarted = self::rememberUnstarted($this->neverStarted, $entry['key'], $entry['redelivered']);
                                    }

                                    $recheckPending = $handed !== [];

                                    throw $callbackFailure;
                                }

                                $chosen = null;

                                if ($returned !== null) {
                                    foreach ($handed as $index => $entry) {
                                        if ($entry['job'] !== null && $entry['job'] === $returned) {
                                            $chosen = $index;

                                            break;
                                        }
                                    }

                                    if ($chosen === null) {
                                        fwrite(STDERR, '[torque:worker] WARN: the popUsing callback returned a job of class '.get_debug_type($returned)." that this worker did not read; skipping it.\n");
                                    }
                                }

                                $message = null;

                                foreach ($handed as $index => $entry) {
                                    if ($index === $chosen) {
                                        $message = $entry['message'];
                                        $prebuilt = $entry['job'];
                                        $redelivered = $entry['redelivered'];
                                    } elseif ($entry['job'] === null && $returned === null && $message === null) {
                                        // Not decodable, so no job to hand out: the
                                        // ordinary path quarantines it.
                                        $message = $entry['message'];
                                        $redelivered = $entry['redelivered'];
                                    } else {
                                        // Held back: it stays pending and is read again soon.
                                        $this->releaseInFlight($entry['key']);
                                        $this->neverStarted = self::rememberUnstarted($this->neverStarted, $entry['key'], $entry['redelivered']);
                                        $recheckPending = true;
                                    }
                                }
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
                        // Every slot polls on its own, so idleness is announced
                        // only when no job runs anywhere, and at most once per
                        // poll interval (one second at least) for the whole worker.
                        if ($slotStarts === [] && $this->prefetched === [] && $this->idleAnnouncementDue($pollInterval)) {
                            $this->dispatchSafely($events, new WorkerIdle($connectionName, $queues[0] ?? 'default', $workerOptions), announceEverySeconds: 60);
                        }

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
                    $redeliveryOwed = $redelivered;
                    $quarantineReason = null;

                    try {
                        $streamJob = null;

                        // Only now: this suspends, and the claim above is what keeps
                        // another slot's pending read off the message. A throw from
                        // the count leaves it in the PEL, not run and still owed.
                        $redeliveries = $redelivered && $this->enforcesStrictAttempts()
                            ? $streamQueue->countRedelivery($queueName, $message['id'])
                            : 0;

                        if ($redeliveries > 0) {
                            $redeliveryOwed = false;
                        }

                        if (StreamQueue::isValidPayload($message['payload'], $wireSerializer)) {
                            try {
                                $streamJob = $prebuilt ?? new StreamJob(
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
                            unset($slotStarts[$fiberIndex], $this->neverStarted[$inFlightKey], $this->quarantineFailed[$inFlightKey]);
                            $this->releaseInFlight($inFlightKey);
                            CoroutineContext::flush();
                            $this->jobsProcessed++;
                            $this->lastJobProcessedAt = time();

                            continue;
                        }
                    } catch (\Throwable $e) {
                        // No attempt started, and the message stays in the PEL for
                        // the pending read. Undo the claim so that read can take it
                        // and a drain does not wait for a job nobody is running.
                        $metrics->recordJobFailed((hrtime(true) - $jobStartTime) / 1_000_000, $queueName);
                        unset($slotStarts[$fiberIndex]);
                        $this->releaseInFlight($inFlightKey);
                        $this->neverStarted = self::rememberUnstarted($this->neverStarted, $inFlightKey, $redeliveryOwed);

                        if ($quarantineReason !== null) {
                            $this->quarantineFailed = self::rememberUnstarted($this->quarantineFailed, $inFlightKey, true);
                        }

                        $this->backOffSlot($fiberIndex, 'failed to start a job', $e, 1);

                        continue;
                    }

                    unset($this->neverStarted[$inFlightKey]);
                    $slotJobs[$fiberIndex] = $streamJob;
                    $this->streamActive[$queueName] = ($this->streamActive[$queueName] ?? 0) + 1;

                    // Locals, not properties: every slot runs its own attempt.
                    $attemptDuration = null; // Set by processMessage().
                    $attemptException = null;
                    $attemptEnded = false;

                    // After the claim above, so a second slot sees this one in
                    // flight, and with $slotStarts set, so a drain waits for it.
                    $quiescentReset->beforeAttempt(count($this->inFlight));

                    // Before JobPopped and JobProcessing: what a listener adds to
                    // Context, and the flush of JobProcessing's hydrate, must reach
                    // this attempt only. Ends after JobAttempted.
                    $slotScopes[$fiberIndex] = AttemptScope::begin();

                    // Runs in a timer's Fiber, while this one stays suspended in the
                    // job. It takes the attempt over: the bookkeeping that this
                    // slot does at the end of an attempt happens there instead,
                    // except the slot's own scope and its place in streamActive.
                    // The place stays taken until the worker exits, which holds
                    // nobody up: the worker is stopping and claims no more jobs.
                    $onTimeout = function (int $timeout, int $firedAt) use ($fiberIndex, $streamJob, $message, $jobStartTime, $inFlightKey, $events, $connectionName, $streams, $deadLetterHandler, $circuitBreaker, $metrics, $quiescentReset, &$slotStarts, &$slotJobs, &$slotScopes): void {
                        // The attempt cancels this timer as it ends, so this is
                        // only for a timer already on its way in the same loop tick.
                        if ($this->exiting || ($slotJobs[$fiberIndex] ?? null) !== $streamJob) {
                            return;
                        }

                        $scope = $slotScopes[$fiberIndex];

                        // All before anything can suspend. Stopping first means a
                        // listener or an interrupted() that never returns still
                        // ends in the drain's forced exit. $slotStarts stays set,
                        // so that exit waits for the release below; a signal
                        // from now on no longer interrupts this job.
                        $streamJob->abandon(fn (): int => count($this->inFlight));
                        unset($slotJobs[$fiberIndex], $slotScopes[$fiberIndex]);
                        $this->settlesRunning++;
                        $this->requestStop(WorkerStopReason::TimedOut);

                        fwrite(STDERR, "[torque:worker] Job {$streamJob->getMessageId()} timed out after {$timeout}s; abandoning it and rotating this worker.\n");

                        try {
                            AttemptScope::within($scope, fn () => $this->settleTimedOutAttempt($streamJob, $message, $timeout, $firedAt, $jobStartTime, $events, $connectionName, $streams[$streamJob->getQueue()] ?? [], $deadLetterHandler, $circuitBreaker, $metrics));
                        } finally {
                            $this->settlesRunning--;
                            $streamJob->abandonmentSettled();
                            $quiescentReset->attemptFinished();
                            unset($slotStarts[$fiberIndex]);
                            $this->releaseInFlight($inFlightKey);
                            $this->jobsProcessed++;
                            $this->lastJobProcessedAt = time();
                        }
                    };

                    try {
                        // Safe, unlike the stock worker: there a throw here skips
                        // the job until retry_after. As in stock, the hydrate at
                        // JobProcessing drops Context added by a listener of this.
                        $this->dispatchSafely($events, new JobPopped($connectionName, $streamJob));

                        $this->processMessage($streamJob, $events, $connectionName, $attemptDuration, $streams, $onTimeout, $redeliveries);
                        $attemptEnded = true;

                        if (! $streamJob->isAbandoned()) {
                            $durationMs = (hrtime(true) - $jobStartTime) / 1_000_000;
                            $metrics->recordJobCompleted($durationMs, $queueName, rescue(fn (): string => $streamJob->resolveName(), null, false));

                            // A job that released itself for a later attempt is
                            // neutral for the breaker, exactly like a retry after
                            // an exception; only a real completion counts.
                            if (! $streamJob->isReleased() && ! $streamJob->hasFailed()) {
                                $circuitBreaker->recordSuccess($queueName);
                            }
                        }
                    } catch (\Throwable $e) {
                        $attemptEnded = true;

                        if ($streamJob->isAbandoned()) {
                            // Its attempt has been settled, so this is no outcome.
                            fwrite(STDERR, "[torque:worker] Job {$streamJob->getMessageId()} threw after it timed out: {$e->getMessage()}\n");
                        } else {
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
                        }
                    } finally {
                        // An abandoned attempt was reported and counted by $onTimeout,
                        // which may be running still. This slot only lets go of it.
                        $abandoned = $streamJob->isAbandoned();

                        if (! $abandoned) {
                            // The command is done: a signal arriving while a
                            // listener below suspends must not interrupt it.
                            // $slotStarts stays set so a drain still waits.
                            unset($slotJobs[$fiberIndex], $slotScopes[$fiberIndex]);
                        }

                        // PHP also runs this block when it destroys the Fiber at
                        // shutdown, whatever the attempt was doing. That job was
                        // abandoned, so it reports nothing. After a job's own
                        // exit() it also keeps its marker, since its redelivery
                        // is a crash; the forced exit clears markers itself.
                        // @phpstan-ignore booleanAnd.leftAlwaysTrue (that unwind is not a Throwable, so analysis cannot see it)
                        if ($attemptEnded && ! $abandoned && ! $this->exiting) {
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

                        if (! $abandoned) {
                            $quiescentReset->attemptFinished();
                            $this->streamActive[$queueName] = max(0, ($this->streamActive[$queueName] ?? 1) - 1);
                            unset($slotStarts[$fiberIndex]);
                            $this->releaseInFlight($inFlightKey);
                            $this->jobsProcessed++;
                            $this->lastJobProcessedAt = time();
                        }

                        CoroutineContext::flush();
                    }
                }
            });
        }

        // Delayed job migration timer: checks every second for matured delayed jobs.
        // Keeps running during the drain window so delayed jobs don't pile up while
        // a hung Fiber prevents the worker from exiting cleanly.
        EventLoop::repeat($timerPeriod('delayed_check_seconds', 1.0), function () use ($redisPool, $queues, $buildStreamKey) {
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
        //
        // The memory limit alone has no such deadline: as in the stock worker,
        // which exits between jobs, the running jobs finish first. Forcing them
        // out would rerun them in a new worker with their attempts unchanged, and
        // the same memory growth would abandon them again, forever.
        $drainGrace = DrainSettings::graceSeconds($this->config);
        $drainStartedAt = null;
        EventLoop::repeat($timerPeriod('stop_check_seconds', 1.0), function () use (&$drainStartedAt, &$slotStarts, &$slotJobs, $drainGrace, $metricsPublisher, $metrics, $redisUri, $queues, $consumerGroup, $buildStreamKey, $events, $workerOptions, $connectionName, $primaryQueue, $streamQueue) {
            // Revolt re-enables a repeat timer before invoking it, so a tick that
            // suspends in a listener or in the cleanup would be overtaken by the
            // next one, which could exit underneath it.
            if ($this->drainTickRunning) {
                return;
            }

            $this->drainTickRunning = true;

            try {
                // Real usage, as the stock worker measures it. Slots stop polling
                // once the stop is requested.
                if ($this->memoryLimit > 0 && ! $this->stopRequested
                    && memory_get_usage(true) / 1024 / 1024 >= $this->memoryLimit) {
                    fwrite(STDERR, "[torque:worker] Memory limit of {$this->memoryLimit} MB reached; claiming no more jobs, exiting once in-flight jobs finish.\n");
                    $this->requestStop(WorkerStopReason::MaxMemoryExceeded);
                }

                if (! $this->hasReachedLimits()) {
                    return;
                }

                // The first reason wins, including over a signal during the drain.
                $this->noteLimitReason();

                if ($drainStartedAt === null && $this->drainHasDeadline()) {
                    $drainStartedAt = time();
                    fwrite(STDERR, "[torque:worker] Limits reached; draining for up to {$drainGrace}s.\n");
                }

                // The reader Fibers stop polling once limits are reached, so the
                // drain is complete as soon as no slot is processing a job. Waiting
                // out the whole window with idle slots kept a rotated worker (and
                // with it a draining master) parked for the full grace period. A
                // read on its way can still deliver something, which would then be
                // charged; a memory stop has no deadline, so it does not wait for one.
                $readOnItsWay = ($this->newReads !== [] || $this->pendingReads > 0) && $drainStartedAt !== null;

                // A settle always gets a moment, also at a grace of zero: exiting in
                // the middle of one would leave the attempt without its outcome.
                $grace = $this->settlesRunning > 0 ? max(1, $drainGrace) : $drainGrace;

                if ((! self::drainComplete($slotStarts) || $readOnItsWay)
                    && ($drainStartedAt === null || time() - $drainStartedAt < $grace)) {
                    return;
                }

                // As in stock, not while jobs run, except for those a forced exit
                // abandons. A listener may suspend: the guard above lets it finish.
                $this->announceStopping($events, $workerOptions, $connectionName, $primaryQueue);

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

                // Before the release: a consumer with pending entries is kept.
                $this->returnUnstartedEntries($streamQueue);
                $this->releaseConsumer($redisUri, $queues, $consumerGroup, $buildStreamKey);

                fwrite(STDERR, self::drainComplete($slotStarts)
                    ? "[torque:worker] Drain complete; no jobs in flight, exiting.\n"
                    : "[torque:worker] Drain window expired; forcing exit.\n");

                // Set last: the cleanup above suspends, and a job finishing in that
                // gap ran to a real end and still reports it.
                $this->exiting = true;
                exit(self::stopStatusFor($this->stopReason));
            } finally {
                $this->drainTickRunning = false;
            }
        });

        $crashed = false;

        try {
            EventLoop::run();
        } catch (\Throwable $e) {
            $crashed = true;
            fwrite(STDERR, "[torque:worker] EventLoop crashed: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
            fwrite(STDERR, $e->getTraceAsString()."\n");
        }

        // A no-op after the drain timer announced; here only for a loop that ended on its own.
        $this->announceStopping($events, $workerOptions, $connectionName, $primaryQueue, $crashed ? Worker::EXIT_ERROR : null);

        // Remove this worker's metrics key from Redis so it doesn't linger as a ghost.
        try {
            $metricsPublisher->removeWorkerMetrics($this->consumerId);
        } catch (\Throwable) {
            // Best-effort cleanup — don't prevent shutdown.
        }

        $this->returnUnstartedEntries($streamQueue);
        $this->releaseConsumer($redisUri, $queues, $consumerGroup, $buildStreamKey);

        // The loop stopped or crashed: any Fiber still suspended is destroyed
        // when the process ends, just as on the forced exit.
        $this->exiting = true;
        $this->isRunning = false;
    }

    /**
     * Refresh this worker's heartbeat file for the master's watchdog.
     *
     * A healthy worker must never look stalled because of a disk or permission
     * problem, so a heartbeat that cannot be refreshed is removed: the master
     * reads a missing file as "no heartbeat yet", never as a stall. Group-writable
     * from birth, like the PID file, to survive a deploy-time chown.
     */
    private function writeHeartbeat(string $path): void
    {
        $previousUmask = umask(0002);

        try {
            if (@touch($path)) {
                return;
            }

            if (! is_dir(dirname($path)) && @mkdir(dirname($path), 0775, true) && @touch($path)) {
                return;
            }
        } finally {
            umask($previousUmask);
        }

        @unlink($path);

        if ($this->mayAnnounce('heartbeat', 60)) {
            fwrite(STDERR, "[torque:worker] WARN cannot write heartbeat file {$path}; the blocked loop watchdog does not cover this worker.\n");
        }
    }

    /**
     * Hand back what this stopping worker read and will never start: prefetched
     * entries and entries in $neverStarted that owe no count. Left in the PEL they
     * would wait out retry_after and then be charged as redeliveries.
     *
     * In every mode: it costs a round trip only when there is something to hand
     * back, and without strict_attempts it still saves those entries the wait.
     * Bounded, because a forced exit comes after the grace already ran out; a
     * Redis that does not answer leaves the entries to be stolen and charged.
     */
    private function returnUnstartedEntries(StreamQueue $streamQueue): void
    {
        $byStream = [];

        foreach ($this->prefetched as $message) {
            $byStream[$message['stream']][] = $message['id'];
        }

        foreach ($this->neverStarted as $key => $owed) {
            if (! $owed && ! isset($this->inFlight[$key]) && ! isset($this->quarantineFailed[$key])) {
                $separator = (int) strrpos($key, '|');
                $byStream[substr($key, 0, $separator)][] = substr($key, $separator + 1);
            }
        }

        // First, so no slot can take one of these while the script is on its way.
        $this->prefetched = [];
        $this->neverStarted = [];

        foreach ($byStream as $streamKey => $ids) {
            foreach ($ids as $id) {
                $this->handedBack[$streamKey.'|'.$id] = true;
            }
        }

        if ($byStream === []) {
            return;
        }

        try {
            async(function () use ($byStream, $streamQueue): void {
                foreach ($byStream as $streamKey => $ids) {
                    // One stream's failure must not cost the others their hand-back.
                    try {
                        $streamQueue->returnUnstarted($streamKey, $this->consumerId, $ids);
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "[torque:worker] WARN could not hand back entries of {$streamKey} that never started: {$e->getMessage()}\n");
                    }
                }
            })->await(new TimeoutCancellation(self::RETURN_UNSTARTED_TIMEOUT));
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] WARN could not hand back entries that never started: {$e->getMessage()}\n");
        }
    }

    /**
     * Remember an entry that was read and not started, forgetting the oldest once
     * full: a popUsing callback can hold back a whole stream.
     *
     * Static and pure for unit testing.
     *
     * @param  array<string, bool>  $set
     * @return array<string, bool>
     */
    public static function rememberUnstarted(array $set, string $key, bool $owed, int $limit = self::MAX_NEVER_STARTED): array
    {
        unset($set[$key]);
        $set[$key] = $owed;

        return count($set) > $limit ? array_slice($set, -$limit, preserve_keys: true) : $set;
    }

    /**
     * Delete this worker's consumer from every stream it served.
     *
     * Every worker start mints a fresh `{host}-{pid}-{hex}` consumer name, so
     * without this each rotation leaves one behind forever (scrpr 2026-08-27:
     * 124k consumers per stream). A consumer with pending entries is left
     * alone: XGROUP DELCONSUMER discards its PEL, and those entries must stay
     * claimable by the next worker's XAUTOCLAIM instead. The check and the delete
     * are one script, so a delivery still on its way cannot land between them.
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
                $redis->execute('EVAL', StreamQueue::LUA_DELETE_IDLE_CONSUMER, '1', $streamKey, $consumerGroup, $this->consumerId);
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
        $count = count($this->inFlight) + count($this->finishedDuringRead) + count($this->prefetched) + count($this->handedBack) + 1;

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

            // This reply can list what a read of new messages delivered while the
            // slot behind that read has yet to see it: running it here would run
            // it twice, and count a first delivery as a redelivery. Only the reads
            // on their way right now matter: a later one cannot deliver an entry
            // this reply already holds, so sustained reads cannot starve this one.
            // Each ends within the read timeout. With none set, one on a half-open
            // socket may never end, so this wait has a limit of its own. Past it the
            // reply cannot be trusted, and finding nothing beats running one twice.
            $outstanding = $this->newReads;
            $waitUntil = hrtime(true) + (int) (self::outstandingReadWait((float) ($this->config['redis']['read_timeout'] ?? self::DEFAULT_READ_TIMEOUT)) * 1_000_000_000);

            while (array_intersect_key($outstanding, $this->newReads) !== []) {
                if (hrtime(true) >= $waitUntil) {
                    if ($this->mayAnnounce('outstanding-read', 60)) {
                        fwrite(STDERR, "[torque:worker] WARN a read of new messages has not ended; skipping this read of the pending list.\n");
                    }

                    return null;
                }

                \Fledge\Async\delay(0.001);
            }

            // Built after the await: another fiber may have claimed a message
            // while this one was waiting on Redis, or finished one the reply
            // still lists. The caller marks the returned message in flight
            // before its next suspension point.
            $taken = self::takenKeys($this->inFlight, $this->finishedDuringRead, $this->prefetched, $this->handedBack);
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
                $this->forgetRedeliveries($readerRedis, $trimmed['stream'], $trimmed['id']);
            }
        }

        return null;
    }

    /**
     * Drop the redelivery count of an entry acknowledged after it was trimmed from
     * the stream. Under strict_attempts only: without it there is no extra round
     * trip, and the housekeeper removes any field left over from an earlier config.
     */
    private function forgetRedeliveries(RedisClient $redis, string $streamKey, string $id): void
    {
        if ($this->enforcesStrictAttempts()) {
            $redis->execute('HDEL', $streamKey.StreamQueue::REDELIVERIES_SUFFIX, $id);
        }
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
     * now, messages finished while the read was awaiting Redis, the prefetched
     * ones waiting for a slot, and those a stopping worker handed back.
     *
     * Static and pure for unit testing.
     *
     * @param  array<string, true>  $inFlight
     * @param  array<string, true>  $finishedDuringRead
     * @param  list<array{stream: string, id: string, payload: string}>  $prefetched
     * @param  array<string, true>  $handedBack
     * @return array<string, true>
     */
    public static function takenKeys(array $inFlight, array $finishedDuringRead, array $prefetched, array $handedBack = []): array
    {
        $taken = $inFlight + $finishedDuringRead + $handedBack;

        foreach ($prefetched as $queued) {
            $taken[$queued['stream'].'|'.$queued['id']] = true;
        }

        return $taken;
    }

    /**
     * Seconds a pending read waits for the reads of new messages that were on their
     * way when its reply came. Those end within the read timeout, if there is one.
     *
     * Static and pure for unit testing.
     */
    public static function outstandingReadWait(float $readTimeout): float
    {
        return $readTimeout > 0 ? $readTimeout : self::DEFAULT_READ_TIMEOUT;
    }

    /**
     * Which entries this worker remembers as never started are still in its own
     * PEL, by id. An XAUTOCLAIM reply names no previous owner, so this is asked
     * before the claim. One that is not listed is held by another consumer, and
     * cannot have become this worker's again other than by a claim that charges it.
     * A round trip only while the set holds an entry of this stream.
     *
     * Limited to what the claim could pick up, and to what this worker should
     * hold: an entry cut off by that limit is charged, as after a crash.
     *
     * @return array<string, true>
     */
    private function ownUnstartedIds(RedisClient $readerRedis, string $streamKey, string $consumerGroup, int $minIdleMs): array
    {
        $count = 0;

        foreach (array_keys($this->neverStarted) as $key) {
            if (str_starts_with($key, $streamKey.'|')) {
                $count++;
            }
        }

        if ($count === 0) {
            return [];
        }

        $count += count($this->inFlight) + count($this->prefetched);
        $reply = $readerRedis->execute('XPENDING', $streamKey, $consumerGroup, 'IDLE', (string) $minIdleMs, '-', '+', (string) $count, $this->consumerId);
        $ids = [];

        foreach (is_array($reply) ? $reply : [] as $entry) {
            $ids[(string) $entry[0]] = true;
        }

        return $ids;
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
            $ownUnstarted = null;

            try {
                for ($scan = 0; $scan < self::STEAL_SCANS_PER_POLL; $scan++) {
                    // A stopping worker claims nothing more: what it took now would
                    // sit in its PEL until retry_after and then be charged. A claim
                    // already on the wire is safe: releaseConsumer() keeps a consumer
                    // with pending entries, in one script, so the entry stays claimable.
                    if ($this->hasReachedLimits()) {
                        return null;
                    }

                    $ownUnstarted ??= $this->ownUnstartedIds($readerRedis, $streamKey, $consumerGroup, $minIdleMs);

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

                        $taken = self::takenKeys($this->inFlight, $this->finishedDuringRead, $this->prefetched, $this->handedBack);
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
                        // It came from another consumer, which may have run it and
                        // crashed, so what this worker remembers no longer holds.
                        if (! isset($ownUnstarted[$message['id']])) {
                            unset($this->neverStarted[$streamKey.'|'.$message['id']]);
                        }

                        return $message;
                    }

                    // Claimed by the call above, and nobody can ever run it.
                    foreach ($claim['trimmed'] as $trimmedId) {
                        $readerRedis->execute('XACK', $streamKey, $consumerGroup, $trimmedId);
                        $this->forgetRedeliveries($readerRedis, $streamKey, $trimmedId);
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
     * Refresh $down from the application's maintenance state. A throwing
     * check (unreadable store) keeps the worker running: it never counts as down.
     */
    private function refreshDownState(bool $force): void
    {
        try {
            $this->down = ! $force && app()->isDownForMaintenance();
        } catch (\Throwable $e) {
            $this->down = false;

            if ($this->mayAnnounce('maintenance check', 60)) {
                fwrite(STDERR, "[torque:worker] Maintenance mode check failed ({$e->getMessage()}), treating as up\n");
            }
        }
    }

    /**
     * A timer period from the internal `intervals` config, floored so a bad value
     * cannot spin the loop. A missing or non-numeric value means the default.
     *
     * @param  array<string, mixed>  $config
     */
    public static function intervalSeconds(array $config, string $key, float $default): float
    {
        $value = $config['intervals'][$key] ?? null;

        return is_numeric($value) ? max(self::MIN_INTERVAL_SECONDS, (float) $value) : $default;
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
     * Ask the worker to drain and exit, remembering why. The first reason wins:
     * a limit that was already reached counts before a signal arriving after it.
     */
    private function requestStop(WorkerStopReason $reason): void
    {
        $this->noteLimitReason();
        $this->stopReason ??= $reason;
        $this->stopRequested = true;
        $this->stopHasGrace = $this->stopHasGrace || $reason !== WorkerStopReason::MaxMemoryExceeded;
    }

    /**
     * Whether in-flight jobs are out of time drain_grace_seconds from now: for
     * every stop reason except the memory limit on its own, however late it
     * joins, so a hung job in a memory stop can still be signalled out.
     */
    private function drainHasDeadline(): bool
    {
        return $this->stopHasGrace
            || $this->jobsProcessed >= $this->maxJobs
            || (time() - $this->startTime) >= $this->maxLifetime;
    }

    /**
     * Record the limit that ended the worker, when one did and nothing else
     * was recorded first. Only valid once run() set the limits.
     */
    private function noteLimitReason(): void
    {
        if ($this->stopReason !== null) {
            return;
        }

        if ($this->jobsProcessed >= $this->maxJobs) {
            $this->stopReason = WorkerStopReason::MaxJobsExceeded;
        } elseif ((time() - $this->startTime) >= $this->maxLifetime) {
            $this->stopReason = WorkerStopReason::MaxTimeExceeded;
        }
    }

    /**
     * Exit status for a stop reason, as the stock worker reports it: 0 for
     * every orderly stop, 12 for the memory limit, 1 (or what the application
     * set on Worker::$timedOutExitCode) for a job timeout. The master replaces a
     * worker whatever its status.
     */
    public static function stopStatusFor(?WorkerStopReason $reason): int
    {
        return match ($reason) {
            WorkerStopReason::MaxMemoryExceeded => Worker::EXIT_MEMORY_LIMIT,
            WorkerStopReason::TimedOut => Worker::$timedOutExitCode ?? Worker::EXIT_ERROR,
            default => Worker::EXIT_SUCCESS,
        };
    }

    /**
     * Dispatch WorkerStopping, once per process: the flag is set before the
     * dispatch, so a second stop path (or a listener that suspends) cannot repeat it.
     */
    private function announceStopping(Dispatcher $events, WorkerOptions $options, string $connectionName, string $queue, ?int $status = null): void
    {
        if ($this->stoppingAnnounced) {
            return;
        }

        $this->stoppingAnnounced = true;
        $this->noteLimitReason();

        $this->dispatchSafely($events, new WorkerStopping(
            $status ?? self::stopStatusFor($this->stopReason),
            $options,
            $this->stopReason,
            $this->jobsProcessed,
            $this->lastJobProcessedAt,
            memory_get_usage(true) / 1024 / 1024,
            $connectionName,
            $queue,
        ));
    }

    /**
     * Whether WorkerIdle may fire now: at most once per poll interval, and at
     * least one second apart, however many slots are polling.
     */
    private function idleAnnouncementDue(float $pollInterval): bool
    {
        $now = hrtime(true) / 1_000_000_000;

        if ($this->lastIdleAt !== null && $now - $this->lastIdleAt < max(1.0, $pollInterval)) {
            return false;
        }

        $this->lastIdleAt = $now;

        return true;
    }

    /**
     * Queues that became paused and queues that resumed between two reads of
     * the framework's paused set.
     *
     * Static and pure for unit testing.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{paused: list<string>, resumed: list<string>}
     */
    public static function diffPausedQueues(array $before, array $after): array
    {
        return [
            'paused' => array_values(array_diff($after, $before)),
            'resumed' => array_values(array_diff($before, $after)),
        ];
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
            $this->interruptJob($job, $slotScopes[$slot] ?? AttemptScope::current(), $signal, $events);
        }
    }

    /**
     * Tell one running job about a signal, in its own scope, and announce that
     * it was told. A job that does not implement Interruptible hears nothing.
     */
    private function interruptJob(StreamJob $job, AttemptScope $scope, int $signal, Dispatcher $events): void
    {
        $resolved = $job->getResolvedJob();

        if (! $resolved instanceof CallQueuedHandler) {
            return;
        }

        $command = $resolved->getRunningCommand();

        if (! $command instanceof Interruptible) {
            return;
        }

        AttemptScope::within($scope, function () use ($command, $signal, $events, $job): void {
            try {
                $command->interrupted($signal);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[torque:worker] Job interrupted({$signal}) threw: {$e->getMessage()}\n");

                return;
            }

            $this->dispatchJobInterrupted($events, $job, $signal);
        });
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
     *   TTL'd values that are not `drain:<pid>` (such as plain timestamps)
     *   are ignored the same way: the drain degrades to the plain
     *   SIGTERM-after-grace stop, which is safe.
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

        $read = ++$this->newReadSequence;
        $this->newReads[$read] = true;

        try {
            $result = $readerRedis->execute('XREADGROUP', ...$args);
        } finally {
            unset($this->newReads[$read]);
        }

        // Nothing suspends from here to the claim, or the prefetch, of what it read.
        return $this->parseXreadgroupResponse($result);
    }

    /**
     * Fire the job and dispatch the surrounding processing events.
     *
     * @param  float|null  $duration  Receives the handler's run time in
     *                                milliseconds, also when it throws. Left
     *                                untouched when the attempt ends before fire().
     * @param  array<string, array<string, mixed>>  $streams
     * @param  (\Closure(int, int): void)|null  $onTimeout  Called with the timeout and
     *                                                      fire()'s hrtime start when
     *                                                      the job runs past its timeout.
     * @param  int  $redeliveries  Deliveries of this entry after its first; 0 without strict_attempts.
     *
     * @param-out float|null $duration
     */
    // @phpstan-ignore paramOut.unusedType (the null is what a throw before fire() leaves behind)
    private function processMessage(
        StreamJob $job,
        Dispatcher $events,
        string $connectionName,
        &$duration = null,
        array $streams = [],
        ?\Closure $onTimeout = null,
        int $redeliveries = 0,
    ): void {
        $events->dispatch(new JobProcessing($connectionName, $job));

        $this->guardAgainstExceededAttempts($job, $streams[$job->getQueue()] ?? [], $redeliveries);

        $this->guardAgainstRepeatedCrashes($job);

        // JobProcessed carries the handler's run time in milliseconds, as the
        // stock worker reports it since laravel/framework 13.34.0: only fire()
        // is timed, not the event dispatch or the stream acknowledgement.
        $startedAt = hrtime(true);

        $timeout = $onTimeout === null ? null : $this->attemptTimeout($job, $streams[$job->getQueue()] ?? []);
        $timer = $timeout === null ? null : EventLoop::delay($timeout, static fn () => $onTimeout($timeout, $startedAt));

        try {
            $job->fire();
        } finally {
            if ($timer !== null) {
                EventLoop::cancel($timer);
            }

            $duration = round((hrtime(true) - $startedAt) / 1_000_000, 2);
        }

        // Timed out while it ran, and its attempt was settled without it.
        if ($job->isAbandoned()) {
            return;
        }

        // If the job handler did not explicitly delete, release, or fail the job,
        // treat it as successfully completed.
        if (! $job->isDeleted() && ! $job->isReleased() && ! $job->hasFailed()) {
            $job->delete();
        }

        $events->dispatch(new JobProcessed($connectionName, $job, $duration));

        // As in the stock worker's process(), so only on this path without a throw.
        // isReleased() is no proof: it is set before the release runs, and a failed
        // one leaves the entry in the PEL for reclaim. Safe, because the job is out
        // by now: a stock listener throwing here could still fail it on top of that.
        if ($job->releaseSucceeded() && ! $job->isDeleted()) {
            $this->dispatchSafely($events, new JobReleased($connectionName, $job));
        }
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
            $this->announceListenerFailure($event, $e, $announceEverySeconds);
        }
    }

    /**
     * Like dispatchSafely() for an event whose listeners can veto: true when a
     * listener returned false. A throwing listener vetoes nothing.
     */
    private function untilSafely(Dispatcher $events, object $event, int $announceEverySeconds = 0): bool
    {
        try {
            return $events->until($event) === false;
        } catch (\Throwable $e) {
            $this->announceListenerFailure($event, $e, $announceEverySeconds);

            return false;
        }
    }

    private function announceListenerFailure(object $event, \Throwable $e, int $announceEverySeconds): void
    {
        if ($announceEverySeconds > 0 && ! $this->mayAnnounce($event::class, $announceEverySeconds)) {
            return;
        }

        $name = class_basename($event);

        fwrite(STDERR, "[torque:worker] {$name} dispatch failed: {$e->getMessage()}\n");

        if (Worker::$reportJobExceptions) {
            $this->reportSafely($e);
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
     * Fails the job if a limit says so, otherwise releases it with its backoff,
     * in the stock worker's order: a failure comes before JobExceptionOccurred, a
     * release after it. A job that already released, deleted or failed itself
     * before throwing keeps that outcome and is neither added again nor
     * dead-lettered, and it does not count against any limit. That includes a
     * release on the last attempt: its copy runs once more and is the one that can
     * still reach the dead-letter stream.
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
        $streamConfig = $streams[$job->getQueue()] ?? [];
        $exceptionOccurred = new JobExceptionOccurred($connectionName, $job, $exception);

        $shouldFail = ! self::settledItself($job) && $this->shouldFailAfter($exception, $job, $streamConfig);

        // Counting maxExceptions suspends, and something the job started can still
        // settle it in that time, so its state is read again here.
        // @phpstan-ignore booleanNot.alwaysTrue (analysis cannot see that suspension)
        if ($shouldFail && ! self::settledItself($job)) {
            try {
                $this->failPermanently($job, $message, $exception, $events, $deadLetterHandler, $circuitBreaker);
            } catch (\Throwable $handlingFailure) {
                $this->dispatchSafely($events, $exceptionOccurred);

                throw $handlingFailure;
            }

            $this->dispatchSafely($events, $exceptionOccurred);

            return;
        }

        $this->dispatchSafely($events, $exceptionOccurred);

        if (self::settledItself($job)) {
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

        $backoffSeconds = $this->retryBackoff($job, $streamConfig);
        $job->retryAfterFailure($backoffSeconds);

        // After the release, as in the stock worker: one that throws fires nothing.
        $this->dispatchSafely($events, new JobReleasedAfterException($connectionName, $job, $backoffSeconds, $exception));
    }

    /**
     * End the attempt of a job that ran past its timeout, in the attempt's scope,
     * and exactly once, like the slot does for an attempt that ended by itself.
     * The job's own Fiber has no say in any of this: see StreamJob::abandon().
     *
     * @param  array{stream: string, id: string, payload: string}  $message
     * @param  array<string, mixed>  $streamConfig
     */
    private function settleTimedOutAttempt(
        StreamJob $job,
        array $message,
        int $timeout,
        int $firedAt,
        int $jobStartTime,
        Dispatcher $events,
        string $connectionName,
        array $streamConfig,
        DeadLetterHandler $deadLetterHandler,
        CircuitBreaker $circuitBreaker,
        MetricsCollector $metrics,
    ): void {
        $exception = TimeoutExceededException::forJob($job);
        $duration = round((hrtime(true) - $firedAt) / 1_000_000, 2);

        // A timeout is no crash. First, so a forced exit does not count it as one.
        $this->forgetProcessingMarker($job);

        // Half the grace at most, so a hung callback leaves time for the outcome
        // before the drain's forced exit, and one deadline for all of them.
        $deadline = microtime(true) + DrainSettings::settleSeconds($this->config);
        $scope = AttemptScope::current();

        $this->runSettleCallback($job, $deadline, fn () => $this->interruptJob($job, $scope, SIGALRM, $events));

        try {
            $this->applyTimeoutOutcome($job, $message, $exception, $timeout, $deadline, $events, $connectionName, $streamConfig, $deadLetterHandler, $circuitBreaker);
        } catch (\Throwable $handlingFailure) {
            // As for a failure in the slot: the entry stays in the PEL for reclaim.
            fwrite(STDERR, "[torque:worker] Handling a job timeout threw: {$handlingFailure->getMessage()}\n");
            $this->reportSafely($handlingFailure);
        }

        $metrics->recordJobFailed((hrtime(true) - $jobStartTime) / 1_000_000, $job->getQueue(), rescue(fn (): string => $job->resolveName(), null, false));

        $this->dispatchJobAttempted($events, $connectionName, $job, $exception, $duration);

        if (Worker::$reportJobExceptions) {
            $this->reportSafely($exception);
        }
    }

    /**
     * Run a job's or listener's callback for a timed out attempt, and wait for it no
     * longer than the deadline: one that never returns must not keep the attempt from
     * being settled, or the job would run again unchanged, for ever without
     * strict_attempts. The callback goes on in its own Fiber, with the attempt's
     * scope carried over by async(). Until the wait ends it can settle the attempt,
     * as if the worker's Fiber had called it; after that it has no say. It always
     * gets a moment, also past the deadline, which is enough for one that does not
     * suspend. Callbacks catch what they throw, so there is nothing to report late.
     */
    private function runSettleCallback(StreamJob $job, float $deadline, \Closure $callback): void
    {
        $settler = \Fiber::getCurrent();

        $future = async(static function () use ($job, $callback): void {
            $job->settleFrom(\Fiber::getCurrent());

            $callback();
        });

        try {
            $future->await(new TimeoutCancellation(max($deadline - microtime(true), 0.05)));
        } catch (CancelledException) {
            fwrite(STDERR, "[torque:worker] A timeout callback of job {$job->getMessageId()} did not finish in time; settling without it.\n");
        } finally {
            $job->settleFrom($settler);
        }
    }

    /**
     * Fail or release a timed out job. The checks are the stock timeout handler's,
     * in its order: attempts, then maxExceptions, then failOnTimeout, each evaluated
     * so maxExceptions is always counted, with JobTimedOut after them. The rest of
     * the sequence is what stock does when it does not kill on timeout: the
     * exception event, then the release. A job that settled itself before it hung
     * keeps that outcome, as in handleFailure().
     *
     * The delay is at least `timeouts.release_delay_seconds`: a shorter one could
     * hand the job to another worker while whatever it was hung on is still being
     * drained here, so keep it near the time that takes. The entry
     * stays in the PEL until LUA_RELEASE, and in $inFlight beyond it, so this worker
     * cannot pick it up again. Another can only steal it once it has been idle for
     * retry_after, the same as any job running that long.
     *
     * @param  array{stream: string, id: string, payload: string}  $message
     * @param  array<string, mixed>  $streamConfig
     */
    private function applyTimeoutOutcome(
        StreamJob $job,
        array $message,
        TimeoutExceededException $exception,
        int $timeout,
        float $deadline,
        Dispatcher $events,
        string $connectionName,
        array $streamConfig,
        DeadLetterHandler $deadLetterHandler,
        CircuitBreaker $circuitBreaker,
    ): void {
        $settledItself = self::settledItself($job);
        $shouldFail = false;

        try {
            if (! $settledItself) {
                $shouldFail = $this->shouldFailAfter($exception, $job, $streamConfig) || $job->shouldFailOnTimeout();
            }

            if ($shouldFail) {
                $this->failPermanently($job, $message, $exception, $events, $deadLetterHandler, $circuitBreaker);
            }
        } finally {
            $this->runSettleCallback($job, $deadline, fn () => $this->dispatchSafely($events, new JobTimedOut($job->getConnectionName(), $job, $timeout)));
            $this->runSettleCallback($job, $deadline, fn () => $this->dispatchSafely($events, new JobExceptionOccurred($connectionName, $job, $exception)));
        }

        if ($shouldFail) {
            return;
        }

        // Read again: a listener above may have settled it.
        if (self::settledItself($job)) {
            // As in handleFailure(): markAsFailed() alone has not acknowledged it.
            if (! $job->isDeleted() && ! $job->isReleased()) {
                $job->delete();
            }

            return;
        }

        $delay = max($this->retryBackoff($job, $streamConfig), DrainSettings::releaseDelaySeconds($this->config));

        $job->retryAfterFailure($delay);

        $this->dispatchSafely($events, new JobReleasedAfterException($connectionName, $job, $delay, $exception));
    }

    /**
     * Fail a job for good. The one way a job leaves the worker as failed.
     *
     * @param  array{stream: string, id: string, payload: string}  $message
     */
    private function failPermanently(
        StreamJob $job,
        array $message,
        \Throwable $exception,
        Dispatcher $events,
        DeadLetterHandler $deadLetterHandler,
        ?CircuitBreaker $circuitBreaker,
    ): void {
        $queueName = $job->getQueue();

        // Write to dead-letter stream FIRST: $job->fail() may throw (e.g. job's
        // failed() callback, DB write for failed_jobs table, or event listener),
        // and we must not lose the failed job.
        $deadLetterHandler->handle(
            queue: $queueName,
            payload: $message['payload'],
            messageId: $message['id'],
            exception: $exception,
            reason: $this->failureReasons[$exception] ?? null,
        );

        // Only permanent failures feed the breaker; a release/retry is
        // neutral, since a job that is going to be retried says nothing
        // yet about the health of the stream's dependencies.
        $circuitBreaker?->recordFailure($queueName);

        // Mark as failed via Laravel's base Job: this ACKs/DELs the
        // stream message and calls the job's failed() callback.
        try {
            $job->fail($exception);
        } catch (\Throwable $failError) {
            // fail() already deleted the message from the stream.
            // If it throws after that (e.g. failed() callback or DB),
            // the job is already in dead-letter: safe to swallow, but
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

    private static function settledItself(StreamJob $job): bool
    {
        return $job->isDeleted() || $job->isReleased() || $job->hasFailed();
    }

    /**
     * Whether this exception ends the job, in the stock worker's order: attempts,
     * then maxExceptions, then the exception handler's verdict. As in stock the
     * exception counts against maxExceptions even when attempts already ended it.
     *
     * @param  array<string, mixed>  $streamConfig
     */
    private function shouldFailAfter(\Throwable $exception, StreamJob $job, array $streamConfig): bool
    {
        // The crash guard and the strict attempts check throw this to end the job,
        // and neither is one more exception to count. TimeoutExceededException
        // extends it: a job that only timed out goes through the checks below.
        if ($exception instanceof MaxAttemptsExceededException && ! $exception instanceof TimeoutExceededException) {
            return true;
        }

        $attemptsEnded = $this->attemptsEndedAfterFailure($job, $streamConfig);
        $exceptionsEnded = $this->honoursJobLimits() && $this->countAgainstMaxExceptions($job);

        // A dontRetry()/dontRetryWhen() verdict from the application's exception
        // handler (Laravel v13.17.0+) forces immediate failure, no matter how
        // many attempts remain, matching Illuminate\Queue\Worker.
        return $attemptsEnded || $exceptionsEnded || $this->shouldStopRetries($exception);
    }

    /**
     * Count a thrown exception against the job's maxExceptions, in the app cache
     * as the stock worker does, so the count survives a release and a new worker.
     * Shares its key with the crash guard, also as in stock. A cache outage must
     * not fail a job: it retries, uncounted, on its other limits.
     */
    private function countAgainstMaxExceptions(StreamJob $job): bool
    {
        if (($uuid = $job->uuid()) === null
            || ($maxExceptions = $job->maxExceptions()) === null
            || ($cache = $this->crashCache()) === null) {
            return false;
        }

        $counter = 'job-exceptions:'.$uuid;

        try {
            if (! $cache->get($counter)) {
                $cache->put($counter, 0, now()->addDay());
            }

            if ($maxExceptions > (int) $cache->increment($counter)) {
                return false;
            }

            $cache->forget($counter);

            return true;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] Exception count failed for {$uuid}: {$e->getMessage()}\n");

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $streamConfig
     */
    private function attemptsEndedAfterFailure(StreamJob $job, array $streamConfig): bool
    {
        if (($deadline = $this->retryDeadline($job)) !== null) {
            return $deadline <= time();
        }

        $limit = $this->attemptLimit($job, $streamConfig);

        return $limit !== null && $job->triesUsed() >= $limit;
    }

    /**
     * The check of a job about to run: that copy fails here instead of running again.
     * Tries are the stock worker's check under strict_attempts, except that a job's
     * own releases do not use them up, so RateLimited and WithoutOverlapping release
     * loops keep working. Those are limited by time instead: see
     * guardAgainstReleaseLimit().
     *
     * A delivery after a worker went away does not raise attempts(): the crash guard
     * recognises a crash by that number staying the same. So those deliveries are
     * counted on the side, in the stream's redelivery hash, and added here. A job
     * with a retryUntil is limited by that alone, for tries and releases both.
     *
     * @param  array<string, mixed>  $streamConfig
     * @param  int  $redeliveries  Deliveries of this entry after its first.
     *
     * @throws MaxAttemptsExceededException
     */
    private function guardAgainstExceededAttempts(StreamJob $job, array $streamConfig, int $redeliveries = 0): void
    {
        if (! $this->honoursJobLimits()) {
            return;
        }

        $strict = $this->enforcesStrictAttempts();

        if (($deadline = $this->retryDeadline($job)) !== null) {
            if ($strict && time() > $deadline) {
                throw MaxAttemptsExceededException::forJob($job);
            }

            return;
        }

        $limit = $this->attemptLimit($job, $streamConfig);

        // $tries = 0: neither its tries nor its releases are limited.
        if ($limit === null) {
            return;
        }

        if ($strict && $job->triesUsed() + $redeliveries > $limit) {
            if ($redeliveries === 0) {
                throw MaxAttemptsExceededException::forJob($job);
            }

            // Same exception, with what an operator needs to tell this apart from a
            // job that used up its attempts by failing.
            throw $this->limitFailure($job, 'redelivery_limit', "{$job->triesUsed()} attempts and {$redeliveries} redeliveries after a worker went away, limit {$limit}.");
        }

        $this->guardAgainstReleaseLimit($job);
    }

    /**
     * The limit on a job that keeps releasing itself, with or without strict_attempts.
     * The deadline was written by the job's first release. An operator can still
     * switch the limit off for a job that already carries one, so that is read too.
     *
     * @throws MaxAttemptsExceededException
     */
    private function guardAgainstReleaseLimit(StreamJob $job): void
    {
        $deadline = $job->releaseDeadline();

        if ($deadline === null || time() <= $deadline) {
            return;
        }

        $seconds = $job->streamQueue->releaseLimitSeconds($job->getQueue());

        if ($seconds > 0) {
            throw $this->limitFailure($job, 'release_limit', "{$job->releases()} releases, and its release limit of {$seconds} seconds has passed.");
        }
    }

    /**
     * The exception that ends a job about to run, with the reason its dead letter
     * gets, logged since the job never gets to say why itself.
     */
    private function limitFailure(StreamJob $job, string $reason, string $details): MaxAttemptsExceededException
    {
        $exception = new MaxAttemptsExceededException("{$job->resolveName()} has been attempted too many times: {$details}");
        $exception->job = $job;
        $this->failureReasons[$exception] = $reason;

        fwrite(STDERR, "[torque:worker] Job {$job->getMessageId()} failed without running: {$exception->getMessage()}\n");

        return $exception;
    }

    /**
     * The job's own deadline, which replaces its attempt limit when it has one.
     */
    private function retryDeadline(StreamJob $job): ?int
    {
        return $this->honoursJobLimits() ? ($job->retryUntil() ?: null) : null;
    }

    /**
     * The number of runs a job gets; null has no limit. A job's own limit of 0
     * means none, as in stock. A stream's means one run and no retries, which is
     * what it has always meant here.
     *
     * @param  array<string, mixed>  $streamConfig
     */
    private function attemptLimit(StreamJob $job, array $streamConfig): ?int
    {
        $declared = $this->honoursJobLimits() ? $job->maxTries() : null;

        if ($declared !== null) {
            return $declared > 0 ? $declared : null;
        }

        return max(1, (int) ($streamConfig['max_retries'] ?? 3));
    }

    /**
     * Seconds until the retry: the job's backoff, then the stream's.
     *
     * @param  array<string, mixed>  $streamConfig
     */
    private function retryBackoff(StreamJob $job, array $streamConfig): int
    {
        $attempts = $job->triesUsed();

        if ($this->honoursJobLimits()) {
            // As stock's calculateBackoff(). The payload holds "1,5,10" for [1, 5, 10].
            $declared = $job->backoff();

            if ($declared !== null) {
                return self::backoffStep(explode(',', is_array($declared) ? implode(',', $declared) : (string) $declared), $attempts);
            }

            $steps = self::streamBackoffSteps($streamConfig['backoff'] ?? null);

            if ($steps !== null) {
                return self::backoffStep($steps, $attempts);
            }
        }

        // 2, 4, 8, 16, ... up to the cap, and short of the job's deadline so it
        // still runs before that. A declared backoff is the job's own choice.
        $delay = min(2 ** min($attempts, 30), self::MAX_EXPONENTIAL_BACKOFF);
        $deadline = $this->retryDeadline($job);

        return $deadline === null ? $delay : min($delay, max(0, $deadline - time() - self::RETRY_DEADLINE_MARGIN));
    }

    /**
     * @param  non-empty-list<mixed>  $steps
     */
    private static function backoffStep(array $steps, int $attempts): int
    {
        return max(0, (int) ($steps[$attempts - 1] ?? $steps[array_key_last($steps)]));
    }

    /**
     * Read a stream's `backoff`: seconds, as a number, a list or "1,5,10". Null
     * for `exponential` and for anything else, which the worker warns about at boot.
     *
     * @return non-empty-list<mixed>|null
     */
    private static function streamBackoffSteps(mixed $backoff): ?array
    {
        $steps = match (true) {
            is_array($backoff) => array_values($backoff),
            is_string($backoff) => explode(',', $backoff),
            is_int($backoff), is_float($backoff) => [$backoff],
            default => [],
        };

        if ($steps === [] || ! array_all($steps, static fn (mixed $step): bool => is_numeric($step))) {
            return null;
        }

        return $steps;
    }

    /**
     * `job_limits: stream` is the kill switch: only `max_retries` and the capped
     * 2**attempts, as before jobs could declare limits, whatever else is configured.
     */
    private function honoursJobLimits(): bool
    {
        return ($this->config['job_limits'] ?? 'job') !== 'stream';
    }

    private function enforcesStrictAttempts(): bool
    {
        return $this->honoursJobLimits() && (bool) ($this->config['strict_attempts'] ?? true);
    }

    /**
     * The seconds this attempt may run, or null for no limit: always while
     * timeouts are off, and at a value of 0, as for `pcntl_alarm` in stock.
     *
     * @param  array<string, mixed>  $streamConfig
     */
    private function attemptTimeout(StreamJob $job, array $streamConfig): ?int
    {
        if (! $this->timeoutsEnabled()) {
            return null;
        }

        $timeout = (int) ($job->timeout() ?? $streamConfig['timeout'] ?? 0);

        return $timeout > 0 ? $timeout : null;
    }

    private function timeoutsEnabled(): bool
    {
        return (bool) ($this->config['timeouts']['enabled'] ?? true);
    }

    /**
     * Say at boot when PHP's own memory_limit sits below the worker's: PHP would
     * fatal before the worker's check ever drains it. An ini of -1 is unlimited
     * and a setting of 0 is off.
     */
    private function announceMemorySettings(): void
    {
        if ($this->memoryLimit <= 0) {
            return;
        }

        $ini = self::iniMemoryMegabytes((string) ini_get('memory_limit'));

        if ($ini !== null && $ini < $this->memoryLimit) {
            fwrite(STDERR, "[torque:worker] WARN PHP memory_limit is {$ini} MB, below the torque memory_limit of {$this->memoryLimit} MB, so PHP will fatal before the worker drains itself. Raise the ini value or lower torque.memory_limit\n");
        }
    }

    /**
     * Say at boot when the grace sits below what the streams ask for. The only
     * signal for an app whose published config still pins
     * `env('TORQUE_DRAIN_GRACE', 10)`, which a restart turns into abandoned jobs.
     */
    private function announceDrainGrace(): void
    {
        $grace = DrainSettings::graceSeconds($this->config);
        $derived = DrainSettings::derivedGraceSeconds($this->config);

        if ($grace < $derived) {
            fwrite(STDERR, "[torque:worker] WARN drain_grace_seconds is {$grace}s, below the {$derived}s derived from the longest stream timeout or retry_after, so a restart or rotation abandons jobs still running after {$grace}s. Set torque.drain_grace_seconds to null and unset TORQUE_DRAIN_GRACE to use the derived value, or raise it to at least {$derived}\n");
        }
    }

    /**
     * An ini memory value in megabytes, or null for unlimited or unparseable.
     */
    private static function iniMemoryMegabytes(string $value): ?float
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/^(-?\d+)\s*([kmg]?)b?$/i', $value, $m)) {
            return null;
        }

        $number = (int) $m[1];

        if ($number < 0) {
            return null;
        }

        $megabytes = match (strtolower($m[2])) {
            'g' => $number * 1024.0,
            'm' => (float) $number,
            'k' => $number / 1024,
            default => $number / 1024 / 1024,
        };

        return $megabytes > 0 ? $megabytes : null;
    }

    /**
     * Say at boot which stream timeouts cannot do their job. A job past retry_after
     * can be stolen and run twice before its timeout hits. Timeouts a job declares
     * are unknown here.
     *
     * @param  array<string, array<string, mixed>>  $streams
     */
    private function announceTimeoutSettings(array $streams): void
    {
        if (! $this->timeoutsEnabled()) {
            return;
        }

        foreach ($streams as $name => $stream) {
            $timeout = (int) ($stream['timeout'] ?? 0);
            $retryAfter = (int) ($stream['retry_after'] ?? 60);

            if ($timeout >= $retryAfter && $timeout > 0) {
                fwrite(STDERR, "[torque:worker] WARN stream {$name}: timeout {$timeout} is not below retry_after {$retryAfter}, so another worker can steal a job that is still running\n");
            }
        }
    }

    /**
     * Say at boot what the limit settings leave out. Which jobs a worker will see
     * is unknown here, so this cannot name the jobs that would behave differently.
     *
     * @param  array<string, array<string, mixed>>  $streams
     */
    private function announceLimitSettings(array $streams): void
    {
        if (! $this->honoursJobLimits()) {
            fwrite(STDERR, "[torque:worker] WARN strict_attempts and release_limit_seconds are ignored while job_limits is stream, so a job that keeps calling release() is not limited\n");

            return;
        }

        foreach ($streams as $name => $stream) {
            $backoff = $stream['backoff'] ?? 'exponential';

            if ($backoff !== 'exponential' && self::streamBackoffSteps($backoff) === null) {
                fwrite(STDERR, "[torque:worker] WARN stream {$name}: backoff ".json_encode($backoff)." is not a number, a list of numbers or exponential, using exponential\n");
            }
        }

        if (! $this->enforcesStrictAttempts()) {
            fwrite(STDERR, "[torque:worker] NOTE strict_attempts is off: attempts and retryUntil are only checked after a thrown exception, so only release_limit_seconds limits a job that keeps calling release(). A delivery after a worker died is not counted either, so only maxExceptions limits a job whose worker keeps dying\n");
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
