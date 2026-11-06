<?php

declare(strict_types=1);

namespace Webpatser\Torque\Process;

use Closure;
use Illuminate\Support\ConfigurationUrlParser;
use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Manager\ScaleDecision;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Redis\UpgradeRunner;
use Webpatser\Torque\Support\DrainSettings;
use Webpatser\Torque\Support\LongWaitGate;
use Webpatser\Torque\Support\ProcessInspector;
use Webpatser\Torque\Support\StreamQueueResolver;
use Webpatser\Torque\Support\WorkerId;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Forks N worker processes and monitors them.
 *
 * The master process installs SIGTERM/SIGINT handlers to gracefully shut down
 * all children, and a SIGCHLD handler to reap zombies. If a worker exits
 * unexpectedly (and no stop was requested), the master respawns a replacement.
 */
final class MasterProcess
{
    /** `Memcached::OPT_CONNECT_TIMEOUT`, in ms. By value: the extension is optional. */
    private const int MEMCACHED_OPT_CONNECT_TIMEOUT = 14;

    /** `Memcached::OPT_POLL_TIMEOUT`, in ms. */
    private const int MEMCACHED_OPT_POLL_TIMEOUT = 8;

    /**
     * Compare-and-delete: remove the key only while it still holds the exact
     * value the caller judged. Used by {@see clearStaleDrainPause()} so a
     * drain (or a deliberate `torque:pause`) written between the read and the
     * delete is never swallowed.
     */
    private const LUA_DELETE_IF_EQUAL = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    /** @var array<int, true> Map of child PIDs to `true`. */
    public private(set) array $workerPids = [];

    /** @var array<int, true> PIDs being scaled down — should not be respawned on exit. */
    private array $scalingDownPids = [];

    private bool $shouldStop = false;

    /**
     * Drain coordination, written by the SIGUSR2 handler and read by the
     * monitor loop. The signal handler stays minimal; the monitor tick
     * promotes the request into the {@see $draining} timer.
     */
    private bool $drainRequested = false;

    private bool $draining = false;

    private ?float $drainStartedAt = null;

    private ?AutoScaler $autoScaler = null;

    private ?MetricsPublisher $metricsPublisher = null;

    /**
     * The exclusive master lock (flock on storage/torque.lock), held for the
     * process lifetime. Mutual exclusion between masters lives here, not in
     * pgrep sweeps: the kernel releases the lock on any exit, including
     * SIGKILL. During a takeover the old master still holds it, so the
     * replacement acquires it lazily from the monitor loop once the old
     * master exits.
     *
     * A flock belongs to the open file, which a fork shares, so the handle is
     * close-on-exec: a worker or alert child outliving the master must not
     * keep the lock from the next one.
     *
     * @var resource|null
     */
    private $lockHandle = null;

    /** Whether this master has written (and thus owns) the PID file. */
    private bool $ownsPidFile = false;

    /**
     * Unix timestamp of the next Redis housekeeping run, or null when
     * housekeeping has not run yet (which makes the first monitor tick after
     * start run it immediately, so a restart after an incident cleans up
     * without waiting out a full interval).
     */
    private ?int $housekeepingDueAt = null;

    /** Seconds between long-wait checks. */
    private const int LONG_WAIT_CHECK_INTERVAL = 30;

    /** Clock time of the next long-wait check, null before the first. */
    private ?float $longWaitDueAt = null;

    private ?LongWaitGate $longWaitGate = null;

    /** Seconds an alert child may take, unless `long_wait_alert_seconds` says otherwise. */
    private const int LONG_WAIT_ALERT_SECONDS = 60;

    /** Seconds past that limit before the master kills a child that did not end itself. */
    private const int LONG_WAIT_ALERT_KILL_SLACK = 5;

    /** Alert children at once; a queue over the cap gets its turn at a later check. */
    private const int LONG_WAIT_ALERT_MAX_CHILDREN = 4;

    /** Seconds after a failed alert before the next one, instead of the cooldown. */
    private const int LONG_WAIT_ALERT_RETRY_SECONDS = 300;

    /**
     * Running alert children, which are no workers: never counted, never respawned.
     *
     * @var array<int, array{queue: string, killAt: int, killed: bool}>
     */
    public private(set) array $longWaitAlerts = [];

    /** @var array<string, string> Where the housekeeper's capped sweep continues at the next run. */
    private array $redeliveryCursors = [];

    /** Rolling throughput state for the aggregate metrics publisher. */
    private ?int $lastAggregateJobsTotal = null;

    /** Failure counter at the previous publish, for the failed-jobs delta. */
    private ?int $lastAggregateJobsFailed = null;

    /**
     * Per-stream cumulative counters at the previous publish.
     *
     * @var array<string, array{0: int, 1: int}>|null
     */
    private ?array $lastAggregatePerQueue = null;

    /**
     * Per-job-class cumulative counters at the previous publish.
     *
     * @var array<string, array{0: int, 1: int, 2: float, 3: float}>|null
     */
    private ?array $lastAggregatePerJob = null;

    /**
     * Per-worker cumulative counters at the previous publish, keyed by worker id.
     *
     * Per-host deltas are folded from per-worker deltas rather than diffed from
     * a host total. A host runs many workers, and one of them rotating resets
     * its counters, which drops the host sum; clamping that at zero would
     * silently drop this host's jobs for as long as the sum took to climb back.
     * With `max_worker_lifetime` at a day that happens daily.
     *
     * A worker id seen for the first time records a baseline and contributes
     * nothing, so a master taking over a running fleet does not dump every
     * worker's lifetime counter into a single bucket.
     *
     * @var array<string, array{0: int, 1: int}>|null
     */
    private ?array $lastWorkerCounters = null;

    /** Stream depth probe for the pending gauge, opened once and reused. */
    private ?StreamQueue $depthProbe = null;

    private ?float $lastAggregateAt = null;

    /** @var array<int, int> Child PID => unix time it was spawned, for the stall watchdog. */
    private array $workerSpawnedAt = [];

    /** @var array<int, true> PIDs the watchdog has already sent SIGKILL, awaiting their reap. */
    private array $stallKilledPids = [];

    /** Start of the previous monitor tick, for spotting time the master itself lost. */
    private ?float $watchdogLastTickAt = null;

    /** No heartbeat counts as older than this: the last moment the master itself lost time. */
    private int $watchdogBaseline = 0;

    /** Unix time of the next heartbeat check; ticks run ten times more often. */
    private float $watchdogNextCheckAt = 0.0;

    /** Current number of running worker processes. */
    public int $workerCount {
        get => count($this->workerPids);
    }

    private readonly string $artisanPath;

    /**
     * @param  array<string, mixed>  $config  Merged Torque config.
     * @param  Closure(string): void  $logger  Callback for outputting status messages.
     */
    public function __construct(
        private readonly array $config,
        private readonly Closure $logger,
        private readonly ?int $takeoverPid = null,
    ) {
        $this->artisanPath = base_path('artisan');
    }

    /**
     * Fork worker processes and enter the monitoring loop.
     *
     * Returns the process exit code (0 for clean shutdown).
     */
    public function start(): int
    {
        // First of all: the check only reads, so a refusal leaves no lock, PID
        // file or worker behind, and a refused reload leaves the old master be.
        if (! $this->upgradePathAllowed()) {
            return 1;
        }

        pcntl_async_signals(true);

        if ($this->takeoverPid === null) {
            // Normal start: mutual exclusion up front. The lock, not a pgrep
            // sweep, decides whether another master is running.
            if (! $this->tryAcquireMasterLock()) {
                // Either a live master holds the flock, or the lock file is
                // unopenable (tryAcquireMasterLock logged the specifics).
                ($this->logger)('Could not acquire the master lock (is another master running?); refusing to start.');

                return 1;
            }

            // Write PID file so torque:stop/status can find us.
            $this->writePidFile();
            $this->ownsPidFile = true;
        }
        // Takeover: the old master still holds the lock and the PID file.
        // Both are claimed later: the lock lazily once the old master exits,
        // the PID file only after this master's own fleet proves healthy
        // (see the takeover block below), so a broken deploy aborts the
        // reload instead of draining a healthy fleet into an outage.

        // Graceful shutdown: forward signal to all children.
        pcntl_signal(SIGTERM, function () {
            $this->shouldStop = true;
            $this->signalChildren(SIGTERM);
        });

        pcntl_signal(SIGINT, function () {
            $this->shouldStop = true;
            $this->signalChildren(SIGTERM);
        });

        // Graceful drain: stop pickup, wait reload_pause_seconds for in-flight
        // jobs to finish, then SIGTERM workers. Used by `torque:reload`. The
        // handler stays trivial because the sync-signal path (Linux) reads
        // the flag from `pcntl_sigtimedwait` and the async path (macOS)
        // delivers into this same closure.
        pcntl_signal(SIGUSR2, function () {
            $this->drainRequested = true;
        });

        $this->warnIfPaused();
        $this->runDataUpgrade();

        $this->prepareHeartbeatDirectory();
        $this->warnIfCacheCanStall();

        $numWorkers = (int) ($this->config['workers'] ?? 4);

        ($this->logger)("Starting {$numWorkers} worker processes...");

        for ($i = 0; $i < $numWorkers; $i++) {
            $this->spawnWorker();
        }

        if ($this->takeoverPid !== null) {
            // Takeover handshake: only a demonstrably live fleet may take the
            // PID file. The flip is the readiness signal torque:reload polls,
            // so it must mean "the new fleet is processing", not "forks ran".
            if (! $this->waitForOwnFleetReady()) {
                ($this->logger)('Takeover aborted: no worker heartbeat within the readiness window. Old master left untouched.');
                $this->shouldStop = true;
                $this->signalChildren(SIGTERM);
                $this->monitor();

                return 1;
            }

            $this->writePidFile();
            $this->ownsPidFile = true;
            $this->signalOldMaster();
        }

        // The aggregate metrics publisher runs for every master when metrics
        // are enabled; autoscale additionally builds its scaler on top of it.
        if ($this->config['metrics']['enabled'] ?? true) {
            $this->metricsPublisher = new MetricsPublisher(
                redisUri: $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
                prefix: $this->config['redis']['prefix'] ?? 'torque:',
                settings: $this->config['metrics'] ?? [],
            );
        }

        if ($this->config['autoscale']['enabled'] ?? false) {
            $autoscaleConfig = $this->config['autoscale'];
            $redisUri = $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379';

            $this->autoScaler = new AutoScaler(
                redisUri: $redisUri,
                minWorkers: (int) ($autoscaleConfig['min_workers'] ?? 2),
                maxWorkers: (int) ($autoscaleConfig['max_workers'] ?? 8),
                scaleUpThreshold: (float) ($autoscaleConfig['scale_up_threshold'] ?? 0.85),
                scaleDownThreshold: (float) ($autoscaleConfig['scale_down_threshold'] ?? 0.20),
                cooldownSeconds: (int) ($autoscaleConfig['cooldown'] ?? 30),
            );

            // Autoscale reads worker metrics, so it needs the publisher even
            // when the aggregate publishing itself was disabled.
            $this->metricsPublisher ??= new MetricsPublisher(
                redisUri: $redisUri,
                prefix: $this->config['redis']['prefix'] ?? 'torque:',
                settings: $this->config['metrics'] ?? [],
            );

            ($this->logger)('Autoscaling enabled ('
                .$autoscaleConfig['min_workers'].'-'.$autoscaleConfig['max_workers']
                .' workers)');
        }

        $exitCode = $this->monitor();

        // No global worker-metrics wipe here: workers delete their own key on
        // graceful exit and every hash carries a heartbeat TTL, so crash
        // ghosts self-expire within a minute. A wipe would also delete the
        // replacement fleet's metrics when a drained-away master exits after
        // a takeover.

        $this->removePidFile();

        return $exitCode;
    }

    /**
     * Spawn a single worker as a clean PHP process.
     *
     * Uses pcntl_fork() + pcntl_exec() to replace the process image entirely.
     * This avoids Fiber/Revolt segfaults that happen when forking a process
     * with active event loop state — pcntl_exec() replaces the memory image.
     */
    private function spawnWorker(): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork worker process');
        }

        if ($pid === 0) {
            // Child: immediately replace process image with a fresh PHP process.
            // This avoids inheriting any Fiber/Revolt/Redis state from the parent.
            $queues = implode(',', (array) ($this->config['queues'] ?? ['default']));
            $concurrency = (string) (int) ($this->config['coroutines_per_worker'] ?? 50);

            pcntl_exec(PHP_BINARY, [
                $this->artisanPath,
                'torque:worker',
                "--queues={$queues}",
                "--concurrency={$concurrency}",
            ]);

            // pcntl_exec only returns on failure.
            fwrite(STDERR, '[Torque] pcntl_exec failed: '.pcntl_get_last_error()."\n");
            exit(1);
        }

        // Parent process — record the child PID.
        $this->workerPids[$pid] = true;
        $this->workerSpawnedAt[$pid] = time();
        ($this->logger)("Worker spawned with PID {$pid}");
    }

    /**
     * Drop everything kept per child once it has been reaped.
     *
     * Its heartbeat file goes too: nothing else removes it after a SIGKILL, and
     * the kernel may hand the PID to a later worker.
     */
    private function forgetWorker(int $pid): void
    {
        unset($this->workerPids[$pid], $this->workerSpawnedAt[$pid], $this->stallKilledPids[$pid]);

        if (self::loopStallKillSeconds($this->config) > 0) {
            @unlink(self::heartbeatPath($pid));
        }
    }

    /**
     * Monitor child processes, reaping exits and respawning as needed.
     *
     * Runs until all children have exited (either from a stop signal or
     * because they hit their max_jobs / max_lifetime limits).
     */
    private function monitor(): int
    {
        // Prefer synchronous signal delivery so SIGCHLD and stop signals wake
        // the master instantly, instead of waiting up to 100ms for the next
        // usleep() tick. pcntl_sigtimedwait is POSIX but unavailable on macOS
        // (sigtimedwait was never implemented there), so fall back to async
        // signals + usleep on platforms that don't have it.
        $syncSignals = function_exists('pcntl_sigtimedwait');

        if ($syncSignals) {
            pcntl_async_signals(false);
            pcntl_sigprocmask(SIG_BLOCK, [SIGTERM, SIGINT, SIGCHLD, SIGUSR2]);
        }

        // Run maintenance (lease self-heal, aggregate metrics, autoscale)
        // every ~10 iterations (~1 second at 100ms wait).
        $maintenanceTick = 0;

        while (! empty($this->workerPids)) {
            if ($syncSignals) {
                $info = [];
                $sig = \pcntl_sigtimedwait([SIGTERM, SIGINT, SIGCHLD, SIGUSR2], $info, 0, 100_000_000);

                if ($sig === SIGTERM || $sig === SIGINT) {
                    $this->shouldStop = true;
                    $this->signalChildren(SIGTERM);
                } elseif ($sig === SIGUSR2) {
                    $this->drainRequested = true;
                }
            } else {
                // Async signal handlers registered in start() handle SIGTERM
                // and SIGINT; usleep is interruptible by signals so it wakes
                // early when one arrives.
                usleep(100_000);
            }

            // Reap every exited child (SIGCHLD coalesces — one delivery may
            // cover multiple exits).
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                if (isset($this->longWaitAlerts[$pid])) {
                    $this->forgetLongWaitAlert($pid, $status);

                    continue;
                }

                $this->forgetWorker($pid);

                if (pcntl_wifexited($status)) {
                    $exitCode = pcntl_wexitstatus($status);
                    ($this->logger)("Worker PID {$pid} exited (code {$exitCode})");
                } elseif (pcntl_wifsignaled($status)) {
                    $signal = pcntl_wtermsig($status);
                    ($this->logger)("Worker PID {$pid} killed by signal {$signal} (".match ($signal) {
                        1 => 'SIGHUP', 2 => 'SIGINT', 6 => 'SIGABRT', 9 => 'SIGKILL',
                        11 => 'SIGSEGV', 13 => 'SIGPIPE', 15 => 'SIGTERM',
                        default => 'SIG'.$signal,
                    }.')');
                } else {
                    ($this->logger)("Worker PID {$pid} exited (unknown status)");
                }

                // Workers being scaled down should not be respawned.
                if (isset($this->scalingDownPids[$pid])) {
                    unset($this->scalingDownPids[$pid]);
                    ($this->logger)("Scaled-down worker PID {$pid} drained and exited.");
                } elseif (! $this->shouldStop) {
                    ($this->logger)('Respawning replacement worker...');
                    $this->spawnWorker();
                }
            }

            $this->superviseLongWaitAlerts();

            // Promote a SIGUSR2 drain request into an active drain timer
            // and fire its watchdog if the grace period has elapsed.
            $this->handleDrainTick();

            // Also while draining or stopping: a stalled worker cannot act on the
            // SIGTERM, and would hold this master open until a supervisor gave up.
            $this->handleWatchdogTick();

            if (++$maintenanceTick >= 10) {
                $maintenanceTick = 0;
                $maintenanceWall = microtime(true);
                $maintenanceMonotonic = hrtime(true);

                $this->maintainLease();
                $this->publishAggregateMetrics();
                $this->handleHousekeepingTick();
                $this->handleLongWaitTick();

                if ($this->autoScaler !== null) {
                    $this->evaluateAutoscale();
                }

                $this->discountMaintenanceTime($maintenanceWall, $maintenanceMonotonic);
            }
        }

        ($this->logger)('All workers exited. Master shutting down.');

        return 0;
    }

    /**
     * Kill this master's own workers whose event loop stopped.
     *
     * Only {@see $workerPids} are judged: during a takeover the old and the new
     * fleet share the heartbeat directory, and each master answers for its own.
     * A kill goes through the reaper like any other crash, which respawns unless
     * the master is stopping or the worker was being scaled down.
     */
    private function handleWatchdogTick(): void
    {
        $limit = self::loopStallKillSeconds($this->config);

        if ($limit === 0) {
            return;
        }

        $now = microtime(true);
        $firstTick = $this->watchdogLastTickAt === null;
        $gap = $firstTick ? 0.0 : $now - $this->watchdogLastTickAt;
        $this->watchdogLastTickAt = $now;

        if ($firstTick || self::watchdogShouldSkipPass($gap, $limit)) {
            [
                'baseline' => $this->watchdogBaseline,
                'nextCheckAt' => $this->watchdogNextCheckAt,
                'spawnedAt' => $this->workerSpawnedAt,
            ] = self::watchdogRebase($this->workerSpawnedAt, $now);

            if (abs($gap) >= $limit) {
                ($this->logger)(sprintf('Loop stall check skipped: the master lost %ds (sleep, a frozen VM or a clock change); heartbeats get a new %ds.', abs($gap), $limit));
            }

            return;
        }

        if ($now < $this->watchdogNextCheckAt) {
            return;
        }

        $this->watchdogNextCheckAt = $now + 1.0;

        foreach (array_keys($this->workerPids) as $pid) {
            if (isset($this->stallKilledPids[$pid])) {
                continue;
            }

            $path = self::heartbeatPath($pid);

            clearstatcache(true, $path);

            $mtime = @filemtime($path);

            $age = self::stalledHeartbeatAge(
                $mtime === false ? null : $mtime,
                $this->workerSpawnedAt[$pid] ?? (int) $now,
                $this->watchdogBaseline,
                (int) $now,
                $limit,
            );

            if ($age !== null) {
                $this->stallKilledPids[$pid] = true;
                ($this->logger)("WARN Loop stall watchdog: worker PID {$pid} heartbeat is {$age}s old (limit {$limit}s), sending SIGKILL. Its jobs are redelivered after retry_after.");
                posix_kill($pid, SIGKILL);
            }
        }
    }

    /**
     * Keep time spent waiting on Redis out of the watchdog's tick gap.
     *
     * With Redis down, a maintenance call loses seconds to its connect attempts.
     * Counted as lost time, every pass would be skipped, and the watchdog would
     * depend on Redis after all. The master really was awake for that time if both
     * clocks agree; sleep, a frozen VM or a clock step make them differ, and then
     * the gap stands.
     */
    private function discountMaintenanceTime(float $wallStartedAt, int $monotonicStartedAt): void
    {
        if ($this->watchdogLastTickAt === null) {
            return;
        }

        $this->watchdogLastTickAt = self::lastTickAfterMaintenance(
            $this->watchdogLastTickAt,
            microtime(true) - $wallStartedAt,
            (hrtime(true) - $monotonicStartedAt) / 1e9,
        );
    }

    /**
     * The previous tick's time once a maintenance call of this duration is left out.
     *
     * @see discountMaintenanceTime()
     */
    public static function lastTickAfterMaintenance(float $lastTickAt, float $wallSeconds, float $monotonicSeconds): float
    {
        return abs($wallSeconds - $monotonicSeconds) < 1.0 ? $lastTickAt + $wallSeconds : $lastTickAt;
    }

    /**
     * The watchdog state after a skipped pass, whichever way the clock moved.
     *
     * After a backward step the next check lies in the future, and a heartbeat
     * written on the new clock reads older than a spawn time from the old one, which
     * {@see stalledHeartbeatAge()} takes for a leftover. Both would turn the watchdog
     * off for the size of the step, so the check is due at once and no spawn time
     * lies ahead of the clock.
     *
     * @param  array<int, int>  $spawnedAt  Child PID => unix time it was spawned.
     * @return array{baseline: int, nextCheckAt: float, spawnedAt: array<int, int>}
     */
    public static function watchdogRebase(array $spawnedAt, float $now): array
    {
        $baseline = (int) $now;

        return [
            'baseline' => $baseline,
            'nextCheckAt' => 0.0,
            'spawnedAt' => array_map(static fn (int $at): int => min($at, $baseline), $spawnedAt),
        ];
    }

    /**
     * The WARN for a default cache store that can stall a worker into a kill, or null.
     *
     * The worker's timers read the cache synchronously (the framework's paused
     * queues, `queue:restart`, a cache-backed down state), so a blackholed cache
     * server stops the event loop for as long as the client waits. If that reaches
     * the limit the whole fleet gets killed, and again after every respawn, which
     * is the mass kill the local heartbeat files exist to prevent.
     *
     * Stores that are local or suspend say nothing, and neither does a custom or
     * composite driver, which may well be either.
     *
     * @param  array<string, mixed>  $cache  The app's `cache` config.
     * @param  array<string, mixed>  $database  The app's `database` config.
     * @param  int  $socketTimeout  The `default_socket_timeout` ini value.
     */
    public static function cacheStallWarning(array $cache, array $database, int $limit, int $socketTimeout): ?string
    {
        $name = (string) ($cache['default'] ?? '');
        $store = (array) ($cache['stores'][$name] ?? []);
        $driver = (string) ($store['driver'] ?? '');

        if ($driver === 'database') {
            $connection = (string) ($store['connection'] ?? $database['default'] ?? '');
            $connectionDriver = (string) ($database['connections'][$connection]['driver'] ?? '');

            if ($connectionDriver === 'sqlite' || str_starts_with($connectionDriver, 'fledge-')) {
                return null;
            }
        } elseif (! in_array($driver, ['redis', 'memcached', 'dynamodb'], true)) {
            return null;
        }

        $seconds = match ($driver) {
            'redis' => self::redisStoreTimeout($store, (array) ($database['redis'] ?? []), $socketTimeout),
            'memcached' => self::memcachedStoreTimeout($store),
            default => null,
        };

        if ($seconds !== null && $seconds < $limit) {
            return null;
        }

        return sprintf(
            "WARN Loop stall watchdog: worker timers read the default cache store '%s' (%s) synchronously, so a network hang can block the event loop %s. At or past loop_stall_kill_seconds (%ds) that kills every worker, and again after each respawn. Set a lower timeout on the store's connection or raise the limit. Predis on an app with php-fiberio enabled suspends instead.",
            $name,
            $driver,
            $seconds === null ? 'for a time Torque cannot determine' : sprintf('for up to %gs', $seconds),
            $limit,
        );
    }

    /**
     * How long one Redis call can wait, the longer of connecting and reading, per
     * attempt. Null when that is unknown or unbounded.
     *
     * phpredis takes 0 as `default_socket_timeout` for both. Predis has its own
     * `read_write_timeout`, where 0 or less means never, and connects within 5s by
     * default.
     *
     * @param  array<string, mixed>  $store
     * @param  array<string, mixed>  $redis  The `database.redis` config.
     */
    private static function redisStoreTimeout(array $store, array $redis, int $socketTimeout): ?float
    {
        $connection = $redis[(string) ($store['connection'] ?? 'default')] ?? null;

        if (! is_array($connection)) {
            return null;
        }

        try {
            $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $fallback = $socketTimeout > 0 ? (float) $socketTimeout : null;

        if (($redis['client'] ?? 'phpredis') === 'predis') {
            $read = $connection['read_write_timeout'] ?? null;
            $read = $read === null ? $fallback : ((float) $read > 0 ? (float) $read : null);
            $connect = (float) ($connection['timeout'] ?? 5.0);
        } else {
            $read = (float) ($connection['read_timeout'] ?? 0.0);
            $read = $read < 0 ? null : ($read > 0 ? $read : $fallback);
            $connect = (float) ($connection['timeout'] ?? 0.0);
            $connect = $connect > 0 ? $connect : $fallback;
        }

        return $read === null || $connect === null ? null : max($read, $connect);
    }

    /**
     * How long one Memcached call can wait, in seconds, or null when the options do
     * not state it: the defaults live in the C library and differ per build.
     *
     * @param  array<string, mixed>  $store
     */
    private static function memcachedStoreTimeout(array $store): ?float
    {
        $options = (array) ($store['options'] ?? []);
        $connect = $options[self::MEMCACHED_OPT_CONNECT_TIMEOUT] ?? null;
        $poll = $options[self::MEMCACHED_OPT_POLL_TIMEOUT] ?? null;

        return $connect === null || $poll === null ? null : max((float) $connect, (float) $poll) / 1000;
    }

    /**
     * The effective `loop_stall_kill_seconds`, 0 when off.
     *
     * Heartbeats come once a second and an mtime only has whole seconds, so a
     * limit of 1 would kill healthy workers on ordinary timer jitter.
     *
     * @param  array<string, mixed>  $config
     */
    public static function loopStallKillSeconds(array $config): int
    {
        $setting = $config['loop_stall_kill_seconds'] ?? null;
        $seconds = is_numeric($setting) ? (int) $setting : self::derivedLoopStallKillSeconds($config);

        return $seconds > 0 ? max(2, $seconds) : 0;
    }

    /**
     * The limit used when `loop_stall_kill_seconds` is null: the longest stream
     * `retry_after` (60 for a stream that sets none), at least 300. A killed job
     * returns after its `retry_after`, so the limit never drops below it.
     *
     * @param  array<string, mixed>  $config
     */
    public static function derivedLoopStallKillSeconds(array $config): int
    {
        $streams = is_array($config['streams'] ?? null) ? $config['streams'] : [];

        $retryAfter = array_map(
            static fn (mixed $stream): int => (is_array($stream) ? max(0, (int) ($stream['retry_after'] ?? 0)) : 0) ?: 60,
            $streams,
        );

        return max(300, $retryAfter === [] ? 0 : max($retryAfter));
    }

    /**
     * Whether the gap since the master's previous tick means it lost time itself.
     *
     * After laptop sleep, a frozen VM or a clock step every heartbeat looks old at
     * once though no worker stalled, and a pass would kill the whole fleet. A tick
     * is normally 100 ms plus a maintenance call. The threshold scales down with
     * short limits, where a smaller loss is already enough to pass the limit, and
     * caps out because any longer loss may as well be skipped: the only cost of a
     * skip is that a real stall is caught one limit later.
     */
    public static function watchdogShouldSkipPass(float $gapSeconds, int $limit): bool
    {
        return abs($gapSeconds) > max(1.0, min(5.0, $limit / 2));
    }

    /**
     * The age to report if this heartbeat means a stalled worker, otherwise null.
     *
     * No file means no heartbeat yet, and so does a file older than the spawn,
     * which an earlier process with this PID left behind. Neither is ever a stall,
     * however long ago the spawn was: a slow boot must not die, and a heartbeat
     * directory that became unwritable must not put the fleet in a kill loop. The
     * price is that a worker hanging before its first heartbeat is not covered.
     *
     * Whole seconds on both sides, because an mtime has no more.
     */
    public static function stalledHeartbeatAge(?int $mtime, int $spawnedAt, int $baseline, int $now, int $limit): ?int
    {
        if ($mtime === null || $mtime < $spawnedAt) {
            return null;
        }

        $age = $now - max($mtime, $baseline);

        return $age > $limit ? $age : null;
    }

    /**
     * Directory of the per-PID heartbeat files.
     *
     * Next to the PID file on purpose: storage is already where a master must be
     * able to write, is per app so several Torque apps on one host cannot collide,
     * and the master lock in the same place means one fleet per directory, bar a
     * takeover on one host, where PIDs are distinct.
     */
    public static function heartbeatDirectory(): string
    {
        return storage_path('framework/torque-heartbeats');
    }

    public static function heartbeatPath(int $pid): string
    {
        return self::heartbeatDirectory().'/'.$pid;
    }

    /**
     * Create the heartbeat directory and clear what dead workers left behind.
     *
     * A file whose PID is alive stays: during a takeover it belongs to the old
     * fleet, which is still running and still being watched. If it is an
     * unrelated process instead, {@see stalledHeartbeatAge()} ignores the file.
     */
    private function prepareHeartbeatDirectory(): void
    {
        if (self::loopStallKillSeconds($this->config) === 0) {
            return;
        }

        $directory = self::heartbeatDirectory();
        $previousUmask = umask(0002);

        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                ($this->logger)("WARN Loop stall watchdog: cannot create {$directory}; workers will report no heartbeat and none will be killed.");

                return;
            }
        } finally {
            umask($previousUmask);
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            $pid = (int) basename($file);

            if ($pid > 0 && (string) $pid === basename($file) && ! posix_kill($pid, 0)) {
                @unlink($file);
            }
        }
    }

    private function warnIfCacheCanStall(): void
    {
        $limit = self::loopStallKillSeconds($this->config);

        if ($limit === 0) {
            return;
        }

        $warning = self::cacheStallWarning(
            (array) config('cache'),
            (array) config('database'),
            $limit,
            (int) ini_get('default_socket_timeout'),
        );

        if ($warning !== null) {
            ($this->logger)($warning);
        }
    }

    /**
     * Read worker metrics from Redis and apply autoscaling decisions.
     */
    private function evaluateAutoscale(): void
    {
        // Never scale during a drain: the fleet is shutting down or handing
        // over, and scaling up mid-drain would fork workers just to kill them.
        if ($this->draining || $this->drainRequested) {
            return;
        }

        // Both are built together in run() when autoscale is enabled; the
        // monitor loop only calls in here once the scaler exists.
        if ($this->autoScaler === null || $this->metricsPublisher === null) {
            return;
        }

        $rawMetrics = $this->ownWorkerMetrics($this->metricsPublisher->getAllWorkerMetrics());

        // Build the format AutoScaler expects, keyed by the worker's PID so
        // scale-down can match against our child PID set.
        $workerMetrics = [];
        foreach ($rawMetrics as $workerId => $data) {
            $pid = (int) ($data['pid'] ?? (WorkerId::parse($workerId)->pid ?? 0));

            if ($pid > 0) {
                $workerMetrics[$pid] = [
                    'active' => (int) ($data['active_slots'] ?? 0),
                    'total' => (int) ($data['total_slots'] ?? 0),
                ];
            }
        }

        $decision = $this->autoScaler->evaluate($this->workerCount, $workerMetrics);

        match ($decision) {
            ScaleDecision::ScaleUp => $this->scaleUp(),
            ScaleDecision::ScaleDown => $this->scaleDown($workerMetrics),
            ScaleDecision::NoChange => null,
        };
    }

    /**
     * Filter a worker-metrics map down to this master's own children.
     *
     * During a takeover two fleets publish side by side; autoscaling and the
     * aggregate metrics must only ever describe our own.
     *
     * @param  array<string, array<string, string>>  $workers
     * @return array<string, array<string, string>>
     */
    private function ownWorkerMetrics(array $workers): array
    {
        return array_filter(
            $workers,
            function (array $data, string $workerId): bool {
                $pid = (int) ($data['pid'] ?? (WorkerId::parse($workerId)->pid ?? 0));

                return isset($this->workerPids[$pid]);
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Scale up by spawning one additional worker.
     */
    private function scaleUp(): void
    {
        ($this->logger)("Autoscaler: scaling up (workers: {$this->workerCount} -> ".($this->workerCount + 1).')');
        $this->spawnWorker();
        $this->autoScaler?->recordAction();
    }

    /**
     * Scale down by sending SIGTERM to the least busy worker.
     *
     * The targeted worker is added to {@see $scalingDownPids} so
     * the monitor loop does not respawn it after it drains and exits.
     *
     * @param  array<int, array{active: int, total: int}>  $workerMetrics  Keyed by worker PID.
     */
    private function scaleDown(array $workerMetrics): void
    {
        // Build a PID-to-active-slots map for workers we currently own; the
        // metrics map is keyed by PID (see evaluateAutoscale). A child with
        // no metrics row yet sorts last so a just-forked worker is never the
        // scale-down victim.
        $pidActivity = [];

        foreach (array_keys($this->workerPids) as $pid) {
            $pidActivity[$pid] = (int) ($workerMetrics[$pid]['active'] ?? PHP_INT_MAX);
        }

        if ($pidActivity === []) {
            return;
        }

        // Pick the worker with the lowest active slot count.
        asort($pidActivity);
        $targetPid = array_key_first($pidActivity);

        ($this->logger)("Autoscaler: scaling down (workers: {$this->workerCount} -> "
            .($this->workerCount - 1)."), sending SIGTERM to PID {$targetPid}");

        $this->scalingDownPids[$targetPid] = true;
        posix_kill($targetPid, SIGTERM);
        $this->autoScaler?->recordAction();
    }

    /**
     * Send a signal to all child worker processes.
     */
    private function signalChildren(int $signal): void
    {
        foreach (array_keys($this->workerPids) as $pid) {
            posix_kill($pid, $signal);
        }
    }

    /**
     * Drive the drain state machine on each monitor tick.
     *
     * On the first tick after a SIGUSR2, write the Redis `paused` key so
     * workers stop picking up new jobs (they observe the key on their own
     * 2s poll) and start the pause timer. Escalation to the same SIGTERM path
     * `torque:stop` uses happens as soon as the fleet reports nothing in
     * flight, or when `reload_pause_seconds` runs out, whichever comes first;
     * the workers' own `drain_grace_seconds` then caps how long they wait for
     * their current job before hard-exiting.
     *
     * The pause is a ceiling, not a wait. Sleeping it out unconditionally kept
     * pickup paused across an idle fleet for the whole window.
     */
    public function handleDrainTick(): void
    {
        if ($this->drainRequested && ! $this->draining) {
            $this->draining = true;
            $this->drainStartedAt = microtime(true);
            $this->beginDrain();
        }

        if ($this->draining && ! $this->shouldStop) {
            $idle = $this->fleetIsIdle();

            if ($idle || (microtime(true) - $this->drainStartedAt) >= DrainSettings::reloadPauseSeconds($this->config)) {
                ($this->logger)($idle
                    ? 'Fleet reports no jobs in flight, signaling workers to stop.'
                    : 'Drain pause elapsed, signaling workers to stop.');
                $this->shouldStop = true;
                $this->signalChildren(SIGTERM);
                $this->draining = false;
            }
        }
    }

    /**
     * Ask Redis whether any of our own workers still has a slot busy.
     *
     * Best-effort by design: without a metrics publisher, or with Redis
     * unreachable, this reports "not idle" so the drain falls back to the
     * pause timer. Cutting a drain short on a Redis blip would kill in-flight
     * jobs; waiting one window too long only costs time.
     */
    private function fleetIsIdle(): bool
    {
        if ($this->metricsPublisher === null) {
            return false;
        }

        try {
            $workers = $this->ownWorkerMetrics($this->metricsPublisher->getAllWorkerMetrics());
        } catch (\Throwable) {
            return false;
        }

        return self::fleetDrainComplete($workers);
    }

    /**
     * True when every worker in the snapshot reports zero active slots.
     *
     * An empty snapshot is deliberately *not* drain-complete: it means the
     * heartbeats could not be read (Redis down, metrics disabled, keys expired
     * mid-drain), not that the fleet is idle. A fleet that genuinely has no
     * children left is handled by the monitor loop's reaper instead.
     *
     * `active_slots` is the publish-interval peak rather than the instant
     * value, so it lags towards "still busy" for up to one tick. That is the
     * safe direction to be wrong in.
     *
     * @param  array<string, array<string, string>>  $workers  Worker id => Redis heartbeat hash.
     */
    public static function fleetDrainComplete(array $workers): bool
    {
        if ($workers === []) {
            return false;
        }

        foreach ($workers as $data) {
            if ((int) ($data['active_slots'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run Redis housekeeping on the first monitor tick and every
     * `dead_letter.prune_interval` seconds after that.
     *
     * Trims the dead-letter stream (TTL plus hard cap) and sweeps consumer
     * names left behind by exited workers, so neither can grow without bound
     * on installations that never scheduled `torque:prune`. Set the interval
     * to 0 to disable and keep pruning purely scheduler-driven.
     *
     * Entirely best-effort: a Redis outage logs one line and re-arms the
     * timer. The master must keep supervising workers regardless, the same
     * way {@see warnIfPaused()} tolerates Redis being down at boot.
     *
     * Public so the cadence can be exercised without entering the monitor loop.
     */
    public function handleHousekeepingTick(): void
    {
        $interval = (int) ($this->config['dead_letter']['prune_interval'] ?? 300);

        if ($interval <= 0) {
            return;
        }

        $now = time();

        if ($this->housekeepingDueAt !== null && $now < $this->housekeepingDueAt) {
            return;
        }

        $this->housekeepingDueAt = $now + $interval;

        try {
            // New each run, with its own connections: its client has no read timeout,
            // so a socket silently dropped in the wait between runs would stall this
            // synchronous loop, and with it reaping and the watchdog, until TCP gives
            // up. Only the scan position of a capped sweep is kept.
            $housekeeper = StreamHousekeeper::fromConfig($this->config)
                ->resumeRedeliverySweep($this->redeliveryCursors);

            $deadLetter = $housekeeper->pruneDeadLetter();

            if ($deadLetter['before'] !== $deadLetter['after']) {
                ($this->logger)("Housekeeping: dead-letter trimmed from {$deadLetter['before']} to {$deadLetter['after']} entries.");
            }

            // A live worker touches its consumer on every poll, so anything
            // idle for longer than a full worker lifetime belongs to a
            // process that is gone.
            $idleSeconds = max(3600, (int) ($this->config['max_worker_lifetime'] ?? 3600));
            $removed = array_sum($housekeeper->pruneConsumers(
                $idleSeconds,
                redeliveryFieldLimit: StreamHousekeeper::REDELIVERY_FIELDS_PER_SWEEP,
            ));

            $this->redeliveryCursors = $housekeeper->redeliveryCursors();

            if ($removed > 0) {
                ($this->logger)("Housekeeping: removed {$removed} stale stream consumers.");
            }
        } catch (\Throwable $e) {
            ($this->logger)("Housekeeping failed ({$e->getMessage()}); retrying in {$interval}s.");
        }
    }

    /**
     * Alert when a configured queue's oldest waiting job passes its `waits`
     * threshold. Only queues with a threshold are read, one call each, at most
     * every {@see LONG_WAIT_CHECK_INTERVAL} seconds. Best-effort like
     * {@see handleHousekeepingTick()}: a Redis error logs one line and the
     * timer is re-armed.
     *
     * Public, with an injectable clock, so it runs without the monitor loop.
     */
    public function handleLongWaitTick(?float $now = null): void
    {
        $thresholds = LongWaitGate::thresholds((array) ($this->config['waits'] ?? []));

        if ($thresholds === []) {
            return;
        }

        $now ??= microtime(true);

        $this->superviseLongWaitAlerts();

        // A drain pauses pickup, so age keeps rising without anything being wrong.
        if ($this->shouldStop || $this->draining || $this->drainRequested) {
            return;
        }

        if ($this->longWaitDueAt !== null && $now < $this->longWaitDueAt) {
            return;
        }

        $this->longWaitDueAt = $now + self::LONG_WAIT_CHECK_INTERVAL;
        $gate = $this->longWaitGate ??= new LongWaitGate;

        try {
            $queue = new StreamQueue(
                redisUri: $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
                default: 'default',
                prefix: $this->config['redis']['prefix'] ?? 'torque:',
                consumerGroup: $this->config['consumer_group'] ?? 'torque',
                cluster: (bool) ($this->config['redis']['cluster'] ?? false),
                serializer: (string) ($this->config['serializer'] ?? 'json'),
            );

            foreach ($thresholds as $name => $threshold) {
                $createdAt = $queue->creationTimeOfOldestPendingJob($name);
                $age = $createdAt === null ? null : max(0.0, $now - $createdAt);

                if ($gate->shouldAlert($name, $age, $threshold, $now)
                    && ! $this->alertLongWait($name, (int) floor($age ?? 0), $threshold)) {
                    $gate->rearm($name);
                }
            }
        } catch (\Throwable $e) {
            ($this->logger)("Long-wait check failed ({$e->getMessage()}); retrying in ".self::LONG_WAIT_CHECK_INTERVAL.'s.');
        }
    }

    /**
     * Log the alert and leave the event and the notifications to a child process.
     *
     * A listener or a mail server can take as long as it likes, and in this loop
     * that would stop reaping, respawning, drain and the watchdog. The child is
     * fork + exec like a worker, not proc_open: its handle would block in waitpid
     * when destroyed, so a master exiting would wait for a hanging alert.
     *
     * The child gets its own session, so that a kill reaches whatever it started,
     * and an empty signal mask, since exec keeps the mask {@see monitor()} blocks
     * SIGTERM with. If the master dies first, the child's own limit still applies,
     * and an alarm set here covers a boot that hangs before the command can start
     * counting: it survives exec, and SIGALRM's default action ends the process, so
     * nothing has to handle it. Set to default first, as an ignored signal stays
     * ignored through exec. PHP on Apple Silicon runs its own time limit on SIGALRM:
     * there it is a fatal error at PHP's next instruction, so a hang inside a C call
     * lasts until the master's kill.
     *
     * @return bool False if no child was started, so the gate can be re-armed.
     */
    private function alertLongWait(string $queue, int $seconds, int $threshold): bool
    {
        ($this->logger)("WARN Long wait on queue {$queue}: oldest job has waited {$seconds}s (threshold {$threshold}s).");

        if (count($this->longWaitAlerts) >= self::LONG_WAIT_ALERT_MAX_CHILDREN) {
            ($this->logger)("Long-wait alert for queue {$queue} not started: ".self::LONG_WAIT_ALERT_MAX_CHILDREN.' alerts are still running; trying again at the next check.');

            return false;
        }

        $limit = max(1, (int) ($this->config['long_wait_alert_seconds'] ?? self::LONG_WAIT_ALERT_SECONDS));
        $pid = pcntl_fork();

        if ($pid === -1) {
            ($this->logger)("Long-wait alert for queue {$queue} not started: fork failed; trying again at the next check.");

            return false;
        }

        if ($pid === 0) {
            @posix_setsid();
            pcntl_sigprocmask(SIG_SETMASK, []);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_alarm($limit + self::LONG_WAIT_ALERT_KILL_SLACK);

            pcntl_exec(PHP_BINARY, [
                $this->artisanPath,
                'torque:long-wait-alert',
                $queue,
                (string) $seconds,
                (string) $threshold,
                "--limit={$limit}",
            ]);

            // Not exit(): that would run the shutdown work of a copy of the master.
            // The master reads a SIGKILL it did not send as a child that never ran.
            posix_kill((int) getmypid(), SIGKILL);
        }

        $this->longWaitAlerts[$pid] = [
            'queue' => $queue,
            'killAt' => (int) hrtime(true) + ($limit + self::LONG_WAIT_ALERT_KILL_SLACK) * 1_000_000_000,
            'killed' => false,
        ];

        return true;
    }

    /**
     * Reap alert children that exited and kill those past their deadline.
     *
     * Each is waited for by its own PID, so a worker's exit is never taken here.
     * A killed child stays listed until it is reaped, which keeps its PID its own.
     */
    public function superviseLongWaitAlerts(): void
    {
        foreach ($this->longWaitAlerts as $pid => $alert) {
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);

            if ($reaped === $pid) {
                $this->forgetLongWaitAlert($pid, $status);
            } elseif ($reaped === -1) {
                unset($this->longWaitAlerts[$pid]);
            } elseif (! $alert['killed'] && hrtime(true) >= $alert['killAt']) {
                ($this->logger)("Long-wait alert for queue {$alert['queue']} ran past its limit, killing alert child PID {$pid}.");
                $this->longWaitAlerts[$pid]['killed'] = true;
                posix_kill(-$pid, SIGKILL);
                posix_kill($pid, SIGKILL);
            }
        }
    }

    /**
     * Act on a reaped alert child's exit status, the one result the master sees.
     *
     * A failure brings the next alert forward rather than waiting out the cooldown.
     * Not to the next check: a channel that delivered would then send again every 30s.
     * A SIGKILL the master did not send means exec failed or the system killed the
     * child, so most likely nothing ran, and the gate is re-armed outright.
     */
    private function forgetLongWaitAlert(int $pid, int $status): void
    {
        $alert = $this->longWaitAlerts[$pid];

        unset($this->longWaitAlerts[$pid]);

        $reason = match (true) {
            pcntl_wifexited($status) => pcntl_wexitstatus($status) === 0 ? null : 'exit code '.pcntl_wexitstatus($status),
            pcntl_wifsignaled($status) => 'killed by signal '.pcntl_wtermsig($status),
            default => 'unknown status',
        };

        if ($reason === null) {
            return;
        }

        if (pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL && ! $alert['killed']) {
            $this->longWaitGate?->rearm($alert['queue']);
            ($this->logger)("Long-wait alert for queue {$alert['queue']} failed ({$reason}); trying again at the next check.");

            return;
        }

        $this->longWaitGate?->retryAfter($alert['queue'], self::LONG_WAIT_ALERT_RETRY_SECONDS);
        ($this->logger)("Long-wait alert for queue {$alert['queue']} failed ({$reason}); the next one may come after ".self::LONG_WAIT_ALERT_RETRY_SECONDS.'s.');
    }

    /**
     * Surface a pre-existing pause at boot: a master starting into a paused
     * queue (deliberate `torque:pause`, or a not-yet-expired drain flag)
     * spawns workers that pick up nothing, which otherwise looks like a
     * silent hang in the supervisor log.
     *
     * A drain pause whose master is gone is cleared first, so the warning is
     * only ever about a pause that still has an owner.
     */
    private function warnIfPaused(): void
    {
        if ($this->clearStaleDrainPause()) {
            return;
        }

        try {
            $redisUri = $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
            $prefix = $this->config['redis']['prefix'] ?? 'torque:';

            $value = createRedisClient($redisUri)->execute('GET', $prefix.'paused');

            if ($value === null) {
                return;
            }

            $ownerPid = DrainPause::ownerPid((string) $value);

            $reason = $ownerPid === null
                ? 'deliberate `torque:pause`'
                : "drain from master PID {$ownerPid}, which is still running";

            ($this->logger)('Queue is PAUSED ('.$reason.', Redis '.$prefix.'paused is set); workers will not pick up jobs. Run `torque:pause continue` to resume.');
        } catch (\Throwable) {
            // Boot must not depend on Redis being up; workers retry on their own.
        }
    }

    /**
     * Delete a `drain:<pid>` pause key whose master is no longer running.
     *
     * A drain pause belongs to exactly one master: it stops that master's own
     * fleet while in-flight jobs finish. When that master is gone (clean exit,
     * killed by the supervisor, OOM) the key still lives out its TTL of
     * pause + grace + 60, and installations that raised the grace to survive
     * long jobs (7200s on one production instance) then start into a queue
     * that reads as PAUSED for two hours after a killed reload. The
     * replacement master clears it at boot instead of honouring it.
     *
     * Only drain values are touched. A deliberate `torque:pause` writes a
     * TTL-less generic value and is never cleared automatically: an operator
     * paused it, an operator resumes it.
     *
     * The delete is a compare-and-delete on the exact value we judged, so a
     * fresh drain or a `torque:pause` landing between the read and the write
     * survives. Best-effort like the rest of the boot path: a Redis outage
     * leaves the key alone and logs nothing.
     *
     * Public (and with an injectable liveness probe) so the sweep can be
     * exercised without a live master. Call it only at start: during our own
     * drain the key legitimately carries our PID.
     *
     * @param  (callable(int): bool)|null  $isMasterAlive  Defaults to the real
     *                                                     process probe.
     * @return bool Whether a stale key was cleared.
     */
    public function clearStaleDrainPause(?callable $isMasterAlive = null): bool
    {
        $isMasterAlive ??= ProcessInspector::isTorqueMaster(...);

        try {
            $redisUri = $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
            $prefix = $this->config['redis']['prefix'] ?? 'torque:';

            $redis = createRedisClient($redisUri);
            $value = $redis->execute('GET', $prefix.'paused');
            $value = $value === null ? null : (string) $value;

            $selfPid = getmypid();

            if ($selfPid === false || ! DrainPause::isStale($value, $selfPid, $isMasterAlive)) {
                return false;
            }

            $deleted = (int) $redis->execute(
                'EVAL',
                self::LUA_DELETE_IF_EQUAL,
                '1',
                $prefix.'paused',
                (string) $value,
            );

            if ($deleted === 0) {
                return false;
            }

            ($this->logger)('Cleared stale drain pause left by master PID '.DrainPause::ownerPid($value).' (not running).');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Run the one-off data upgrade for the installed Torque version.
     *
     * Deploying over an older release leaves keys behind that the new code no
     * longer writes or expires, so the first master start after an upgrade
     * cleans them up and records the version in `{prefix}version`. Subsequent
     * starts on the same version do nothing.
     *
     * Best-effort like {@see warnIfPaused()}: startup must never depend on
     * Redis being reachable, and a failed attempt simply runs again next time.
     *
     * The runner is injectable so the once-per-version contract can be
     * exercised against a pinned version instead of whatever this checkout
     * happens to report.
     */
    private function runDataUpgrade(?UpgradeRunner $runner = null): void
    {
        try {
            ($runner ?? UpgradeRunner::fromConfig($this->config, $this->logger))->run();
        } catch (\Throwable $e) {
            ($this->logger)("Data upgrade skipped ({$e->getMessage()}); it runs again on the next start.");
        }
    }

    /**
     * Whether the stored data version is one this release can upgrade from.
     *
     * Deliberately not part of the best-effort {@see runDataUpgrade()}: a
     * refusal has to stop the start. A Redis outage is not a refusal, the
     * runner passes on that itself.
     */
    private function upgradePathAllowed(): bool
    {
        try {
            UpgradeRunner::fromConfig($this->config, $this->logger)->assertUpgradePath();
        } catch (\DomainException $e) {
            ($this->logger)($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Mark workers paused via Redis so they stop picking new jobs.
     *
     * Best-effort: if Redis is unreachable we still proceed to the timed
     * SIGTERM in {@see handleDrainTick}; workers may not see the pause flag
     * but their own SIGTERM grace lets them finish their current job.
     *
     * The key carries an expiry (grace + 60s): a drain's pause only needs to
     * outlive the drain window itself. Without one, the key survives the
     * master handover -- the draining master exits, the replacement starts,
     * and nothing ever deletes it, so every reload leaves the whole queue
     * permanently paused until a manual `torque:pause continue`. A deliberate
     * `torque:pause` still sets the key without a TTL and is unaffected.
     *
     * The TTL is the backstop, not the guarantee: it can be hours long on
     * installations with a big `drain_grace_seconds`, so the next master to
     * start also clears the key when this PID is gone
     * (see {@see clearStaleDrainPause()}).
     */
    private function beginDrain(): void
    {
        try {
            $redisUri = $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
            $prefix = $this->config['redis']['prefix'] ?? 'torque:';

            // The value scopes the pause to THIS master's fleet: workers
            // compare the embedded PID against their own parent and ignore a
            // drain that is not theirs, so a draining old master never pauses
            // the replacement fleet during a takeover. A deliberate
            // `torque:pause` writes a TTL-less generic value that pauses
            // every fleet (see WorkerProcess::shouldPauseFor()).
            createRedisClient($redisUri)
                ->execute('SET', $prefix.'paused', 'drain:'.getmypid(), 'EX', (string) DrainSettings::drainPauseTtlSeconds($this->config));
        } catch (\Throwable $e) {
            ($this->logger)("Drain: failed to set Redis paused key ({$e->getMessage()}); proceeding with timed SIGTERM.");
        }

        ($this->logger)('Draining: pickup paused, waiting up to '.DrainSettings::reloadPauseSeconds($this->config).'s for in-flight jobs.');
    }

    /**
     * Get the path to the PID file.
     */
    public static function pidFilePath(): string
    {
        return storage_path('torque.pid');
    }

    /**
     * Get the path to the master lock file.
     */
    public static function lockFilePath(): string
    {
        return storage_path('torque.lock');
    }

    /**
     * Try to take the exclusive master lock without blocking.
     *
     * The lock is held for the process lifetime; the kernel releases it on
     * any exit (including SIGKILL), which makes it the reliable mutual
     * exclusion between masters where pgrep sweeps and PID files are racy.
     * That holds because of the `e` mode: every child of this process execs,
     * which closes its copy of the handle, and nothing relies on a child
     * holding the lock.
     */
    private function tryAcquireMasterLock(): bool
    {
        $path = self::lockFilePath();

        if (is_link($path)) {
            ($this->logger)("Refusing to use master lock file {$path}: it is a symlink.");

            return false;
        }

        $existed = file_exists($path);
        $handle = @fopen($path, 'ce');

        if ($handle === false) {
            // Not "lock held": the file cannot even be opened. Seen in the
            // wild when deploy tooling chowns storage to the web user and the
            // supervised master (a different user) loses write access; saying
            // "another master holds the lock" sent the operator hunting a
            // ghost process. Report the real failure.
            ($this->logger)(sprintf(
                'Cannot open master lock file %s (%s); fix ownership/permissions of the storage directory.',
                $path,
                error_get_last()['message'] ?? 'unknown error',
            ));

            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        if (! $existed) {
            // Group-writable from birth: survives a deploy-time chown to the
            // web user (see writePidFile).
            @chmod($path, 0664);
        }

        $this->lockHandle = $handle;

        return true;
    }

    /**
     * Per-second lease maintenance from the monitor loop.
     *
     * - A takeover master acquires the master lock lazily, the moment the
     *   drained old master exits and the kernel releases it.
     * - The PID file self-heals: rewritten when missing, reclaimed when it
     *   holds a dead or foreign PID, and — the losing side of a clobber
     *   race — this master self-demotes into a drain when another LIVE
     *   torque master owns the file.
     */
    private function maintainLease(): void
    {
        if ($this->lockHandle === null && $this->tryAcquireMasterLock()) {
            ($this->logger)('Master lock acquired.');
        }

        if (! $this->ownsPidFile || $this->shouldStop) {
            return;
        }

        $path = self::pidFilePath();

        if (is_link($path)) {
            return;
        }

        $raw = @file_get_contents($path);
        $pidInFile = $raw === false ? null : (int) $raw;

        if ($pidInFile === getmypid()) {
            return;
        }

        if ($pidInFile !== null && $pidInFile > 0 && ProcessInspector::isTorqueMaster($pidInFile)) {
            ($this->logger)("PID file is owned by live master {$pidInFile}; self-demoting into a drain.");
            $this->ownsPidFile = false;
            $this->drainRequested = true;

            return;
        }

        if (! $this->draining && ! $this->drainRequested) {
            ($this->logger)($raw === false
                ? 'PID file missing; rewriting.'
                : "PID file held stale PID {$pidInFile}; reclaiming.");

            try {
                $this->writePidFile();
            } catch (\Throwable $e) {
                ($this->logger)("PID file rewrite failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Wait for at least one of this master's own workers to publish a
     * metrics heartbeat, proving the new fleet actually boots and runs.
     *
     * Children are reaped (not respawned) while waiting; a fleet whose every
     * worker died is reported failed immediately.
     */
    private function waitForOwnFleetReady(): bool
    {
        $timeout = (float) ($this->config['takeover_ready_timeout'] ?? 30.0);
        $deadline = microtime(true) + $timeout;

        $publisher = new MetricsPublisher(
            redisUri: $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
            prefix: $this->config['redis']['prefix'] ?? 'torque:',
            settings: $this->config['metrics'] ?? [],
        );

        while (microtime(true) < $deadline && ! $this->shouldStop) {
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                $this->forgetWorker($pid);
                ($this->logger)("Worker PID {$pid} exited during takeover readiness.");
            }

            if ($this->workerPids === []) {
                return false;
            }

            try {
                foreach ($publisher->getAllWorkerMetrics() as $data) {
                    if (isset($this->workerPids[(int) ($data['pid'] ?? 0)])) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                // Redis hiccup: keep polling until the deadline.
            }

            usleep(250_000);
        }

        return false;
    }

    /**
     * Ask the old master to drain, master-to-master.
     *
     * Idempotent with torque:reload's own signal; this copy covers a reload
     * process that died between spawning us and signalling. Only a process
     * positively identified as a torque master is ever signalled: SIGUSR2's
     * default disposition terminates, so a recycled PID must never receive it.
     */
    private function signalOldMaster(): void
    {
        $oldPid = $this->takeoverPid;

        if ($oldPid === null || $oldPid === getmypid()) {
            return;
        }

        if (! posix_kill($oldPid, 0)) {
            ($this->logger)("Old master (PID {$oldPid}) already exited.");

            return;
        }

        $cmdline = ProcessInspector::commandLine($oldPid);

        if ($cmdline === null || ! str_contains($cmdline, 'torque:start')) {
            ($this->logger)("PID {$oldPid} is not identifiably a torque master; not signalling it.");

            return;
        }

        posix_kill($oldPid, SIGUSR2);
        ($this->logger)("Signalled old master (PID {$oldPid}) to drain.");
    }

    /**
     * Publish the aggregated fleet metrics from the monitor loop.
     *
     * Throughput is a real jobs-per-second computed from the delta of
     * processed+failed counters between publishes; worker restarts reset
     * their counters, so negative deltas clamp to zero rather than
     * publishing nonsense.
     */
    private function publishAggregateMetrics(): void
    {
        if ($this->metricsPublisher === null || ! ($this->config['metrics']['enabled'] ?? true)) {
            return;
        }

        $interval = max(1, (int) ($this->config['metrics']['publish_interval'] ?? 1));
        $now = microtime(true);

        if ($this->lastAggregateAt !== null && ($now - $this->lastAggregateAt) < $interval) {
            return;
        }

        try {
            $workers = $this->ownWorkerMetrics($this->metricsPublisher->getAllWorkerMetrics());
            $aggregate = $this->metricsPublisher->aggregateFromWorkers($workers);

            $jobsFailed = (int) $aggregate['jobs_failed'];
            $jobsTotal = (int) $aggregate['jobs_processed'] + $jobsFailed;
            $perQueue = is_array($aggregate['per_queue'] ?? null) ? $aggregate['per_queue'] : [];
            $perJob = is_array($aggregate['per_job'] ?? null) ? $aggregate['per_job'] : [];

            if ($this->lastAggregateJobsTotal !== null && $this->lastAggregateAt !== null && $now > $this->lastAggregateAt) {
                $delta = $jobsTotal - $this->lastAggregateJobsTotal;
                $aggregate['throughput'] = $delta > 0
                    ? round($delta / ($now - $this->lastAggregateAt), 2)
                    : 0.0;

                // Persist the same deltas into the metric rollups. The dashboard
                // reads those for a damped rate, exact daily counts and a
                // history that outlives any single page load. A worker that
                // restarted resets its counters, so every delta is clamped the
                // same way the throughput above is.
                $failedDelta = max(0, $jobsFailed - (int) $this->lastAggregateJobsFailed);

                $this->metricsPublisher->recordOutcomes(
                    max(0, $delta - $failedDelta),
                    $failedDelta,
                    $this->perQueueDeltas($perQueue),
                );

                $this->metricsPublisher->recordJobOutcomes($this->perJobDeltas($perJob));

                ['deltas' => $hostDeltas, 'counters' => $workerCounters] = self::hostDeltas(
                    $workers,
                    $this->lastWorkerCounters ?? [],
                );

                $this->metricsPublisher->recordHostOutcomes($hostDeltas);
                $this->lastWorkerCounters = $workerCounters;
            }

            // Gauges are point-in-time, so unlike the counters they are sampled
            // on every tick including the first.
            $this->metricsPublisher->recordGauges($this->gaugeSamples($aggregate));
            $this->metricsPublisher->recordHostGauges($this->hostGaugeSamples($workers));

            $this->lastAggregateJobsTotal = $jobsTotal;
            $this->lastAggregateJobsFailed = $jobsFailed;
            $this->lastAggregatePerQueue = $perQueue;
            $this->lastAggregatePerJob = $perJob;
            $this->lastAggregateAt = $now;

            $this->metricsPublisher->publishAggregate($aggregate);
        } catch (\Throwable) {
            // Metrics must never take the master down.
        }
    }

    /**
     * Per-stream deltas since the previous publish, clamped at zero.
     *
     * @param  array<string, array{0: int, 1: int}>  $perQueue  Cumulative counters.
     * @return array<string, array{0: int, 1: int}>
     */
    private function perQueueDeltas(array $perQueue): array
    {
        $deltas = [];

        foreach ($perQueue as $queue => [$processed, $failed]) {
            [$lastProcessed, $lastFailed] = $this->lastAggregatePerQueue[$queue] ?? [0, 0];

            $processedDelta = max(0, (int) $processed - (int) $lastProcessed);
            $failedDelta = max(0, (int) $failed - (int) $lastFailed);

            if ($processedDelta > 0 || $failedDelta > 0) {
                $deltas[(string) $queue] = [$processedDelta, $failedDelta];
            }
        }

        return $deltas;
    }

    /**
     * Per-job-class deltas since the previous publish.
     *
     * Counters and the runtime sum are cumulative and clamp at zero the way the
     * stream deltas do. The runtime high-water mark is not cumulative: it is
     * whatever the largest run reported this tick was, so it passes through.
     *
     * @param  array<string, array{0: int, 1: int, 2: float, 3: float}>  $perJob  Cumulative counters.
     * @return array<string, array{0: int, 1: int, 2: float, 3: float}>
     */
    private function perJobDeltas(array $perJob): array
    {
        $deltas = [];

        foreach ($perJob as $class => [$processed, $failed, $runtimeSum, $runtimeMax]) {
            [$lastProcessed, $lastFailed, $lastRuntimeSum] = $this->lastAggregatePerJob[$class] ?? [0, 0, 0.0, 0.0];

            $processedDelta = max(0, (int) $processed - (int) $lastProcessed);
            $failedDelta = max(0, (int) $failed - (int) $lastFailed);

            if ($processedDelta > 0 || $failedDelta > 0) {
                $deltas[(string) $class] = [
                    $processedDelta,
                    $failedDelta,
                    max(0.0, (float) $runtimeSum - (float) $lastRuntimeSum),
                    (float) $runtimeMax,
                ];
            }
        }

        return $deltas;
    }

    /**
     * The host a worker runs on: the published field, or the host half of the
     * `{host}-{pid}-{hex}` id for rows written by older code.
     *
     * @param  array<string, mixed>  $worker
     */
    private static function hostOf(string $workerId, array $worker): string
    {
        $host = trim((string) ($worker['host'] ?? ''));

        return $host !== '' ? $host : WorkerId::parse($workerId)->host;
    }

    /**
     * Fold per-worker counter deltas into per-host outcome deltas.
     *
     * `ownWorkerMetrics()` has already narrowed the input to this master's own
     * children, so on a multi-host cluster every master writes only its own
     * host and no two ever contend for the same field.
     *
     * Pure and static so the fold can be tested without a fleet or a Redis,
     * the way {@see fleetDrainComplete()} is.
     *
     * @param  array<string, array<string, mixed>>  $workers  Raw worker hashes, this tick.
     * @param  array<string, array{0: int, 1: int}>  $previous  The same map, previous tick.
     * @return array{deltas: array<string, array{0: int, 1: int}>, counters: array<string, array{0: int, 1: int}>}
     */
    #[\NoDiscard]
    public static function hostDeltas(array $workers, array $previous): array
    {
        $deltas = [];
        $counters = [];

        foreach ($workers as $workerId => $worker) {
            $workerId = (string) $workerId;
            $processed = (int) ($worker['jobs_processed'] ?? 0);
            $failed = (int) ($worker['jobs_failed'] ?? 0);

            // Rebuilt from this tick's ids only, so a rotated worker's id falls
            // out and the map stays bounded by the live fleet.
            $counters[$workerId] = [$processed, $failed];

            // First sighting: record the baseline, contribute nothing.
            if (! isset($previous[$workerId])) {
                continue;
            }

            $host = self::hostOf($workerId, $worker);

            if ($host === '') {
                continue;
            }

            [$lastProcessed, $lastFailed] = $previous[$workerId];

            $processedDelta = max(0, $processed - (int) $lastProcessed);
            $failedDelta = max(0, $failed - (int) $lastFailed);

            if ($processedDelta === 0 && $failedDelta === 0) {
                continue;
            }

            [$hostProcessed, $hostFailed] = $deltas[$host] ?? [0, 0];
            $deltas[$host] = [$hostProcessed + $processedDelta, $hostFailed + $failedDelta];
        }

        return ['deltas' => $deltas, 'counters' => $counters];
    }

    /**
     * Per-host gauge samples for this tick.
     *
     * Slot pressure is what the workers screen exists to show, worker count is
     * the "that box lost half its fleet at 02:00" signal that slots alone
     * cannot give when workers have different slot counts, and memory is what
     * decides a recycle.
     *
     * Latency is deliberately absent: a worker's `avg_latency_ms` is an average
     * over its whole life, so bucketing it would draw a nearly flat line that
     * reads like a measurement and is not one. That needs a windowed latency in
     * the collector first.
     *
     * @param  array<string, array<string, mixed>>  $workers
     * @return array<string, array<string, float>>
     */
    private static function hostGaugeSamples(array $workers): array
    {
        $hosts = [];

        foreach ($workers as $workerId => $worker) {
            $host = self::hostOf((string) $workerId, $worker);

            if ($host === '') {
                continue;
            }

            $memoryMb = ((float) ($worker['memory_bytes'] ?? 0)) / 1_048_576;

            $sample = $hosts[$host] ?? [
                MetricsPublisher::GAUGE_HOST_BUSY_SLOTS => 0.0,
                MetricsPublisher::GAUGE_HOST_TOTAL_SLOTS => 0.0,
                MetricsPublisher::GAUGE_HOST_MEMORY => 0.0,
                MetricsPublisher::GAUGE_HOST_WORKER_MEMORY_PEAK => 0.0,
                MetricsPublisher::GAUGE_HOST_WORKERS => 0.0,
            ];

            $sample[MetricsPublisher::GAUGE_HOST_BUSY_SLOTS] += (float) ($worker['active_slots'] ?? 0);
            $sample[MetricsPublisher::GAUGE_HOST_TOTAL_SLOTS] += (float) ($worker['total_slots'] ?? 0);
            $sample[MetricsPublisher::GAUGE_HOST_MEMORY] += $memoryMb;
            // Slots and memory sum across the box; the peak is the largest
            // single worker, which is the number a recycle threshold is set on.
            $sample[MetricsPublisher::GAUGE_HOST_WORKER_MEMORY_PEAK] = max(
                $sample[MetricsPublisher::GAUGE_HOST_WORKER_MEMORY_PEAK],
                $memoryMb,
            );
            $sample[MetricsPublisher::GAUGE_HOST_WORKERS]++;

            $hosts[$host] = $sample;
        }

        return $hosts;
    }

    /**
     * Gauge samples for this tick: the fleet numbers plus the queue depth.
     *
     * Depth is the one figure the aggregate cannot supply, so it is probed here
     * with one script call per stream. A stream whose consumer group
     * does not exist yet must not cost us the rest of the samples, hence the
     * per-stream rescue.
     *
     * @param  array<string, mixed>  $aggregate
     * @return array<string, float>
     */
    private function gaugeSamples(array $aggregate): array
    {
        $pending = 0;
        $delayed = 0;
        $probe = $this->depthProbe ??= rescue(fn (): StreamQueue => StreamQueueResolver::make(), null, false);

        if ($probe !== null) {
            foreach (array_keys((array) ($this->config['streams'] ?? [])) as $stream) {
                // One round trip per stream; pending is the waiting count.
                $depths = rescue(fn (): array => $probe->depths((string) $stream), null, false);
                $pending += (int) ($depths['waiting'] ?? 0);
                $delayed += (int) ($depths['delayed'] ?? 0);
            }
        }

        return [
            MetricsPublisher::GAUGE_LATENCY => (float) ($aggregate['avg_latency'] ?? 0),
            MetricsPublisher::GAUGE_CONCURRENT => (float) ($aggregate['concurrent'] ?? 0),
            MetricsPublisher::GAUGE_MEMORY => (float) ($aggregate['memory_mb'] ?? 0),
            MetricsPublisher::GAUGE_WORKER_MEMORY_PEAK => (float) ($aggregate['memory_peak_mb'] ?? 0),
            MetricsPublisher::GAUGE_PENDING => (float) $pending,
            MetricsPublisher::GAUGE_DELAYED => (float) $delayed,
        ];
    }

    /**
     * Write the master PID to the PID file atomically.
     *
     * Refuses to start if the PID path already exists as a symlink: unlinking
     * and recreating opens a small TOCTOU window where an attacker with write
     * access to the storage dir could redirect the rename.
     *
     * @throws \RuntimeException
     */
    private function writePidFile(): void
    {
        $path = self::pidFilePath();

        if (is_link($path)) {
            throw new \RuntimeException("Refusing to start: PID path {$path} is a symlink.");
        }

        $tmpPath = $path.'.'.getmypid().'.tmp';

        // The temp path is predictable, so a local user with write access to
        // storage/ could pre-plant a symlink there and have the write truncate
        // an arbitrary file. A stale leftover (or planted link) is removed
        // (unlink never follows a symlink), then the file is created with
        // fopen 'x' (O_CREAT|O_EXCL), which fails instead of following a link
        // that appears in the meantime.
        if (is_link($tmpPath) || file_exists($tmpPath)) {
            @unlink($tmpPath);
        }

        // Group-writable from birth: deploy tooling that chowns storage to the
        // web user (Deployer writable_mode=chown with a different http_user)
        // must not lock the supervised master's own runtime files away from
        // it. A umask-created mode avoids a follow-up chmod(), which would
        // follow a symlink swapped in after creation.
        $previousUmask = umask(0002);

        try {
            $handle = @fopen($tmpPath, 'x');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw new \RuntimeException("Failed to create temporary PID file at {$tmpPath} exclusively.");
        }

        $written = flock($handle, LOCK_EX) && fwrite($handle, (string) getmypid()) !== false;
        fclose($handle);

        if (! $written) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to write temporary PID file at {$tmpPath}.");
        }

        if (! rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to move PID file to {$path}.");
        }
    }

    /**
     * Remove the PID file on shutdown.
     *
     * After a zero-downtime reload the replacement master has already
     * rewritten `storage/torque.pid` with its own PID; the draining old
     * master must not clobber that, so only unlink when the file still
     * points at our own PID.
     */
    private function removePidFile(): void
    {
        $path = self::pidFilePath();

        if (! file_exists($path) || is_link($path)) {
            return;
        }

        $pidInFile = (int) @file_get_contents($path);

        if ($pidInFile === getmypid()) {
            @unlink($path);
        }
    }

    /**
     * Read the master PID from the PID file, or null if not running.
     */
    public static function readPid(): ?int
    {
        $path = self::pidFilePath();

        if (! file_exists($path) || is_link($path)) {
            return null;
        }

        $pid = (int) file_get_contents($path);

        if ($pid <= 0) {
            return null;
        }

        // The PID must be alive AND actually be a Torque master. The
        // command-line check guards against a recycled PID: when a stale
        // PID file survives a container restart on a bind mount, its
        // number is often reassigned to an unrelated process (php-fpm,
        // the test runner, ...) which posix_kill alone cannot tell apart
        // from a real master.
        //
        // Readers never unlink a stale file: during a takeover the old
        // master's death races the new master's atomic rename, and a reader
        // that unlinks in that window deletes the NEW master's entry. A dead
        // PID simply reads as "not running"; the owning master's lease
        // maintenance rewrites or reclaims the file on its own.
        if (posix_kill($pid, 0) && ProcessInspector::isTorqueMaster($pid)) {
            return $pid;
        }

        return null;
    }
}
