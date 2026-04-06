<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Amp\Redis\RedisClient;
use Illuminate\Console\Command;

use function Amp\Redis\createRedisClient;

/**
 * Display the current status of the Torque queue worker.
 *
 * Reads metrics from Redis hashes published by workers and the master process,
 * and displays a summary table with throughput, concurrency, and per-worker stats.
 */
final class TorqueStatusCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:status';

    /** @var string */
    protected $description = 'Display the status of the Torque queue worker';

    public function handle(): int
    {
        /** @var array<string, mixed> $config */
        $config = config('torque');

        $redisUri = $config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
        $prefix = $config['redis']['prefix'] ?? 'torque:';

        $redis = createRedisClient($redisUri);

        $this->renderMasterStatus();
        $this->renderOverallMetrics($redis, $prefix);
        $this->renderWorkerTable($redis, $prefix);

        return self::SUCCESS;
    }

    /**
     * Display whether the master process is running based on the PID file.
     */
    private function renderMasterStatus(): void
    {
        $pidFile = storage_path('torque.pid');
        $running = false;
        $pid = null;

        if (file_exists($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));

            if ($pid > 0 && posix_kill($pid, 0)) {
                $running = true;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=cyan;options=bold>Master Status</>',
            $running
                ? "<fg=green;options=bold>RUNNING</> <fg=gray>(PID {$pid})</>"
                : '<fg=red;options=bold>STOPPED</>',
        );

        $this->newLine();
    }

    /**
     * Read and display overall metrics from the `{prefix}metrics` hash.
     *
     * Expected hash fields: throughput, concurrent, avg_latency, pending, failed.
     */
    private function renderOverallMetrics(RedisClient $redis, string $prefix): void
    {
        $metricsKey = $prefix . 'metrics';

        $fields = $this->getHashAll($redis, $metricsKey);

        $this->components->twoColumnDetail('Throughput (jobs/sec)', $this->formatMetric($fields['throughput'] ?? null, '/s'));
        $this->components->twoColumnDetail('Concurrent Jobs', $this->formatMetric($fields['concurrent'] ?? null));
        $this->components->twoColumnDetail('Avg Latency', $this->formatMetric($fields['avg_latency'] ?? null, ' ms'));
        $this->components->twoColumnDetail('Pending', $this->formatMetric($fields['pending'] ?? null));
        $this->components->twoColumnDetail('Failed', $this->formatMetric($fields['failed'] ?? null));

        $this->newLine();
    }

    /**
     * Discover and display per-worker metrics from `{prefix}worker:*` hashes.
     *
     * Expected hash fields per worker: pid, active_slots, total_slots,
     * jobs_processed, avg_latency, last_heartbeat.
     */
    private function renderWorkerTable(RedisClient $redis, string $prefix): void
    {
        $workerKeys = $this->scanKeys($redis, $prefix . 'worker:*');

        if ($workerKeys === []) {
            $this->components->warn('No worker metrics found in Redis.');

            return;
        }

        // Sort by PID for consistent output.
        sort($workerKeys);

        $rows = [];

        foreach ($workerKeys as $key) {
            $fields = $this->getHashAll($redis, $key);

            $pid = $fields['pid'] ?? '?';
            $activeSlots = $fields['active_slots'] ?? '0';
            $totalSlots = $fields['total_slots'] ?? '0';
            $jobsProcessed = $fields['jobs_processed'] ?? '0';
            $avgLatency = $fields['avg_latency'] ?? '0';
            $lastHeartbeat = $fields['last_heartbeat'] ?? null;

            $rows[] = [
                $pid,
                "{$activeSlots}/{$totalSlots}",
                number_format((int) $jobsProcessed),
                round((float) $avgLatency, 2) . ' ms',
                $this->formatHeartbeat($lastHeartbeat),
            ];
        }

        $this->table(
            ['PID', 'Slots (active/total)', 'Jobs Processed', 'Avg Latency', 'Last Heartbeat'],
            $rows,
        );
    }

    /**
     * Read all fields from a Redis hash as a key-value array.
     *
     * @return array<string, string>
     */
    private function getHashAll(RedisClient $redis, string $key): array
    {
        try {
            /** @var array|null $result */
            $result = $redis->execute('HGETALL', $key);
        } catch (\Amp\Redis\RedisException) {
            return [];
        }

        if (! is_array($result) || $result === []) {
            return [];
        }

        // HGETALL returns a flat list: [field, value, field, value, ...]
        $fields = [];
        for ($i = 0, $count = count($result); $i < $count; $i += 2) {
            $fields[(string) $result[$i]] = (string) $result[$i + 1];
        }

        return $fields;
    }

    /**
     * Scan Redis for keys matching a pattern.
     *
     * Uses SCAN with COUNT to avoid blocking on large key spaces.
     *
     * @return string[]
     */
    private function scanKeys(RedisClient $redis, string $pattern): array
    {
        $keys = [];
        $cursor = '0';

        do {
            /** @var array $result */
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            $cursor = (string) $result[0];
            $batch = $result[1] ?? [];

            foreach ($batch as $key) {
                $keys[] = (string) $key;
            }
        } while ($cursor !== '0');

        return $keys;
    }

    /**
     * Format a metric value for display, with an optional unit suffix.
     */
    private function formatMetric(string|null $value, string $suffix = ''): string
    {
        if ($value === null || $value === '') {
            return '<fg=gray>--</>';
        }

        return number_format((float) $value, 2) . $suffix;
    }

    /**
     * Format a Unix timestamp heartbeat as a human-readable "X seconds ago" string.
     */
    private function formatHeartbeat(string|null $timestamp): string
    {
        if ($timestamp === null || $timestamp === '' || $timestamp === '0') {
            return '<fg=gray>--</>';
        }

        $elapsed = time() - (int) $timestamp;

        if ($elapsed < 0) {
            return '<fg=gray>--</>';
        }

        if ($elapsed < 5) {
            return "<fg=green>{$elapsed}s ago</>";
        }

        if ($elapsed < 30) {
            return "<fg=yellow>{$elapsed}s ago</>";
        }

        return "<fg=red>{$elapsed}s ago</>";
    }
}
