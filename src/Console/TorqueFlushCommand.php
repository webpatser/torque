<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Process\MasterProcess;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Flush all pending and delayed jobs from Torque queues.
 *
 * Already claimed (in-flight) jobs continue processing normally.
 * Only unclaimed stream entries and delayed jobs are removed.
 *
 * Usage:
 *   php artisan torque:flush
 *   php artisan torque:flush --force
 *   php artisan torque:flush --queues=emails,notifications
 */
final class TorqueFlushCommand extends Command
{
    protected $signature = 'torque:flush
        {--force : Force flush in production}
        {--queues= : Comma-separated queue names (default: all configured)}';

    protected $description = 'Flush all pending and delayed jobs from Torque queues';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force to flush in production.');

            return self::FAILURE;
        }

        $config = config('torque');
        $prefix = $config['redis']['prefix'] ?? 'torque:';
        $redisUri = $config['redis']['uri'] ?? 'redis://127.0.0.1:6379';

        // Resolve queue names.
        if ($this->option('queues') !== null) {
            $queues = array_map('trim', explode(',', $this->option('queues')));
        } elseif (isset($config['streams']) && is_array($config['streams'])) {
            $queues = array_keys($config['streams']);
        } else {
            $queues = ['default'];
        }

        // Warn if Torque is running.
        if (MasterProcess::readPid() !== null) {
            $this->components->warn('Torque is running. In-flight jobs will complete normally. Only queued/delayed jobs are flushed.');
        }

        try {
            $redis = createRedisClient($redisUri);
        } catch (\Throwable $e) {
            $this->components->error("Cannot connect to Redis: {$e->getMessage()}");

            return self::FAILURE;
        }

        $totalStream = 0;
        $totalDelayed = 0;

        foreach ($queues as $queue) {
            $streamKey = $prefix . $queue;
            $delayedKey = $streamKey . ':delayed';

            // Count before flushing.
            $streamSize = (int) $redis->execute('XLEN', $streamKey);
            $delayedSize = (int) $redis->execute('ZCARD', $delayedKey);

            // XTRIM MAXLEN 0 removes all unclaimed entries; in-flight (PEL) jobs are unaffected.
            if ($streamSize > 0) {
                $redis->execute('XTRIM', $streamKey, 'MAXLEN', '0');
            }

            // Delete the delayed sorted set entirely.
            if ($delayedSize > 0) {
                $redis->execute('DEL', $delayedKey);
            }

            $totalStream += $streamSize;
            $totalDelayed += $delayedSize;

            $this->components->twoColumnDetail(
                $queue,
                "{$streamSize} queued, {$delayedSize} delayed",
            );
        }

        // Clean up orphaned job payload keys.
        $jobsDeleted = $this->deleteJobKeys($redis, $prefix);

        $this->newLine();
        $this->components->info("Flushed {$totalStream} queued, {$totalDelayed} delayed, {$jobsDeleted} job keys.");

        return self::SUCCESS;
    }

    /**
     * Delete individual job payload keys using SCAN to avoid blocking Redis.
     */
    private function deleteJobKeys(mixed $redis, string $prefix): int
    {
        $deleted = 0;
        $cursor = '0';
        $pattern = $prefix . 'job:*';

        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '200');
            $cursor = (string) $result[0];
            $keys = $result[1] ?? [];

            if ($keys !== []) {
                $redis->execute('DEL', ...$keys);
                $deleted += count($keys);
            }
        } while ($cursor !== '0');

        return $deleted;
    }
}
