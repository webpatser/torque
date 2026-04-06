<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;

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
            $this->components->warn("Process {$pid} is not running. Cleaning up stale PID file.");
            $this->removePidFile($pidFile);

            return self::SUCCESS;
        }

        $signal = $this->option('force') ? SIGKILL : SIGTERM;
        $signalName = $this->option('force') ? 'SIGKILL' : 'SIGTERM';

        $this->components->info("Sending {$signalName} to Torque master (PID {$pid})...");

        if (! posix_kill($pid, $signal)) {
            $this->components->error("Failed to send {$signalName} to PID {$pid}: " . posix_strerror(posix_get_last_error()));

            return self::FAILURE;
        }

        // SIGKILL is instant — no graceful wait needed.
        if ($this->option('force')) {
            // Brief pause to let the OS reap the process.
            usleep(self::POLL_INTERVAL);
            $this->removePidFile($pidFile);
            $this->components->info('Torque master killed.');

            return self::SUCCESS;
        }

        // Wait for graceful shutdown after SIGTERM.
        $this->components->info('Waiting for graceful shutdown...');

        $waited = 0;
        $maxWait = self::GRACEFUL_TIMEOUT * 1_000_000;

        while ($waited < $maxWait) {
            // posix_kill with signal 0 returns false when the process no longer exists.
            if (! posix_kill($pid, 0)) {
                $this->removePidFile($pidFile);
                $this->components->info('Torque master stopped gracefully.');

                return self::SUCCESS;
            }

            usleep(self::POLL_INTERVAL);
            $waited += self::POLL_INTERVAL;
        }

        $this->components->error(
            "Torque master (PID {$pid}) did not exit within " . self::GRACEFUL_TIMEOUT . ' seconds. Use --force to send SIGKILL.',
        );

        return self::FAILURE;
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
}
