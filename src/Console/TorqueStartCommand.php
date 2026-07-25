<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\ProcessInspector;

/**
 * Start the Torque queue worker with N forked processes.
 *
 * Usage:
 *   php artisan torque:start
 *   php artisan torque:start --workers=8 --concurrency=100
 *   php artisan torque:start --queues=emails,notifications
 */
final class TorqueStartCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:start
        {--workers= : Number of worker processes}
        {--concurrency= : Coroutine slots per worker}
        {--queues= : Comma-separated queue names}
        {--takeover= : Internal: replace the running master with this PID (used by torque:reload)}';

    /** @var string */
    protected $description = 'Start the Torque coroutine-based queue worker';

    public function handle(): int
    {
        $takeoverPid = $this->option('takeover') !== null ? (int) $this->option('takeover') : null;

        // Refuse to start if a master is already running, unless this is the
        // takeover half of a reload replacing exactly that master.
        $existingPid = MasterProcess::readPid();

        if ($existingPid !== null && $existingPid !== $takeoverPid) {
            $this->components->error("Torque is already running (master PID {$existingPid}). Run torque:stop first, or torque:reload to replace it.");

            return self::FAILURE;
        }

        if ($takeoverPid !== null) {
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

        // Sweep orphaned workers: a master killed with SIGKILL (OOM,
        // supervisor stop failure) leaves no master process behind, only its
        // reparented workers, and those must not survive into the new fleet
        // or every hard death doubles it. Filtered by parentage so a fleet
        // whose master is alive (the draining side of a takeover, another
        // release on the same host) is never touched. The first pattern
        // character is bracketed so the `sh -c` wrapper PHP spawns for
        // exec(), whose own argv contains the pattern text, never matches
        // itself.
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

        /** @var array<string, mixed> $config */
        $config = config('torque');

        // Apply CLI overrides.
        if ($this->option('workers') !== null) {
            $config['workers'] = (int) $this->option('workers');
        }

        if ($this->option('concurrency') !== null) {
            $config['coroutines_per_worker'] = (int) $this->option('concurrency');
        }

        // Resolve queue names: CLI option > config stream keys > fallback.
        if ($this->option('queues') !== null) {
            $config['queues'] = array_map('trim', explode(',', $this->option('queues')));

            foreach ($config['queues'] as $queue) {
                if (! preg_match('/^[a-zA-Z0-9_\-.:]+$/', $queue)) {
                    $this->components->error("Invalid queue name: {$queue}");

                    return self::FAILURE;
                }
            }
        } elseif (isset($config['streams']) && is_array($config['streams'])) {
            $config['queues'] = array_keys($config['streams']);
        } else {
            $config['queues'] = ['default'];
        }

        $workers = (int) ($config['workers'] ?? 4);
        $concurrency = (int) ($config['coroutines_per_worker'] ?? 50);
        $queues = implode(', ', $config['queues']);

        $version = InstalledVersions::getPrettyVersion('webpatser/torque') ?? 'dev';
        $this->components->info("Torque {$version} starting with {$workers} workers x {$concurrency} coroutines");
        $this->components->info("Queues: {$queues}");
        $redisUri = $config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
        $this->components->info('Redis: '.preg_replace('/:([^@]+)@/', ':***@', $redisUri));

        // Serializer detection: hard error when igbinary is configured but the
        // extension is missing, gentle nudge when ext is missing under json,
        // confirmation when ext is present and configured.
        $serializer = (string) ($config['serializer'] ?? 'json');
        $hasIgbinary = extension_loaded('igbinary');

        if ($serializer === 'igbinary' && ! $hasIgbinary) {
            $this->components->error('TORQUE_SERIALIZER=igbinary but ext-igbinary is not loaded. Install with: pecl install igbinary');

            return self::FAILURE;
        }

        if ($serializer === 'igbinary' && $hasIgbinary) {
            $this->components->info('Serializer: igbinary');
        } elseif ($serializer === 'json' && ! $hasIgbinary) {
            $this->components->info('Tip: install ext-igbinary for ~2x faster payload encoding (set TORQUE_SERIALIZER=igbinary).');
        }

        if ($takeoverPid !== null) {
            $this->components->info("Takeover: replacing master PID {$takeoverPid} once this fleet is ready.");
        }

        $master = new MasterProcess(
            config: $config,
            logger: fn (string $message) => $this->components->info($message),
            takeoverPid: $takeoverPid,
        );

        return $master->start();
    }
}
