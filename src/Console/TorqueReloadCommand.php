<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\ProcessInspector;

/**
 * Zero-downtime reload of the Torque master.
 *
 * Default flow (takeover handshake): spawn `torque:start --takeover=<oldPid>`,
 * which forks its fleet, waits for its own workers' first heartbeat, only then
 * takes over `storage/torque.pid` and asks the old master to drain. This
 * command polls for the PID-file flip and signals the drain as well
 * (idempotent with the master-to-master signal). `--drain` skips the spawn
 * step and only signals; use it when an external supervisor (systemd, k8s
 * preStop, Supervisor) owns spawning the replacement — under a supervisor the
 * spawned takeover master would escape supervision, so `--drain` is the
 * correct production mode there.
 *
 * Unlike Resonate, Torque has no socket to share. Two masters running
 * briefly during the swap is harmless: the Redis queue claims jobs
 * atomically, so a job is processed by exactly one worker no matter how
 * many masters are alive at the moment.
 *
 * Two clocks are at play and they are not the same one. `--timeout` is how
 * long *this command* waits before it stops watching; `drain_grace_seconds`
 * is the ceiling the master and its workers hold themselves to. Left
 * unset, `--timeout` derives from that ceiling, so the default can no
 * longer be shorter than the drain it is waiting for.
 */
#[AsCommand(name: 'torque:reload')]
final class TorqueReloadCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:reload
        {--drain : Only signal the running master to drain; do not spawn a replacement}
        {--if-running : Exit successfully when no master is running instead of failing (for deploy scripts)}
        {--force : Spawn a takeover master even when the running master is supervised}
        {--timeout= : Seconds to wait for the old master to exit after the drain signal (default: derived from drain_grace_seconds)}
        {--health-timeout=45 : Seconds to wait for the new master to take over the PID file (covers its worker-heartbeat readiness gate)}';

    /** @var string */
    protected $description = 'Reload the Torque master with zero downtime';

    /**
     * Spawner callable, swappable in tests.
     *
     * Receives the old master PID and returns the PID of the spawned
     * `torque:start --takeover` process, or null on failure. The default
     * implementation uses `proc_open` and lets the child be reparented to
     * init when this command exits.
     *
     * @var (callable(int):(?int))|null
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

    /**
     * The reload mutex handle; held until this process exits.
     *
     * @var resource|null
     */
    private $reloadLock = null;

    /** Where the spawned child's stderr is captured for diagnostics. */
    private ?string $stderrPath = null;

    /**
     * Slack added to an explicit `--timeout` so the operator's number means
     * "wait this long for the drain" rather than "including the round trip
     * of signalling and polling".
     */
    private const int SIGNAL_SLACK_SECONDS = 5;

    public function handle(): int
    {
        if (windows_os() || ! function_exists('posix_kill')) {
            $this->components->error('torque:reload requires posix extensions and is not supported on Windows.');

            return self::FAILURE;
        }

        if (! $this->acquireReloadLock()) {
            $this->components->error('Another torque:reload is already running; refusing to double-spawn.');

            return self::FAILURE;
        }

        try {
            return $this->reload();
        } finally {
            // Command instances persist in the console kernel, so the lock is
            // released explicitly rather than relying on handle GC.
            $this->releaseReloadLock();
        }
    }

    private function reload(): int
    {

        $oldPid = MasterProcess::readPid();

        if ($oldPid === null) {
            if ($this->option('if-running')) {
                $this->components->warn('No running Torque master found (storage/torque.pid missing or stale); nothing to reload.');

                return self::SUCCESS;
            }

            $this->components->error('No running Torque master found (storage/torque.pid missing or stale).');

            return self::FAILURE;
        }

        $timeoutOption = $this->option('timeout');
        $drainTimeout = $timeoutOption === null ? null : max(0, (int) $timeoutOption);
        $healthTimeout = max(1, (int) $this->option('health-timeout'));

        if ($this->option('drain')) {
            $this->components->info("Draining Torque master (PID: {$oldPid}).");

            return $this->signalDrain($oldPid, $drainTimeout);
        }

        // A supervised master (supervisord, systemd) must be reloaded with
        // --drain: the supervisor owns the respawn, while a self-spawned
        // takeover master would run unsupervised from then on. Worse, once
        // the old master drains, the supervisor's respawns collide with the
        // unsupervised takeover master until startretries exhaust and the
        // program goes FATAL, so the next drain leaves no queue at all.
        // Refusing (instead of warning) turns that outage into a hard stop;
        // --force remains for deliberately pulling a master out from under a
        // supervisor.
        $masterParent = ProcessInspector::parentPid($oldPid);

        if ($masterParent !== null && $masterParent !== 1 && ! $this->option('force')) {
            $this->components->error(
                "Master PID {$oldPid} appears to run under a process supervisor (parent PID {$masterParent}). "
                .'Use `torque:reload --drain` there: the supervisor respawns the master, while a spawned '
                .'takeover master would escape supervision. Pass --force to spawn one anyway.',
            );

            return self::FAILURE;
        }

        $this->components->info("Spawning replacement master (current PID: {$oldPid}).");

        $newPid = $this->spawn($oldPid);

        if ($newPid === null) {
            $this->components->error('Failed to spawn replacement master.');

            return self::FAILURE;
        }

        $this->components->info("Replacement PID: {$newPid}. Waiting for it to take over the PID file.");

        if (! $this->waitForReady($oldPid, $healthTimeout)) {
            $this->components->error('Replacement master did not take over the PID file in time; terminating it.');
            $this->surfaceChildStderr();
            @posix_kill($newPid, SIGTERM);

            return self::FAILURE;
        }

        $this->cleanupStderrCapture();
        $this->components->info("Replacement ready; draining old master (PID: {$oldPid}).");

        return $this->signalDrain($oldPid, $drainTimeout);
    }

    /**
     * Signal the old master to drain and wait for it to exit.
     *
     * `$timeout` is null when `--timeout` was not given, in which case the
     * wait covers the master's own worst case and a master still alive past
     * it is a wedged one, worth a SIGTERM. A shorter explicit timeout means
     * the caller wanted its own deadline (a deploy tool with a run timeout,
     * say), not a shorter drain: that path stops watching and leaves the
     * master to the ceiling it already enforces.
     */
    private function signalDrain(int $pid, ?int $timeout): int
    {
        // The takeover master signals the old one itself the moment it takes
        // the PID file, so by the time this runs the old master may already
        // be gone. That is success, not an error.
        if (! @posix_kill($pid, 0)) {
            $this->components->info("Old master (PID: {$pid}) already exited.");

            return self::SUCCESS;
        }

        if (! @posix_kill($pid, SIGUSR2)) {
            $this->components->error("Failed to signal PID {$pid} (SIGUSR2).");

            return self::FAILURE;
        }

        $worstCase = MasterProcess::drainWorstCaseSeconds(
            (int) config('torque.drain_grace_seconds', 10),
        );

        $window = $timeout === null ? $worstCase : $timeout + self::SIGNAL_SLACK_SECONDS;
        $deadline = microtime(true) + $window;

        while (microtime(true) < $deadline) {
            if (! @posix_kill($pid, 0)) {
                $this->components->info("Old master (PID: {$pid}) exited cleanly.");

                return self::SUCCESS;
            }

            usleep(200_000);
        }

        if ($window < $worstCase) {
            $this->components->info(
                "Old master (PID: {$pid}) is still draining after {$window}s; leaving it to its own "
                ."drain_grace_seconds ceiling ({$worstCase}s worst case). It stops accepting work "
                .'from the moment it was signalled and exits on its own.',
            );

            return self::SUCCESS;
        }

        $this->components->warn("Old master (PID: {$pid}) did not exit within {$window}s, past its own drain ceiling; sending SIGTERM.");
        @posix_kill($pid, SIGTERM);

        return self::SUCCESS;
    }

    /**
     * Spawn a detached `torque:start --takeover` child process.
     */
    private function spawn(int $oldPid): ?int
    {
        if (is_callable(self::$spawner)) {
            return (self::$spawner)($oldPid);
        }

        $artisan = base_path('artisan');

        if (! is_file($artisan)) {
            return null;
        }

        // Capture stderr: a replacement that refuses to boot must say why in
        // the reload output instead of dying silently into /dev/null.
        $stderr = tempnam(sys_get_temp_dir(), 'torque-reload-');
        $this->stderrPath = $stderr === false ? null : $stderr;

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->stderrPath ?? '/dev/null', 'a'],
            2 => ['file', $this->stderrPath ?? '/dev/null', 'a'],
        ];

        $pipes = [];

        $process = @proc_open(
            [PHP_BINARY, $artisan, 'torque:start', "--takeover={$oldPid}"],
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

    /**
     * Take the non-blocking reload mutex so two concurrent reloads cannot
     * both spawn replacements for the same master.
     */
    private function acquireReloadLock(): bool
    {
        $path = storage_path('torque.reload.lock');
        $existed = file_exists($path);
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            return false;
        }

        if (! $existed) {
            // Group-writable from birth: survives a deploy-time chown to the
            // web user (see MasterProcess::writePidFile).
            @chmod($path, 0664);
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->reloadLock = $handle;

        return true;
    }

    private function releaseReloadLock(): void
    {
        if ($this->reloadLock !== null) {
            flock($this->reloadLock, LOCK_UN);
            fclose($this->reloadLock);
            $this->reloadLock = null;
        }
    }

    /**
     * Print the captured stderr of the failed replacement, if any.
     */
    private function surfaceChildStderr(): void
    {
        if ($this->stderrPath === null || ! is_file($this->stderrPath)) {
            return;
        }

        $output = trim((string) @file_get_contents($this->stderrPath));

        if ($output !== '') {
            $this->components->twoColumnDetail('Replacement output', '');
            $this->line($output);
        }

        $this->cleanupStderrCapture();
    }

    private function cleanupStderrCapture(): void
    {
        if ($this->stderrPath !== null) {
            @unlink($this->stderrPath);
            $this->stderrPath = null;
        }
    }
}
