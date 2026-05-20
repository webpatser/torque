<?php

declare(strict_types=1);

namespace Webpatser\Torque\Process;

use Closure;
use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Manager\ScaleDecision;
use Webpatser\Torque\Metrics\MetricsPublisher;

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

        // Write PID file so torque:stop/status can find us.
        $this->writePidFile();

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

        $numWorkers = (int) ($this->config['workers'] ?? 4);

        ($this->logger)("Starting {$numWorkers} worker processes...");

        for ($i = 0; $i < $numWorkers; $i++) {
            $this->spawnWorker();
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

            $this->metricsPublisher = new MetricsPublisher(
                redisUri: $redisUri,
                prefix: $this->config['redis']['prefix'] ?? 'torque:',
            );

            ($this->logger)('Autoscaling enabled ('
                .$autoscaleConfig['min_workers'].'-'.$autoscaleConfig['max_workers']
                .' workers)');
        }

        $exitCode = $this->monitor();

        // Clean up all worker metrics keys from Redis after all workers have exited.
        // This is the safety net — individual workers clean up on graceful exit, but
        // SIGKILL or crashes may leave ghost entries.
        try {
            $publisher = new MetricsPublisher(
                redisUri: $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
                prefix: $this->config['redis']['prefix'] ?? 'torque:',
            );
            $publisher->removeAllWorkerMetrics();
        } catch (\Throwable) {
            // Best-effort — don't prevent shutdown.
        }

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

        // Evaluate autoscaling every ~10 iterations (~1 second at 100ms wait).
        $autoscaleTick = 0;

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

            // Autoscale evaluation on a throttled tick.
            if ($this->autoScaler !== null && ++$autoscaleTick >= 10) {
                $autoscaleTick = 0;
                $this->evaluateAutoscale();
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
        $rawMetrics = $this->metricsPublisher->getAllWorkerMetrics();

        // Build the format AutoScaler expects: ['active' => int, 'total' => int] per worker.
        $workerMetrics = [];
        foreach ($rawMetrics as $workerId => $data) {
            $workerMetrics[$workerId] = [
                'active' => (int) ($data['active_slots'] ?? 0),
                'total' => (int) ($data['total_slots'] ?? 0),
            ];
        }

        $decision = $this->autoScaler->evaluate($this->workerCount, $workerMetrics);

        match ($decision) {
            ScaleDecision::ScaleUp => $this->scaleUp(),
            ScaleDecision::ScaleDown => $this->scaleDown($workerMetrics),
            ScaleDecision::NoChange => null,
        };
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
        // Build a PID-to-active-slots map for workers we currently own.
        // Worker IDs in Redis may be PID-based or arbitrary — match against our PID set.
        $pidActivity = [];

        foreach (array_keys($this->workerPids) as $pid) {
            // Try both the raw PID and string variants as the worker ID.
            $pidStr = (string) $pid;
            $pidActivity[$pid] = (int) ($workerMetrics[$pidStr]['active'] ?? PHP_INT_MAX);
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
     * Mark workers paused via Redis so they stop picking new jobs.
     *
     * Best-effort: if Redis is unreachable we still proceed to the timed
     * SIGTERM in {@see handleDrainTick}; workers may not see the pause flag
     * but their own SIGTERM grace lets them finish their current job.
     */
    private function beginDrain(): void
    {
        $grace = (int) ($this->config['drain_grace_seconds'] ?? 10);

        try {
            $redisUri = $this->config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
            $prefix = $this->config['redis']['prefix'] ?? 'torque:';

            createRedisClient($redisUri)
                ->execute('SET', $prefix.'paused', (string) time());
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
        if (posix_kill($pid, 0) && self::processIsTorqueMaster($pid)) {
            return $pid;
        }

        // Stale PID file — dead or recycled PID. Clean up.
        @unlink($path);

        return null;
    }

    /**
     * Whether the process at the given PID is a Torque master.
     *
     * Inspects `/proc/<pid>/cmdline` on Linux. On platforms without
     * `/proc` (e.g. macOS) the command line cannot be read, so the check
     * is treated as inconclusive and the caller's liveness check stands
     * on its own — matching the pre-`/proc` behaviour.
     */
    private static function processIsTorqueMaster(int $pid): bool
    {
        $cmdlinePath = "/proc/{$pid}/cmdline";

        if (! is_readable($cmdlinePath)) {
            return true;
        }

        $cmdline = (string) @file_get_contents($cmdlinePath);

        return str_contains($cmdline, 'torque:start');
    }
}
