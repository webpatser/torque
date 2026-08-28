<?php

declare(strict_types=1);

namespace Webpatser\Torque\Process;

use Closure;
use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Manager\ScaleDecision;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Redis\UpgradeRunner;
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

    /** Stream depth probe for the pending gauge, opened once and reused. */
    private ?StreamQueue $depthProbe = null;

    private ?float $lastAggregateAt = null;

    /** Current number of running worker processes. */
    public int $workerCount {
        get => count($this->workerPids);
    }

    /**
     * @param  array<string, mixed>  $config  Merged Torque config.
     * @param  Closure(string): void  $logger  Callback for outputting status messages.
     */
    private readonly string $artisanPath;

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

        // Graceful drain: stop pickup, wait drain_grace_seconds for in-flight
        // jobs to finish, then SIGTERM workers. Used by `torque:reload`. The
        // handler stays trivial because the sync-signal path (Linux) reads
        // the flag from `pcntl_sigtimedwait` and the async path (macOS)
        // delivers into this same closure.
        pcntl_signal(SIGUSR2, function () {
            $this->drainRequested = true;
        });

        $this->warnIfPaused();
        $this->runDataUpgrade();

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
        ($this->logger)("Worker spawned with PID {$pid}");
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
                unset($this->workerPids[$pid]);

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

            // Promote a SIGUSR2 drain request into an active drain timer
            // and fire its watchdog if the grace period has elapsed.
            $this->handleDrainTick();

            if (++$maintenanceTick >= 10) {
                $maintenanceTick = 0;

                $this->maintainLease();
                $this->publishAggregateMetrics();
                $this->handleHousekeepingTick();

                if ($this->autoScaler !== null) {
                    $this->evaluateAutoscale();
                }
            }
        }

        ($this->logger)('All workers exited. Master shutting down.');

        return 0;
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
        $this->autoScaler->recordAction();
    }

    /**
     * Scale down by sending SIGTERM to the least busy worker.
     *
     * The targeted worker is added to {@see $scalingDownPids} so
     * the monitor loop does not respawn it after it drains and exits.
     *
     * @param  array<string, array{active: int, total: int}>  $workerMetrics
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
        $this->autoScaler->recordAction();
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
     * 2s poll) and start the grace timer. Once `drain_grace_seconds` has
     * elapsed, escalate to the same SIGTERM path `torque:stop` uses; the
     * workers' own `drain_grace_seconds` then caps how long they wait for
     * their current job before hard-exiting.
     */
    public function handleDrainTick(): void
    {
        if ($this->drainRequested && ! $this->draining) {
            $this->draining = true;
            $this->drainStartedAt = microtime(true);
            $this->beginDrain();
        }

        if ($this->draining && ! $this->shouldStop) {
            $grace = (int) ($this->config['drain_grace_seconds'] ?? 10);

            if ((microtime(true) - $this->drainStartedAt) >= $grace) {
                ($this->logger)('Drain grace elapsed, signaling workers to stop.');
                $this->shouldStop = true;
                $this->signalChildren(SIGTERM);
                $this->draining = false;
            }
        }
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
            $housekeeper = StreamHousekeeper::fromConfig($this->config);

            $deadLetter = $housekeeper->pruneDeadLetter();

            if ($deadLetter['before'] !== $deadLetter['after']) {
                ($this->logger)("Housekeeping: dead-letter trimmed from {$deadLetter['before']} to {$deadLetter['after']} entries.");
            }

            // A live worker touches its consumer on every poll, so anything
            // idle for longer than a full worker lifetime belongs to a
            // process that is gone.
            $idleSeconds = max(3600, (int) ($this->config['max_worker_lifetime'] ?? 3600));
            $removed = array_sum($housekeeper->pruneConsumers($idleSeconds));

            if ($removed > 0) {
                ($this->logger)("Housekeeping: removed {$removed} stale stream consumers.");
            }
        } catch (\Throwable $e) {
            ($this->logger)("Housekeeping failed ({$e->getMessage()}); retrying in {$interval}s.");
        }
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
     * `drain_grace_seconds + 60`, and installations that raised the grace to
     * survive long jobs (7200s on one production instance) then start into a
     * queue that reads as PAUSED for two hours after a killed reload. The
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

            if (! DrainPause::isStale($value, getmypid(), $isMasterAlive)) {
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
        $grace = (int) ($this->config['drain_grace_seconds'] ?? 10);

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
                ->execute('SET', $prefix.'paused', 'drain:'.getmypid(), 'EX', (string) ($grace + 60));
        } catch (\Throwable $e) {
            ($this->logger)("Drain: failed to set Redis paused key ({$e->getMessage()}); proceeding with timed SIGTERM.");
        }

        ($this->logger)("Draining: pickup paused, waiting up to {$grace}s for in-flight jobs.");
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
     */
    private function tryAcquireMasterLock(): bool
    {
        $path = self::lockFilePath();
        $existed = file_exists($path);
        $handle = @fopen($path, 'c');

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
                unset($this->workerPids[$pid]);
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
            }

            // Gauges are point-in-time, so unlike the counters they are sampled
            // on every tick including the first.
            $this->metricsPublisher->recordGauges($this->gaugeSamples($aggregate));

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
     * Gauge samples for this tick: the fleet numbers plus the queue depth.
     *
     * Depth is the one figure the aggregate cannot supply, so it is probed here
     * with an XPENDING and a ZCARD per stream. A stream whose consumer group
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
        $probe = $this->depthProbe ??= rescue(fn (): ?StreamQueue => StreamQueueResolver::make(), null, false);

        if ($probe !== null) {
            foreach (array_keys((array) ($this->config['streams'] ?? [])) as $stream) {
                $pending += (int) rescue(fn (): int => $probe->pendingSize((string) $stream), 0, false);
                $delayed += (int) rescue(fn (): int => $probe->delayedSize((string) $stream), 0, false);
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

        if (file_put_contents($tmpPath, (string) getmypid(), LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write temporary PID file at {$tmpPath}.");
        }

        // Group-writable: deploy tooling that chowns storage to the web user
        // (Deployer writable_mode=chown with a different http_user) must not
        // lock the supervised master's own runtime files away from it.
        @chmod($tmpPath, 0664);

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
