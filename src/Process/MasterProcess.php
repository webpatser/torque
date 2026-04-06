<?php

declare(strict_types=1);

namespace Webpatser\Torque\Process;

use Closure;
use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Manager\ScaleDecision;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Worker\WorkerProcess;

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
    private readonly string $bootstrapPath;

    public function __construct(
        private readonly array $config,
        private readonly Closure $logger,
    ) {
        // Resolve bootstrap path before forking — cwd may change after fork.
        $this->bootstrapPath = base_path('bootstrap/app.php');
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
                . $autoscaleConfig['min_workers'] . '-' . $autoscaleConfig['max_workers']
                . ' workers)');
        }

        $exitCode = $this->monitor();

        $this->removePidFile();

        return $exitCode;
    }

    /**
     * Fork a single worker process.
     *
     * The child bootstraps a fresh Laravel application, creates a WorkerProcess,
     * and runs the event loop. The parent records the child PID.
     */
    private function spawnWorker(): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork worker process');
        }

        if ($pid === 0) {
            // Child process — bootstrap a fresh Laravel app so each worker
            // has its own service container, database connections, etc.
            // Use the pre-resolved path since cwd may differ after fork.
            try {
                $app = require $this->bootstrapPath;
                $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
                $kernel->bootstrap();

                $worker = new WorkerProcess($this->config);
                $worker->run();
            } catch (\Throwable $e) {
                // Write to stderr so it's visible even if Laravel logging is unavailable.
                fwrite(STDERR, "[Torque Worker] Fatal: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");

                try {
                    // Attempt to log via Laravel if available.
                    if (function_exists('app') && app()->bound('log')) {
                        app('log')->error('[Torque Worker] Fatal error during run', [
                            'exception' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                } catch (\Throwable) {
                    // Logging itself failed — stderr output above is our only hope.
                }
            }

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
        // Evaluate autoscaling every 10 iterations (~1 second at 100ms sleep).
        $autoscaleTick = 0;

        while (!empty($this->workerPids)) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid > 0) {
                unset($this->workerPids[$pid]);

                $exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
                ($this->logger)("Worker PID {$pid} exited (code {$exitCode})");

                // Workers being scaled down should not be respawned.
                if (isset($this->scalingDownPids[$pid])) {
                    unset($this->scalingDownPids[$pid]);
                    ($this->logger)("Scaled-down worker PID {$pid} drained and exited.");
                } elseif (!$this->shouldStop) {
                    ($this->logger)('Respawning replacement worker...');
                    $this->spawnWorker();
                }
            }

            // Autoscale evaluation on a throttled tick.
            if ($this->autoScaler !== null && ++$autoscaleTick >= 10) {
                $autoscaleTick = 0;
                $this->evaluateAutoscale();
            }

            // Sleep 100ms to avoid busy-looping.
            usleep(100_000);
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
        ($this->logger)("Autoscaler: scaling up (workers: {$this->workerCount} -> " . ($this->workerCount + 1) . ')');
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
            . ($this->workerCount - 1) . "), sending SIGTERM to PID {$targetPid}");

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
     * Get the path to the PID file.
     */
    public static function pidFilePath(): string
    {
        return storage_path('torque.pid');
    }

    /**
     * Write the master PID to the PID file atomically.
     *
     * Uses a temporary file + rename to prevent partial reads. Rejects
     * symlinks at the target path to prevent symlink attacks.
     */
    private function writePidFile(): void
    {
        $path = self::pidFilePath();

        if (is_link($path)) {
            unlink($path);
        }

        $tmpPath = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmpPath, (string) getmypid());
        rename($tmpPath, $path);
    }

    /**
     * Remove the PID file on shutdown.
     */
    private function removePidFile(): void
    {
        $path = self::pidFilePath();

        if (file_exists($path) && !is_link($path)) {
            unlink($path);
        }
    }

    /**
     * Read the master PID from the PID file, or null if not running.
     */
    public static function readPid(): ?int
    {
        $path = self::pidFilePath();

        if (!file_exists($path) || is_link($path)) {
            return null;
        }

        $pid = (int) file_get_contents($path);

        if ($pid <= 0) {
            return null;
        }

        // Verify the process is still alive.
        if (posix_kill($pid, 0)) {
            return $pid;
        }

        // Stale PID file — clean up.
        @unlink($path);

        return null;
    }
}
