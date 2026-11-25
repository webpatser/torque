<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Composer\InstalledVersions;
use Fledge\Loop\UnsupportedFeatureException;
use Illuminate\Console\Command;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\EventLoopDriver;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeConfig;
use Webpatser\Torque\Support\NodeName;
use Webpatser\Torque\Support\ProcessInspector;

/**
 * Start the Torque queue worker with N forked processes.
 *
 * Usage:
 *   php artisan torque:start
 *   php artisan torque:start --workers=8 --concurrency=100
 *   php artisan torque:start --queues=emails,notifications
 *   php artisan torque:start --replace   (supervisor entrypoint, see below)
 */
final class TorqueStartCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:start
        {--workers= : Number of worker processes}
        {--concurrency= : Coroutine slots per worker}
        {--queues= : Comma-separated queue names}
        {--node= : Node name (defaults to TORQUE_NODE, then the hostname); selects the nodes block}
        {--replace : Absorb a running master via the takeover handshake instead of refusing to start (use as the supervisor program command)}
        {--takeover= : Internal: replace the running master with this PID (used by torque:reload)}';

    /** @var string */
    protected $description = 'Start the Torque coroutine-based queue worker';

    public function handle(): int
    {
        $takeoverPid = $this->option('takeover') !== null ? (int) $this->option('takeover') : null;

        if ($this->option('replace') && $takeoverPid !== null) {
            $this->components->error('--replace and --takeover are mutually exclusive.');

            return self::FAILURE;
        }

        try {
            $node = NodeName::resolve(
                is_string($this->option('node')) ? $this->option('node') : null,
                Narrow::map(config('torque')),
            );
            NodeName::setCurrent($node);
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // Refuse to start if a master is already running, unless this is the
        // takeover half of a reload replacing exactly that master, or an
        // explicit --replace start absorbing whatever master is live.
        $existingPid = MasterProcess::readPid();

        if ($existingPid !== null && $existingPid !== $takeoverPid && ! $this->option('replace')) {
            $this->components->error("Torque is already running (master PID {$existingPid}). Run torque:stop first, or torque:reload to replace it.");

            return self::FAILURE;
        }

        if ($this->option('replace') && $existingPid !== null) {
            // Supervisor convergence: a live master that is not ours (a stray
            // takeover master, a manual start) is absorbed via the same
            // handshake torque:reload uses, but WITHOUT the setsid detach:
            // this process must remain the supervisor's child so exactly one
            // supervised master exists once the old one has drained. If the
            // new fleet never becomes ready the takeover aborts, the old
            // master keeps running, and the supervisor retries.
            $this->components->warn("Live master (PID {$existingPid}) found; absorbing it via takeover handshake.");
            $takeoverPid = $existingPid;
        }

        if ($takeoverPid !== null && ! $this->option('replace')) {
            if ($existingPid === null) {
                // The old master is already gone; proceed as a normal start.
                $takeoverPid = null;
            } else {
                // Detach into an own session: the takeover master must survive
                // killasgroup on the old supervisor program and must not share
                // the reload shell's process group.
                @posix_setsid();
            }
        }

        // Node block over flat keys; CLI options below win over both.
        try {
            $config = NodeConfig::resolve(Narrow::map(config('torque')), $node);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // Configured worker groups decide how --workers and --concurrency apply.
        $configuredGroups = is_array($config['groups'] ?? null) ? $config['groups'] : [];
        $groupNames = implode(', ', array_map(strval(...), array_keys($configuredGroups)));
        $groupSizeOverride = $this->option('queues') === null
            && ($this->option('workers') !== null || $this->option('concurrency') !== null);

        if ($groupSizeOverride && count($configuredGroups) > 1) {
            $this->components->error("--workers and --concurrency cannot be used with several worker groups ({$groupNames}). Pass --queues to replace the groups, or set the numbers per group in the nodes config.");

            return self::FAILURE;
        }

        // Apply CLI overrides.
        if ($this->option('workers') !== null) {
            $config['workers'] = (int) $this->option('workers');
        }

        if ($this->option('concurrency') !== null) {
            $config['slots_per_worker'] = (int) $this->option('concurrency');
        }

        // With exactly one configured group the overrides apply to that group.
        if ($groupSizeOverride && count($configuredGroups) === 1) {
            $only = array_key_first($configuredGroups);
            $block = Narrow::map($configuredGroups[$only]);

            if ($this->option('workers') !== null) {
                $block['workers'] = (int) $this->option('workers');
            }

            if ($this->option('concurrency') !== null) {
                $block['slots_per_worker'] = (int) $this->option('concurrency');
            }

            $configuredGroups[$only] = $block;
            $config['groups'] = $configuredGroups;
        }

        // Resolve queue names: CLI option > node block > config stream keys > fallback.
        if ($this->option('queues') !== null) {
            $config['queues'] = array_map('trim', explode(',', $this->option('queues')));

            foreach ($config['queues'] as $queue) {
                if (! preg_match('/^[a-zA-Z0-9_\-.:]+$/D', $queue)) {
                    $this->components->error("Invalid queue name: {$queue}");

                    return self::FAILURE;
                }
            }

            if ($configuredGroups !== []) {
                unset($config['groups']);
                $this->components->info("--queues replaces the configured worker groups ({$groupNames}) with one group named default.");
            }
        } elseif (! is_array($config['queues'] ?? null) || $config['queues'] === []) {
            // Queues set by the node block are already validated against streams.
            $config['queues'] = is_array($config['streams'] ?? null) ? array_keys($config['streams']) : ['default'];
        }

        try {
            $config['groups'] = NodeConfig::groups($config);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // A missing igbinary is a config refusal, so it comes before the sweep;
        // the serializer info lines follow the start banner.
        if (Narrow::string($config['serializer'] ?? null, 'json') === 'igbinary' && ! extension_loaded('igbinary')) {
            $this->components->error('TORQUE_SERIALIZER=igbinary but ext-igbinary is not loaded. Install with: pecl install igbinary');

            return self::FAILURE;
        }

        // Sweep orphaned workers: a master killed with SIGKILL (OOM,
        // supervisor stop failure) leaves no master process behind, only its
        // reparented workers, and those must not survive into the new fleet
        // or every hard death doubles it. Filtered by parentage so a fleet
        // whose master is alive (the draining side of a takeover, another
        // release on the same host) is never touched. The first pattern
        // character is bracketed so the `sh -c` wrapper PHP spawns for
        // exec(), whose own argv contains the pattern text, never matches
        // itself. It runs only once every option and config refusal above
        // has passed, so a refused start has no side effect.
        $workerOutput = [];
        exec('pgrep -f '.escapeshellarg('[a]rtisan torque:worker'), $workerOutput);
        $orphanWorkers = array_filter(
            array_map('intval', $workerOutput),
            function (int $pid): bool {
                if ($pid <= 0 || $pid === getmypid()) {
                    return false;
                }

                $parent = ProcessInspector::parentPid($pid);

                // Only a worker whose parent is not a live torque master is
                // an orphan; unknown parentage is left alone.
                return $parent !== null && ! ProcessInspector::isTorqueMaster($parent);
            },
        );

        if ($orphanWorkers !== []) {
            $this->components->warn(
                'Killing orphaned torque:worker process(es): '.implode(', ', $orphanWorkers),
            );

            foreach ($orphanWorkers as $pid) {
                posix_kill($pid, SIGKILL);
            }

            usleep(200_000);
        }

        $groups = $config['groups'];
        $grouped = count($groups) > 1 || $groups[0]['name'] !== NodeConfig::DEFAULT_GROUP;

        $workers = Narrow::int($config['workers'] ?? null, 4);
        $concurrency = Narrow::int($config['slots_per_worker'] ?? null, 50);
        $queues = implode(', ', array_map(strval(...), array_filter((array) $config['queues'], is_scalar(...))));

        if ($grouped) {
            $workers = array_sum(array_column($groups, 'workers'));
            $concurrency = max([1, ...array_column($groups, 'slots_per_worker')]);
            $queues = implode(', ', array_unique(array_merge(...array_column($groups, 'queues'))));
        }

        $version = InstalledVersions::getPrettyVersion('webpatser/torque') ?? 'dev';
        $this->components->info("Torque {$version} starting with {$workers} workers x {$concurrency} coroutines");
        $this->components->info("Queues: {$queues}");

        if ($grouped) {
            foreach ($groups as $group) {
                $slots = $group['workers'] * $group['slots_per_worker'];
                $this->components->info("Group {$group['name']}: queues ".implode(', ', $group['queues'])."; {$group['workers']} workers x {$group['slots_per_worker']} coroutines ({$slots} slots)");
            }
        }
        $redisUri = Narrow::string(Narrow::map($config['redis'] ?? null)['uri'] ?? null, 'redis://127.0.0.1:6379');
        $this->components->info('Redis: '.preg_replace('/:([^@]+)@/', ':***@', $redisUri));

        // Serializer detection: hard error when igbinary is configured but the
        // extension is missing, gentle nudge when ext is missing under json,
        // confirmation when ext is present and configured.
        $serializer = Narrow::string($config['serializer'] ?? null, 'json');
        $hasIgbinary = extension_loaded('igbinary');

        if ($serializer === 'igbinary') {
            $this->components->info('Serializer: igbinary');
        } elseif ($serializer === 'json' && ! $hasIgbinary) {
            $this->components->info('Tip: install ext-igbinary for ~2x faster payload encoding (set TORQUE_SERIALIZER=igbinary).');
        }

        // The master's own loop (its Redis reads run on it), and the check that the
        // workers can start: they install the same driver, and an unusable value
        // would otherwise only show as a fleet that dies and respawns forever.
        // FLEDGE_EVENT_LOOP in the real process environment wins over a cached
        // torque.event_loop, so the rollback works without a config rebuild.
        $environment = EventLoopDriver::environment();
        $source = EventLoopDriver::source($environment);

        try {
            $driver = EventLoopDriver::install($config, fn (string $warning) => $this->components->warn($warning), $environment);
        } catch (\InvalidArgumentException|UnsupportedFeatureException $e) {
            $this->components->error("Unusable event loop driver from {$source}: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Event loop: {$driver} (from {$source})");

        if ($takeoverPid !== null) {
            $this->components->info("Takeover: replacing master PID {$takeoverPid} once this fleet is ready.");
        }

        $master = new MasterProcess(
            config: $config,
            logger: fn (string $message) => $this->components->info($message),
            takeoverPid: $takeoverPid,
            errorLogger: fn (string $message) => $this->components->error($message),
        );

        return $master->start();
    }
}
