<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * Stop the running Torque master process.
 *
 * Reads the master PID from the PID file and sends SIGTERM (or SIGKILL with --force).
 * Waits for the process to exit before removing the PID file.
 *
 * NOTE: MasterProcess must write `storage_path('torque.pid')` on startup for this
 * command to work. That change is tracked separately.
 */
final class TorqueStopCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:stop
        {--force : Send SIGKILL instead of SIGTERM}';

    /** @var string */
    protected $description = 'Stop the Torque queue worker master process';

    /**
     * Maximum seconds to wait for the process to exit after SIGTERM.
     */
    private const int GRACEFUL_TIMEOUT = 30;

    /**
     * Polling interval in microseconds while waiting for process exit.
     */
    private const int POLL_INTERVAL = 100_000;

    public function handle(): int
    {
        $pidFile = storage_path('torque.pid');

        if (! file_exists($pidFile)) {
            $this->components->error('Torque does not appear to be running (no PID file found).');

            return self::FAILURE;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));

        if ($pid <= 0) {
            $this->components->error('PID file exists but contains an invalid PID.');
            $this->removePidFile($pidFile);

            return self::FAILURE;
        }

        // Verify the process is actually running (signal 0 checks existence).
        if (! posix_kill($pid, 0)) {
            $this->components->warn("Process {$pid} is not running. Cleaning up stale PID file and orphans.");
            $this->removePidFile($pidFile);
            $this->killOrphanWorkers();
            $this->cleanupWorkerMetrics();

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $this->components->info("Sending SIGKILL to Torque process group (PID {$pid})...");

            // Kill the entire process group in one shot — master + all forked workers.
            // Must kill the group BEFORE the leader, otherwise -pid may fail.
            $this->killProcessGroup($pid);

            usleep(self::POLL_INTERVAL);
            $this->removePidFile($pidFile);
            $this->cleanupWorkerMetrics();
            $this->components->info('Torque master and workers killed.');

            return self::SUCCESS;
        }

        $this->components->info("Sending SIGTERM to Torque process group (PID {$pid})...");

        // Send SIGTERM to the entire process group so both master and workers
        // begin graceful shutdown simultaneously.
        if (! posix_kill(-$pid, SIGTERM)) {
            // Fallback: if process group kill fails, try the master directly.
            if (! posix_kill($pid, SIGTERM)) {
                $this->components->error("Failed to send SIGTERM to PID {$pid}: " . posix_strerror(posix_get_last_error()));

                return self::FAILURE;
            }
        }

        // Wait for graceful shutdown after SIGTERM.
        $this->components->info('Waiting for graceful shutdown...');

        $waited = 0;
        $maxWait = self::GRACEFUL_TIMEOUT * 1_000_000;

        while ($waited < $maxWait) {
            // posix_kill with signal 0 returns false when the process no longer exists.
            if (! posix_kill($pid, 0)) {
                $this->removePidFile($pidFile);
                $this->cleanupWorkerMetrics();
                $this->components->info('Torque master stopped gracefully.');

                return self::SUCCESS;
            }

            usleep(self::POLL_INTERVAL);
            $waited += self::POLL_INTERVAL;
        }

        // Graceful shutdown timed out — escalate to SIGKILL on entire process group.
        $this->components->warn(
            'Graceful shutdown timed out after ' . self::GRACEFUL_TIMEOUT . ' seconds. Sending SIGKILL...',
        );

        $this->killProcessGroup($pid);
        usleep(self::POLL_INTERVAL);
        $this->removePidFile($pidFile);
        $this->cleanupWorkerMetrics();
        $this->components->info('Torque master and workers killed.');

        return self::SUCCESS;
    }

    /**
     * Remove the PID file from storage.
     */
    private function removePidFile(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Kill any orphaned torque:worker processes belonging to this project.
     *
     * Scopes the pgrep pattern to this project's base path to avoid
     * matching workers from other projects on the same server.
     */
    private function killOrphanWorkers(): void
    {
        $basePath = base_path();
        $output = [];
        exec('pgrep -f ' . escapeshellarg($basePath . '/artisan torque:worker'), $output);

        foreach ($output as $line) {
            $pid = (int) trim($line);
            if ($pid > 0 && $pid !== getmypid()) {
                posix_kill($pid, SIGKILL);
            }
        }
    }

    /**
     * Kill the process group led by the given PID.
     *
     * Workers forked from the master share its PGID, so killing the
     * group ensures no orphans survive after a force-kill.
     */
    private function killProcessGroup(int $pid): void
    {
        // Negative PID = send signal to entire process group.
        posix_kill(-$pid, SIGKILL);
    }

    /**
     * Remove all worker metrics keys from Redis so they don't linger as ghosts.
     */
    private function cleanupWorkerMetrics(): void
    {
        try {
            app(MetricsPublisher::class)->removeAllWorkerMetrics();
        } catch (\Throwable) {
            // Best-effort — Redis may be unavailable.
        }
    }
}
