<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Webpatser\Torque\Control\ControlChannel;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\DrainSettings;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeName;
use Webpatser\Torque\Support\ProcessInspector;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Zero-downtime reload of the Torque master.
 *
 * Default flow (takeover handshake): spawn `torque:start --takeover=<oldPid>`,
 * which forks its fleet, waits for its own workers' first heartbeat, only then
 * takes over `storage/torque.pid` and asks the old master to drain. This
 * command polls for the PID-file flip and signals the drain as well
 * (idempotent with the master-to-master signal). `--drain` skips the spawn
 * step and only signals; use it when an external supervisor (systemd, k8s
 * preStop, Supervisor) owns spawning the replacement, under a supervisor the
 * spawned takeover master would escape supervision, so `--drain` is the
 * correct production mode there.
 *
 * `--node` and `--all` send the fixed `drain` action to remote masters
 * through the control channel instead and wait for the acks. That is the
 * remote form of `--drain`: nothing is spawned, so a master whose record
 * says it is not supervised is refused (`torque:stop` is the honest verb).
 *
 * Unlike Resonate, Torque has no socket to share. Two masters running
 * briefly during the swap is harmless: the Redis queue claims jobs
 * atomically, so a job is processed by exactly one worker no matter how
 * many masters are alive at the moment.
 *
 * Two clocks are at play and they are not the same one. `--timeout` is how
 * long *this command* waits before it stops watching; `reload_pause_seconds`
 * and `drain_grace_seconds` are the ceilings the master and its workers hold
 * themselves to. Left unset, `--timeout` derives from those, so the default
 * can no longer be shorter than the drain it is waiting for.
 */
#[AsCommand(name: 'torque:reload')]
final class TorqueReloadCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:reload
        {--node=* : Send drain to the live supervised masters on these nodes through the control channel (repeatable, comma lists allowed)}
        {--all : Send drain to every live supervised master in the fleet through the control channel}
        {--drain : Only signal the running master to drain; do not spawn a replacement}
        {--if-running : Exit successfully when no master is running instead of failing (for deploy scripts)}
        {--force : Spawn a takeover master even when the running master is supervised (local only)}
        {--timeout= : Seconds to wait for the old master to exit after the drain signal (default: the drain worst case, reload_pause_seconds + drain_grace_seconds + slack). Remote: seconds to wait for the acks (default: the command TTL)}
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

    /**
     * Test seam: replaces the usleep() between polls while waiting for remote
     * acks, so a test can play the master's part between two polls.
     *
     * @internal
     *
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $remoteSleeper = null;

    /**
     * Polling interval in microseconds while waiting for remote acks.
     */
    private const int REMOTE_POLL_INTERVAL = 250_000;

    public function handle(): int
    {
        $nodes = $this->listOption('node');
        $all = (bool) $this->option('all');

        if ($nodes !== [] || $all) {
            return $this->reloadRemote($nodes, $all);
        }

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

    /**
     * Drain remote masters through the control channel: send the fixed
     * `drain` action to every live supervised master on the named nodes (or
     * the whole fleet with --all) and wait for the acks. A remote reload
     * never spawns, so a master without a supervisor to respawn it is
     * refused: draining it would only stop it.
     *
     * @param  list<string>  $nodes
     */
    private function reloadRemote(array $nodes, bool $all): int
    {
        if ($all && $nodes !== []) {
            $this->components->error('--all cannot be combined with --node.');

            return self::FAILURE;
        }

        if ($this->option('force')) {
            $this->components->error(
                '--force cannot be combined with --node or --all: a remote reload only drains and never spawns a takeover master.',
            );

            return self::FAILURE;
        }

        $config = Narrow::map(config('torque'));
        $control = Narrow::map($config['control'] ?? null);

        if (! Narrow::bool($control['enabled'] ?? null, true)) {
            $this->components->error(
                'Remote control is disabled (torque.control.enabled is false). Nothing was sent; run torque:reload on the node itself.',
            );

            return self::FAILURE;
        }

        foreach ($nodes as $node) {
            try {
                NodeName::validate($node);
            } catch (\InvalidArgumentException $e) {
                $this->components->error('Nothing was sent: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $commandTtl = Narrow::int($control['command_ttl_seconds'] ?? null, 60);
        $redisConfig = Narrow::map($config['redis'] ?? null);
        $redis = createRedisClient(Narrow::string($redisConfig['uri'] ?? null, 'redis://127.0.0.1:6379'));
        $prefix = Narrow::string($redisConfig['prefix'] ?? null, 'torque:');

        try {
            $channel = new ControlChannel($redis, $prefix, $commandTtl);
        } catch (\InvalidArgumentException $e) {
            $this->components->error('Nothing was sent: '.$e->getMessage());

            return self::FAILURE;
        }

        $masters = new MasterRegistry($redis, $prefix)->all();
        $ifRunning = (bool) $this->option('if-running');
        $failed = false;

        if ($all) {
            if ($masters === []) {
                if ($ifRunning) {
                    $this->components->warn('No live Torque master found in the fleet; nothing to reload.');

                    return self::SUCCESS;
                }

                $this->components->error('No live Torque master found in the fleet. Nothing was sent.');

                return self::FAILURE;
            }

            $targets = $masters;
        } else {
            $targets = [];

            foreach ($nodes as $node) {
                $onNode = array_values(array_filter($masters, static fn (array $master): bool => $master['node'] === $node));

                if ($onNode === []) {
                    if ($ifRunning) {
                        $this->components->warn("No live Torque master on node [{$node}]; nothing to reload there.");
                    } else {
                        $this->components->error("No live Torque master on node [{$node}].");
                        $failed = true;
                    }

                    continue;
                }

                array_push($targets, ...$onNode);
            }
        }

        $issuer = 'cli@'.NodeName::current();

        /** @var array<string, string> $sent master id to command id */
        $sent = [];

        foreach ($targets as $master) {
            if (! $master['supervised']) {
                $this->components->error(
                    "Master {$master['master_id']} is not supervised: a drain would stop it without a replacement. Nothing was sent to it.",
                );
                $this->line("  Use torque:stop --node={$master['node']} to stop it, or reload it on that node itself.");
                $failed = true;

                continue;
            }

            $command = $channel->send($master['master_id'], ControlChannel::ACTION_DRAIN, $issuer);
            $sent[$master['master_id']] = $command['id'];
            $this->components->info("Sent drain to master {$master['master_id']} on node [{$master['node']}] (PID {$master['pid']}).");
        }

        if ($sent !== []) {
            $timeoutOption = $this->option('timeout');
            $timeout = $timeoutOption === null ? $commandTtl : max(0, Narrow::int($timeoutOption));

            if (! $this->awaitRemoteAcks($channel, $sent, $timeout)) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Wait for every master to ack its drain command. Returns false when a
     * master rejected it or no ack arrived within the timeout.
     *
     * @param  array<string, string>  $pending  master id to command id
     */
    private function awaitRemoteAcks(ControlChannel $channel, array $pending, int $timeout): bool
    {
        $ok = true;
        $deadline = microtime(true) + $timeout;

        while (true) {
            foreach ($pending as $masterId => $commandId) {
                $ack = $channel->readAck($commandId);

                if ($ack === null) {
                    continue;
                }

                unset($pending[$masterId]);

                if (! $ack['accepted'] || $ack['master'] !== $masterId) {
                    $this->components->error("Master {$masterId} rejected the drain command.");
                    $ok = false;

                    continue;
                }

                $this->components->info("Master {$masterId} acknowledged the drain; its supervisor starts the replacement once it exits.");
            }

            if ($pending === [] || microtime(true) >= $deadline) {
                break;
            }

            self::$remoteSleeper !== null
                ? (self::$remoteSleeper)(self::REMOTE_POLL_INTERVAL)
                : usleep(self::REMOTE_POLL_INTERVAL);
        }

        foreach (array_keys($pending) as $masterId) {
            $this->components->error("Master {$masterId} timed out: no acknowledgement within {$timeout} seconds.");
            $ok = false;
        }

        return $ok;
    }

    /**
     * A repeatable option with comma lists flattened, trimmed and deduplicated.
     * Empty entries are kept so validation refuses them.
     *
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $values = [];

        foreach ((array) $this->option($name) as $raw) {
            foreach (explode(',', Narrow::string($raw, '')) as $value) {
                $values[] = trim($value);
            }
        }

        return array_values(array_unique($values));
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

        $worstCase = DrainSettings::drainWorstCaseSeconds(Narrow::map(config('torque')));

        $signalSlack = max(0, Narrow::int(config('torque.intervals.reload_signal_slack_seconds', self::SIGNAL_SLACK_SECONDS)));
        $window = $timeout === null ? $worstCase : $timeout + $signalSlack;
        $deadline = microtime(true) + $window;

        while (microtime(true) < $deadline) {
            if (! ProcessInspector::isAlive($pid)) {
                $this->components->info("Old master (PID: {$pid}) exited cleanly.");

                return self::SUCCESS;
            }

            usleep(200_000);
        }

        if ($window < $worstCase) {
            $this->components->info(
                "Old master (PID: {$pid}) is still draining after {$window}s; leaving it to its own "
                ."drain ceiling ({$worstCase}s worst case). It stops accepting work "
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

        return $status['pid'];
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
