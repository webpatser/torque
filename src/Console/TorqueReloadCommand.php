<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Webpatser\Torque\Process\MasterProcess;

/**
 * Zero-downtime reload of the Torque master.
 *
 * Mirrors `resonate:reload`. Default flow: spawn a replacement master, wait
 * for it to take over `storage/torque.pid`, then signal the old master via
 * SIGUSR2. `--drain` skips the spawn step and only signals; use it when an
 * external supervisor (systemd, k8s preStop, Supervisor) handles spawning
 * the replacement.
 *
 * Unlike Resonate, Torque has no socket to share. Two masters running
 * briefly during the swap is harmless: the Redis queue claims jobs
 * atomically, so a job is processed by exactly one worker no matter how
 * many masters are alive at the moment.
 */
#[AsCommand(name: 'torque:reload')]
final class TorqueReloadCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:reload
        {--drain : Only signal the running master to drain; do not spawn a replacement}
        {--timeout=30 : Seconds to wait for the old master to exit after the drain signal}
        {--health-timeout=10 : Seconds to wait for the new master to take over the PID file}';

    /** @var string */
    protected $description = 'Reload the Torque master with zero downtime';

    /**
     * Spawner callable, swappable in tests.
     *
     * Returns the PID of the spawned `torque:start` process, or null on
     * failure. The default implementation uses `proc_open` and lets the
     * child be reparented to init when this command exits.
     *
     * @var (callable():(?int))|null
     */
    public static $spawner = null;

    /**
     * Readiness-probe callable, swappable in tests.
     *
     * Receives the old master PID and returns true once the PID file
     * points at a different alive PID (the new master is up).
     *
     * @var (callable(int):bool)|null
     */
    public static $readinessChecker = null;

    public function handle(): int
    {
        if (windows_os() || ! function_exists('posix_kill')) {
            $this->components->error('torque:reload requires posix extensions and is not supported on Windows.');

            return self::FAILURE;
        }

        $oldPid = MasterProcess::readPid();

        if ($oldPid === null) {
            $this->components->error('No running Torque master found (storage/torque.pid missing or stale).');

            return self::FAILURE;
        }

        $drainTimeout = max(0, (int) $this->option('timeout'));
        $healthTimeout = max(1, (int) $this->option('health-timeout'));

        if ($this->option('drain')) {
            $this->components->info("Draining Torque master (PID: {$oldPid}).");

            return $this->signalDrain($oldPid, $drainTimeout);
        }

        $this->components->info("Spawning replacement master (current PID: {$oldPid}).");

        $newPid = $this->spawn();

        if ($newPid === null) {
            $this->components->error('Failed to spawn replacement master.');

            return self::FAILURE;
        }

        $this->components->info("Replacement PID: {$newPid}. Waiting for it to take over the PID file.");

        if (! $this->waitForReady($oldPid, $healthTimeout)) {
            $this->components->error('Replacement master did not take over the PID file in time; terminating it.');
            @posix_kill($newPid, SIGTERM);

            return self::FAILURE;
        }

        $this->components->info("Replacement ready; draining old master (PID: {$oldPid}).");

        return $this->signalDrain($oldPid, $drainTimeout);
    }

    /**
     * Signal the old master to drain and wait for it to exit.
     */
    private function signalDrain(int $pid, int $timeout): int
    {
        if (! @posix_kill($pid, SIGUSR2)) {
            $this->components->error("Failed to signal PID {$pid} (SIGUSR2).");

            return self::FAILURE;
        }

        $deadline = microtime(true) + $timeout + 5;

        while (microtime(true) < $deadline) {
            if (! @posix_kill($pid, 0)) {
                $this->components->info("Old master (PID: {$pid}) exited cleanly.");

                return self::SUCCESS;
            }

            usleep(200_000);
        }

        $this->components->warn("Old master (PID: {$pid}) did not exit within the drain window; sending SIGTERM.");
        @posix_kill($pid, SIGTERM);

        return self::SUCCESS;
    }

    /**
     * Spawn a detached `torque:start` child process.
     */
    private function spawn(): ?int
    {
        if (is_callable(self::$spawner)) {
            return (self::$spawner)();
        }

        $artisan = base_path('artisan');

        if (! is_file($artisan)) {
            return null;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];

        $pipes = [];

        $process = @proc_open(
            [PHP_BINARY, $artisan, 'torque:start'],
            $descriptors,
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            return null;
        }

        $status = proc_get_status($process);

        return $status['pid'] ?? null;
    }

    /**
     * Poll `storage/torque.pid` until it points at a new alive PID.
     */
    private function waitForReady(int $oldPid, int $timeout): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if ($this->isReplacementReady($oldPid)) {
                return true;
            }

            usleep(250_000);
        }

        return false;
    }

    /**
     * The replacement is ready when the PID file points at an alive PID
     * that is not the old master's.
     */
    private function isReplacementReady(int $oldPid): bool
    {
        if (is_callable(self::$readinessChecker)) {
            return (bool) (self::$readinessChecker)($oldPid);
        }

        $current = MasterProcess::readPid();

        return $current !== null && $current !== $oldPid;
    }
}
