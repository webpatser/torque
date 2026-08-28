<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Console\Command;
use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Redis\UpgradeRunner;
use Webpatser\Torque\Support\WorkerId;

use function Fledge\Async\Redis\createRedisClient;

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

        // One client for everything: a short-lived CLI process must not open
        // a connection per collaborator, so queue depths and dead-letter
        // counts are read with raw commands on this client instead of
        // resolving StreamQueue/DeadLetterHandler instances.
        $redis = createRedisClient($redisUri);

        $this->renderMasterStatus();
        $this->renderDataVersion($redis, $prefix);
        $this->renderOverallMetrics($redis, $prefix, $config);
        $this->renderCircuitBreakers($redis, $config);
        $this->renderWorkerTable($redis, $prefix);

        return self::SUCCESS;
    }

    /**
     * Display whether the master process is running based on the PID file.
     *
     * Uses {@see MasterProcess::readPid()} so the symlink guard, the
     * recycled-PID identity check, and stale-file semantics all match the
     * rest of the toolchain; a recycled PID must not render as RUNNING.
     */
    private function renderMasterStatus(): void
    {
        $pid = MasterProcess::readPid();

        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=cyan;options=bold>Master Status</>',
            $pid !== null
                ? "<fg=green;options=bold>RUNNING</> <fg=gray>(PID {$pid})</>"
                : '<fg=red;options=bold>STOPPED</>',
        );

        $this->newLine();
    }

    /**
     * Display the fleet summary: master-published aggregate first (it carries
     * a real throughput), live worker-hash aggregation as fallback, and
     * always-live queue depth / dead-letter counts, mirroring the dashboard's
     * OverviewData.
     *
     * @param  array<string, mixed>  $config
     */
    private function renderOverallMetrics(RedisClient $redis, string $prefix, array $config): void
    {
        $throughput = null;
        $concurrent = null;
        $avgLatency = null;

        try {
            $agg = $this->getHashAll($redis, $prefix.'metrics');

            if ($agg === []) {
                // No master-published aggregate: aggregate the live worker
                // hashes. The publisher is used purely for its aggregation
                // math; it never opens its own connection here.
                $workers = [];

                foreach ($this->scanKeys($redis, $prefix.'worker:*') as $key) {
                    $workers[substr($key, strlen($prefix.'worker:'))] = $this->getHashAll($redis, $key);
                }

                $agg = new MetricsPublisher('redis://unused')->aggregateFromWorkers($workers);
                // A one-shot snapshot cannot derive a rate; only the
                // master-published aggregate carries real throughput.
                unset($agg['throughput']);
            }

            if ((int) ($agg['workers'] ?? 0) > 0) {
                $throughput = isset($agg['throughput']) ? (string) $agg['throughput'] : null;
                $concurrent = (string) ($agg['concurrent'] ?? 0);
                $avgLatency = (string) ($agg['avg_latency'] ?? 0);
            }
        } catch (\Throwable) {
            // Placeholders below.
        }

        $pending = null;
        $failed = null;

        try {
            $consumerGroup = (string) ($config['consumer_group'] ?? 'torque');
            $cluster = (bool) ($config['cluster'] ?? ($config['redis']['cluster'] ?? false));
            $streams = array_keys((array) ($config['streams'] ?? []));
            $pendingCount = 0;

            foreach ($streams === [] ? ['default'] : $streams as $name) {
                $streamKey = $prefix.($cluster && ! str_contains((string) $name, '{') ? '{'.$name.'}' : $name);

                $pendingCount += max(0, (int) $redis->execute('XLEN', $streamKey));
                $pendingCount += max(0, (int) $redis->execute('ZCARD', $streamKey.':delayed'));
            }

            $pending = (string) $pendingCount;
            $failed = (string) max(0, (int) $redis->execute('XLEN', $prefix.'dead-letter'));
        } catch (\Throwable) {
            // Placeholders below.
        }

        $this->components->twoColumnDetail('Throughput (jobs/sec)', $this->formatMetric($throughput, '/s'));
        $this->components->twoColumnDetail('Concurrent Jobs', $this->formatMetric($concurrent));
        $this->components->twoColumnDetail('Avg Latency', $this->formatMetric($avgLatency, ' ms'));
        $this->components->twoColumnDetail('Pending', $this->formatMetric($pending));
        $this->components->twoColumnDetail('Failed (dead letter)', $this->formatMetric($failed));

        $this->newLine();
    }

    /**
     * Show the recorded data version next to the installed one.
     *
     * They differ only between a deploy and the first master start after it,
     * which is exactly when knowing the upgrade sweep has not run yet is
     * useful.
     */
    private function renderDataVersion(RedisClient $redis, string $prefix): void
    {
        $installed = UpgradeRunner::installedVersion();

        try {
            $stored = $redis->execute('GET', $prefix.UpgradeRunner::VERSION_KEY_SUFFIX);
        } catch (\Throwable) {
            $stored = null;
        }

        $stored = $stored === null ? null : (string) $stored;

        $this->components->twoColumnDetail(
            'Data version',
            $stored === null
                ? "<fg=yellow>not recorded</> <fg=gray>(upgrade runs on the next master start, installed {$installed})</>"
                : ($stored === $installed
                    ? "{$stored}"
                    : "{$stored} <fg=gray>(installed {$installed})</>"),
        );

        $this->newLine();
    }

    /**
     * List every stream whose circuit breaker is not closed.
     *
     * Nothing is printed while all breakers are closed, so the normal status
     * output is unchanged; a tripped stream is the exception worth a line.
     *
     * @param  array<string, mixed>  $config
     */
    private function renderCircuitBreakers(RedisClient $redis, array $config): void
    {
        $breaker = CircuitBreaker::fromConfig($config, client: $redis);
        $printed = false;

        foreach (array_keys((array) ($config['streams'] ?? [])) as $queue) {
            $state = $breaker->state((string) $queue);

            if ($state === null) {
                continue;
            }

            $detail = $state['state'] === 'open'
                ? '<fg=red;options=bold>OPEN</>'.($state['resumes_at'] !== null
                    ? ' <fg=gray>(probes in '.max(0, $state['resumes_at'] - time()).'s)</>'
                    : '')
                : '<fg=yellow;options=bold>HALF-OPEN</> <fg=gray>(probing)</>';

            $this->components->twoColumnDetail("Circuit breaker [{$queue}]", $detail);
            $printed = true;
        }

        if ($printed) {
            $this->newLine();
        }
    }

    /**
     * Discover and display per-worker metrics from `{prefix}worker:*` hashes.
     *
     * Expected hash fields per worker: pid, active_slots, total_slots,
     * jobs_processed, avg_latency, last_heartbeat.
     */
    private function renderWorkerTable(RedisClient $redis, string $prefix): void
    {
        $workerKeys = $this->scanKeys($redis, $prefix.'worker:*');

        if ($workerKeys === []) {
            $this->components->warn('No worker metrics found in Redis.');

            return;
        }

        // Sort by PID for consistent output.
        sort($workerKeys);

        $rows = [];

        $workerPrefix = $prefix.'worker:';

        foreach ($workerKeys as $key) {
            $fields = $this->getHashAll($redis, $key);

            // Prefer the published pid field; fall back to parsing the
            // `{host}-{pid}-{hex}` worker id for rows written by older code.
            $workerId = str_starts_with($key, $workerPrefix) ? substr($key, strlen($workerPrefix)) : $key;
            $pid = $fields['pid'] ?? WorkerId::parse($workerId)->pid ?? '?';
            $activeSlots = $fields['active_slots'] ?? '0';
            $totalSlots = $fields['total_slots'] ?? '0';
            $jobsProcessed = $fields['jobs_processed'] ?? '0';
            $jobsFailed = $fields['jobs_failed'] ?? '0';
            $avgLatency = $fields['avg_latency_ms'] ?? '0';
            $lastHeartbeat = $fields['last_heartbeat'] ?? null;

            $rows[] = [
                (string) $pid,
                "{$activeSlots}/{$totalSlots}",
                number_format((int) $jobsProcessed),
                number_format((int) $jobsFailed),
                round((float) $avgLatency, 2).' ms',
                $this->formatHeartbeat($lastHeartbeat),
            ];
        }

        $this->table(
            ['PID', 'Slots (active/total)', 'Jobs Processed', 'Failed', 'Avg Latency', 'Last Heartbeat'],
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
        } catch (RedisException) {
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
    private function formatMetric(?string $value, string $suffix = ''): string
    {
        if ($value === null || $value === '') {
            return '<fg=gray>--</>';
        }

        return number_format((float) $value, 2).$suffix;
    }

    /**
     * Format a Unix timestamp heartbeat as a human-readable "X seconds ago" string.
     */
    private function formatHeartbeat(?string $timestamp): string
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
