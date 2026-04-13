<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Process\MasterProcess;

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
        {--queues= : Comma-separated queue names}';

    /** @var string */
    protected $description = 'Start the Torque coroutine-based queue worker';

    public function handle(): int
    {
        // Refuse to start if a master is already running (PID file or actual process).
        $existingPid = MasterProcess::readPid();

        if ($existingPid !== null) {
            $this->components->error("Torque is already running (master PID {$existingPid}). Run torque:stop first.");

            return self::FAILURE;
        }

        // Also check for orphaned master processes whose PID file was lost.
        // Scope pgrep to this project's base path to avoid matching other projects.
        $basePath = base_path();
        $output = [];
        exec('pgrep -f ' . escapeshellarg($basePath . '/artisan torque:start'), $output);
        $otherMasters = array_filter(
            array_map('intval', $output),
            fn (int $pid) => $pid > 0 && $pid !== getmypid(),
        );

        if ($otherMasters !== []) {
            $this->components->error(
                'Found running torque:start process(es): ' . implode(', ', $otherMasters) . '. Run torque:stop first or kill them manually.',
            );

            return self::FAILURE;
        }

        /** @var array<string, mixed> $config */
        $config = config('torque');

        // Kill any orphaned worker processes and clean stale metrics from a previous run.
        $this->cleanupOrphans();

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
                if (!preg_match('/^[a-zA-Z0-9_\-.:]+$/', $queue)) {
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

        $this->components->info("Torque starting with {$workers} workers x {$concurrency} coroutines");
        $this->components->info("Queues: {$queues}");
        $redisUri = $config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
        $this->components->info('Redis: ' . preg_replace('/:([^@]+)@/', ':***@', $redisUri));

        $master = new MasterProcess(
            config: $config,
            logger: fn (string $message) => $this->components->info($message),
        );

        return $master->start();
    }

    /**
     * Clean stale worker metrics from Redis left by a previous run.
     */
    private function cleanupOrphans(): void
    {
        try {
            app(MetricsPublisher::class)->removeAllWorkerMetrics();
        } catch (\Throwable) {
            // Best-effort — Redis may be unavailable.
        }
    }
}
