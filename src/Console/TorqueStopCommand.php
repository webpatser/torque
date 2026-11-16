<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\DrainSettings;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\ProcessInspector;

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
        {--force : Send SIGKILL instead of SIGTERM}
        {--timeout= : Seconds to wait for a graceful shutdown before SIGKILL (default: the drain worst case, reload_pause_seconds + drain_grace_seconds + slack)}';

    /** @var string */
    protected $description = 'Stop the Torque queue worker master process';

    /**
     * Polling interval in microseconds while waiting for process exit.
     */
    private const int POLL_INTERVAL = 100_000;

    private const int MASTER = 1;

    private const int NOT_MASTER = 2;

    private const int UNIDENTIFIED = 3;

    /**
     * Test seam: replaces ProcessInspector::commandLine() for the master
     * check, so a test can simulate a transient read failure.
     *
     * @internal
     *
     * @var (\Closure(int): ?string)|null
     */
    public static ?\Closure $commandLineReader = null;

    public function handle(): int
    {
        $pidFile = MasterProcess::pidFilePath();
        $pid = null;

        if (is_link($pidFile)) {
            // Same guard as MasterProcess::readPid(): a symlinked PID file is
            // not ours to trust or to delete.
            $this->components->error("PID file {$pidFile} is a symlink. Refusing to read or signal it.");

            return self::FAILURE;
        }

        if (file_exists($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));

            if ($pid <= 0) {
                $this->components->warn('PID file exists but contains an invalid PID. Cleaning up.');
                $this->removePidFile($pidFile);
                $pid = null;
            } elseif (! ProcessInspector::isAlive($pid)) {
                $this->components->warn("Process {$pid} is not running. Cleaning up stale PID file and orphans.");
                $this->removePidFile($pidFile, $pid);
                $pid = null;
            } else {
                $state = $this->masterState($pid);

                if ($state === self::NOT_MASTER) {
                    // A live PID that is not a Torque master is a recycled number
                    // (stale file after a crash or container restart). Signalling
                    // its group would hit an unrelated process; unlinking the file
                    // could race a new master's rename during a takeover.
                    $this->components->error(
                        "Process {$pid} from the PID file is alive but is not a Torque master. Not signalling it and leaving the PID file in place.",
                    );

                    return self::FAILURE;
                }

                if ($state === self::UNIDENTIFIED) {
                    $this->reportUnidentified($pid);

                    return self::FAILURE;
                }
            }
        }

        // No valid PID from file: nothing to stop. This command is scoped to
        // the master this storage dir describes; it never pgrep-hunts other
        // masters (that would kill a mid-takeover replacement, another
        // release, or another app on the same host). A live master with a
        // lost PID file rewrites it within a second via its lease
        // maintenance, so hunting is also unnecessary. Reparented workers
        // whose master died are still swept.
        if ($pid === null) {
            $this->killOrphanWorkers();
            $this->components->info('No running Torque processes found.');

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $this->components->info("Sending SIGKILL to Torque process group (PID {$pid})...");

            // Kill the entire process group in one shot: master + all forked workers.
            // Must kill the group BEFORE the leader, otherwise -pid may fail.
            $state = $this->killProcessGroup($pid);

            if ($state === self::UNIDENTIFIED) {
                $this->reportUnidentified($pid);

                return self::FAILURE;
            }

            if ($state === self::NOT_MASTER) {
                $this->components->warn("Process {$pid} is no longer a Torque master. Nothing signalled.");

                return self::SUCCESS;
            }

            usleep(self::POLL_INTERVAL);
            $this->removePidFile($pidFile, $pid);
            $this->components->info('Torque master and workers killed.');

            return self::SUCCESS;
        }

        $this->components->info("Sending SIGTERM to Torque process group (PID {$pid})...");

        // Send SIGTERM to the entire process group so both master and workers
        // begin graceful shutdown simultaneously. Re-verify first: the master
        // may have exited (and its PID been reused) since the check above.
        $state = $this->masterState($pid);

        if ($state === self::UNIDENTIFIED) {
            $this->reportUnidentified($pid);

            return self::FAILURE;
        }

        if ($state === self::NOT_MASTER) {
            $this->components->warn("Process {$pid} is no longer a Torque master. Nothing signalled.");

            return self::SUCCESS;
        }

        if (! posix_kill(-$pid, SIGTERM)) {
            // Fallback: if process group kill fails, try the master directly.
            if (! posix_kill($pid, SIGTERM)) {
                $this->components->error("Failed to send SIGTERM to PID {$pid}: ".posix_strerror(posix_get_last_error()));

                return self::FAILURE;
            }
        }

        // Wait for graceful shutdown after SIGTERM.
        $this->components->info('Waiting for graceful shutdown...');

        // This is the one shutdown path that ends in SIGKILL on the whole
        // process group, so its deadline must clear a full drain: the master
        // waiting for its fleet, then each worker finishing the job it holds.
        // A fixed 30s killed in-flight jobs on any installation that sized
        // drain_grace_seconds for long work.
        $gracefulTimeout = $this->gracefulTimeout();

        $waited = 0;
        $maxWait = $gracefulTimeout * 1_000_000;

        while ($waited < $maxWait) {
            // Gone, or the PID now belongs to something else: either way the
            // master we signalled has exited. A live process whose command
            // line cannot be read right now (e.g. a transient ps failure) is
            // not proof of exit, so keep waiting.
            if ($this->masterState($pid) === self::NOT_MASTER) {
                $this->removePidFile($pidFile, $pid);
                $this->components->info('Torque master stopped gracefully.');

                return self::SUCCESS;
            }

            usleep(self::POLL_INTERVAL);
            $waited += self::POLL_INTERVAL;
        }

        // Graceful shutdown timed out — escalate to SIGKILL on entire process group.
        $this->components->warn(
            "Graceful shutdown timed out after {$gracefulTimeout} seconds. Sending SIGKILL...",
        );

        $state = $this->killProcessGroup($pid);

        if ($state === self::UNIDENTIFIED) {
            $this->reportUnidentified($pid);

            return self::FAILURE;
        }

        if ($state === self::NOT_MASTER) {
            $this->removePidFile($pidFile, $pid);
            $this->components->info('Torque master stopped before the deadline check.');

            return self::SUCCESS;
        }

        usleep(self::POLL_INTERVAL);
        $this->removePidFile($pidFile, $pid);
        $this->components->info('Torque master and workers killed.');

        return self::SUCCESS;
    }

    /**
     * Classify the PID: verified master, verified not a master (or dead), or
     * alive but unidentifiable.
     *
     * Stricter than ProcessInspector::isTorqueMaster(), which treats an
     * unreadable command line as a master so liveness callers stay
     * conservative. Here an unreadable command line is its own state: it
     * never allows a group SIGTERM/SIGKILL, and it never counts as proof
     * that the master exited.
     *
     * @return self::MASTER|self::NOT_MASTER|self::UNIDENTIFIED
     */
    private function masterState(int $pid): int
    {
        if (! ProcessInspector::isAlive($pid)) {
            return self::NOT_MASTER;
        }

        $cmdline = self::$commandLineReader !== null
            ? (self::$commandLineReader)($pid)
            : ProcessInspector::commandLine($pid);

        if ($cmdline === null) {
            return self::UNIDENTIFIED;
        }

        return str_contains($cmdline, 'torque:start') ? self::MASTER : self::NOT_MASTER;
    }

    private function reportUnidentified(int $pid): void
    {
        $this->components->error(
            "Process {$pid} is alive but its command line could not be read. Not signalling it and leaving the PID file in place.",
        );
    }

    /**
     * Seconds to wait for a graceful shutdown before escalating to SIGKILL.
     *
     * The drain worst case unless `--timeout` says otherwise,
     * so the wait can never be shorter than the drain the fleet is entitled
     * to. Idle workers exit immediately (they have since 0.16.5), so a large
     * ceiling costs nothing on a quiet fleet.
     */
    private function gracefulTimeout(): int
    {
        $option = $this->option('timeout');

        if ($option !== null) {
            return max(0, (int) $option);
        }

        return DrainSettings::drainWorstCaseSeconds(Narrow::map(config('torque')));
    }

    /**
     * Remove the PID file from storage.
     *
     * With an expected PID, the file is only removed while it still holds
     * that PID, so a replacement master that renamed its own PID file into
     * place during a takeover keeps its entry.
     */
    private function removePidFile(string $path, ?int $expectedPid = null): void
    {
        if (! file_exists($path) || is_link($path)) {
            return;
        }

        if ($expectedPid !== null && (int) trim((string) @file_get_contents($path)) !== $expectedPid) {
            return;
        }

        @unlink($path);
    }

    /**
     * Kill orphaned torque:worker processes: workers whose parent is no
     * longer a live torque master. Parentage-filtered so a fleet whose
     * master is alive (a takeover in progress, another release on the same
     * host) is never touched. The first pattern character is bracketed so
     * the `sh -c` wrapper PHP spawns for exec(), whose own argv contains
     * the pattern text, never matches itself.
     */
    private function killOrphanWorkers(): void
    {
        $output = [];
        exec('pgrep -f '.escapeshellarg('[a]rtisan torque:worker'), $output);

        foreach ($output as $line) {
            $pid = (int) trim($line);

            if ($pid <= 0 || $pid === getmypid()) {
                continue;
            }

            $parent = ProcessInspector::parentPid($pid);

            if ($parent !== null && ! ProcessInspector::isTorqueMaster($parent)) {
                posix_kill($pid, SIGKILL);
            }
        }
    }

    /**
     * Kill the process group led by the given PID.
     *
     * Workers forked from the master share its PGID, so killing the
     * group ensures no orphans survive after a force-kill. Re-verifies the
     * PID immediately before signalling and only sends when it is a
     * verified master. Returns the state it observed.
     *
     * @return self::MASTER|self::NOT_MASTER|self::UNIDENTIFIED
     */
    private function killProcessGroup(int $pid): int
    {
        $state = $this->masterState($pid);

        if ($state === self::MASTER) {
            // Negative PID = send signal to entire process group.
            posix_kill(-$pid, SIGKILL);
        }

        return $state;
    }
}
