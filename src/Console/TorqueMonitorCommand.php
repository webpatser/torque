<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Process\MasterProcess;

use function Amp\Redis\createRedisClient;

/**
 * Real-time terminal dashboard for Torque — htop-style monitor.
 *
 * Usage:
 *   php artisan torque:monitor
 *   php artisan torque:monitor --refresh=1000
 */
final class TorqueMonitorCommand extends Command
{
    protected $signature = 'torque:monitor
        {--refresh=500 : Refresh interval in milliseconds}';

    protected $description = 'Live terminal dashboard for Torque workers';

    private bool $shouldStop = false;

    private int $startedAt;

    private int $lastLineCount = 0;

    /** Circular buffer of [timestamp, totalJobs] samples for rolling throughput. */
    private array $throughputSamples = [];

    private int $throughputCursor = 0;

    private const int THROUGHPUT_WINDOW = 120; // 60s at 500ms refresh

    public function handle(): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () {
            $this->shouldStop = true;
        });

        $refreshMs = max(100, (int) $this->option('refresh'));
        $this->startedAt = time();

        $publisher = app(MetricsPublisher::class);
        $config = config('torque');
        $prefix = $config['redis']['prefix'] ?? 'torque:';
        $redisUri = $config['redis']['uri'] ?? 'redis://127.0.0.1:6379';

        // Hide cursor for cleaner rendering.
        $this->output->write("\033[?25l");

        try {
            while (!$this->shouldStop) {
                $this->render($publisher, $config, $prefix, $redisUri);
                usleep($refreshMs * 1000);
            }
        } finally {
            // Show cursor, clear screen, print exit message.
            $this->output->write("\033[?25h");
            $this->newLine();
            $this->components->info('Monitor stopped.');
        }

        return self::SUCCESS;
    }

    /** @var string[] */
    private array $lines = [];

    private function render(MetricsPublisher $publisher, array $config, string $prefix, string $redisUri): void
    {
        $this->lines = [];

        $workers = $publisher->getAllWorkerMetrics();
        $metrics = $this->aggregateFromWorkers($workers);
        $masterPid = MasterProcess::readPid();

        // Check pause state.
        $paused = false;
        try {
            $redis = createRedisClient($redisUri);
            $paused = (bool) $redis->execute('EXISTS', $prefix . 'paused');
        } catch (\Throwable) {
        }

        $this->renderHeader($metrics, $masterPid, $paused);
        $this->renderMetrics($metrics);
        $this->renderWorkers($workers);
        $this->renderQueues($config, $redisUri, $prefix);
        $this->renderFooter($metrics, $paused, (int) $this->option('refresh'));

        // Build frame: cursor home, each line clears to EOL, then wipe leftover lines.
        $frame = "\033[H";

        foreach ($this->lines as $line) {
            $frame .= $line . "\033[K\n";
        }

        // Clear any leftover lines from the previous (longer) frame.
        $currentCount = count($this->lines);
        for ($i = $currentCount; $i < $this->lastLineCount; $i++) {
            $frame .= "\033[K\n";
        }

        $this->lastLineCount = $currentCount;

        // Single write — minimal I/O, zero flicker.
        $this->output->write($frame);
    }

    /**
     * Buffer a line for the current frame (replaces $this->output->writeln).
     */
    private function bufferLine(string $content = ''): void
    {
        $this->lines[] = $content;
    }

    private function renderHeader(array $metrics, ?int $masterPid, bool $paused): void
    {
        $workerCount = (int) ($metrics['workers'] ?? 0);
        $totalSlots = (int) ($metrics['total_slots'] ?? 0);
        $updatedAt = (int) ($metrics['updated_at'] ?? 0);
        $age = $updatedAt > 0 ? time() - $updatedAt : 0;

        $status = match (true) {
            $paused => "\033[33m⏸ PAUSED\033[0m",
            $masterPid !== null && $age < 10 => "\033[32m● RUNNING\033[0m",
            $masterPid !== null => "\033[33m● STALE\033[0m",
            default => "\033[31m● STOPPED\033[0m",
        };

        $this->bufferLine(" \033[1m⚙ Torque Monitor\033[0m                        {$status}  {$workerCount} workers │ {$totalSlots} slots │ {$age}s ago");
        $this->bufferLine(' ' . str_repeat('─', 78));
    }

    private function renderMetrics(array $m): void
    {
        $throughput = round((float) ($m['throughput'] ?? 0), 1);
        $concurrent = (int) ($m['concurrent'] ?? 0);
        $totalSlots = (int) ($m['total_slots'] ?? 0);
        $pct = $totalSlots > 0 ? round($concurrent / $totalSlots * 100) : 0;
        $latency = round((float) ($m['avg_latency'] ?? 0), 1);
        $memory = round((float) ($m['memory_mb'] ?? 0), 1);
        $processed = number_format((int) ($m['jobs_processed'] ?? 0));
        $failed = number_format((int) ($m['jobs_failed'] ?? 0));

        $pctColor = match (true) {
            $pct >= 85 => "\033[31m",
            $pct >= 60 => "\033[33m",
            default => "\033[32m",
        };

        $latencyColor = match (true) {
            $latency >= 1000 => "\033[31m",
            $latency >= 200 => "\033[33m",
            default => "\033[32m",
        };

        $failedColor = ((int) ($m['jobs_failed'] ?? 0)) > 0 ? "\033[31m" : "\033[32m";

        $this->bufferLine();
        $this->bufferLine(sprintf(
            "  Throughput   \033[1m%-16s\033[0m Concurrent   {$pctColor}\033[1m%s/%s (%s%%)\033[0m",
            "{$throughput} jobs/s",
            $concurrent,
            $totalSlots,
            $pct,
        ));
        $this->bufferLine(sprintf(
            "  Avg Latency  {$latencyColor}\033[1m%-16s\033[0m Memory       \033[1m%s MB\033[0m",
            "{$latency} ms",
            $memory,
        ));
        $this->bufferLine(sprintf(
            "  Processed    \033[1m%-16s\033[0m Failed       {$failedColor}\033[1m%s\033[0m",
            $processed,
            $failed,
        ));
    }

    private function renderWorkers(array $workers): void
    {
        $this->bufferLine();
        $this->bufferLine("  \033[1mWorkers\033[0m");

        if ($workers === []) {
            $this->bufferLine("  \033[2mNo workers reporting\033[0m");
            return;
        }

        $barWidth = 20;

        $this->bufferLine('  ┌' . str_repeat('─', 24) . '┬' . str_repeat('─', 8) . '┬' . str_repeat('─', $barWidth + 10) . '┬' . str_repeat('─', 10) . '┬' . str_repeat('─', 8) . '┐');
        $this->bufferLine(sprintf(
            '  │ %-22s │ %-6s │ %-' . ($barWidth + 8) . 's │ %-8s │ %-6s │',
            'ID', 'Status', 'Slots', 'Jobs', 'Latency',
        ));
        $this->bufferLine('  ├' . str_repeat('─', 24) . '┼' . str_repeat('─', 8) . '┼' . str_repeat('─', $barWidth + 10) . '┼' . str_repeat('─', 10) . '┼' . str_repeat('─', 8) . '┤');

        foreach ($workers as $id => $w) {
            $active = (int) ($w['active_slots'] ?? 0);
            $total = (int) ($w['total_slots'] ?? 1);
            $usage = $total > 0 ? $active / $total : 0;
            $heartbeat = (int) ($w['last_heartbeat'] ?? 0);
            $age = time() - $heartbeat;
            $jobs = number_format((int) ($w['jobs_processed'] ?? 0));
            $latency = round((float) ($w['avg_latency_ms'] ?? 0));

            // Status.
            $statusStr = $age < 10 ? "\033[32m● OK  \033[0m" : "\033[33m● Stale\033[0m";

            // Slot bar.
            $filled = (int) round($usage * $barWidth);
            $empty = $barWidth - $filled;
            $barColor = match (true) {
                $usage >= 0.85 => "\033[31m",
                $usage >= 0.60 => "\033[33m",
                default => "\033[32m",
            };
            $bar = $barColor . str_repeat('█', $filled) . "\033[0m" . str_repeat('░', $empty);
            $slotLabel = sprintf('%d/%d', $active, $total);

            // Truncate ID to 22 chars.
            $shortId = strlen($id) > 22 ? substr($id, 0, 19) . '...' : $id;

            $this->bufferLine(sprintf(
                '  │ %-22s │ %s │ %s %-7s │ %8s │ %4dms │',
                $shortId,
                $statusStr,
                $bar,
                $slotLabel,
                $jobs,
                $latency,
            ));
        }

        $this->bufferLine('  └' . str_repeat('─', 24) . '┴' . str_repeat('─', 8) . '┴' . str_repeat('─', $barWidth + 10) . '┴' . str_repeat('─', 10) . '┴' . str_repeat('─', 8) . '┘');
    }

    private function renderQueues(array $config, string $redisUri, string $prefix): void
    {
        $streams = $config['streams'] ?? [];
        if ($streams === []) {
            return;
        }

        $this->bufferLine();
        $this->bufferLine("  \033[1mQueues\033[0m");

        $this->bufferLine('  ┌' . str_repeat('─', 20) . '┬' . str_repeat('─', 8) . '┬' . str_repeat('─', 9) . '┬' . str_repeat('─', 9) . '┐');
        $this->bufferLine(sprintf(
            '  │ %-18s │ %6s │ %7s │ %7s │',
            'Queue', 'Size', 'Pending', 'Delayed',
        ));
        $this->bufferLine('  ├' . str_repeat('─', 20) . '┼' . str_repeat('─', 8) . '┼' . str_repeat('─', 9) . '┼' . str_repeat('─', 9) . '┤');

        try {
            $redis = createRedisClient($redisUri);

            foreach (array_keys($streams) as $queue) {
                $streamKey = $prefix . $queue;
                $consumerGroup = $config['consumer_group'] ?? 'torque';

                $size = (int) $redis->execute('XLEN', $streamKey);
                $delayed = (int) $redis->execute('ZCARD', $streamKey . ':delayed');

                $pending = 0;
                try {
                    $result = $redis->execute('XPENDING', $streamKey, $consumerGroup);
                    $pending = (int) ($result[0] ?? 0);
                } catch (\Throwable) {
                }

                $this->bufferLine(sprintf(
                    '  │ %-18s │ %6s │ %7s │ %7s │',
                    $queue,
                    number_format($size),
                    number_format($pending),
                    number_format($delayed),
                ));
            }
        } catch (\Throwable) {
            $this->bufferLine("  │ \033[2mRedis unavailable\033[0m" . str_repeat(' ', 27) . '│');
        }

        $this->bufferLine('  └' . str_repeat('─', 20) . '┴' . str_repeat('─', 8) . '┴' . str_repeat('─', 9) . '┴' . str_repeat('─', 9) . '┘');
    }

    private function renderFooter(array $metrics, bool $paused, int $refreshMs): void
    {
        $this->bufferLine();

        $pausedStr = $paused ? "\033[33mYes\033[0m" : "\033[32mNo\033[0m";
        $failedCount = (int) ($metrics['jobs_failed'] ?? 0);
        $failedStr = $failedCount > 0 ? "\033[31m{$failedCount}\033[0m" : "\033[32m0\033[0m";

        $uptime = $this->formatUptime();

        $this->bufferLine("  Failed: {$failedStr} in dead letter  │  Paused: {$pausedStr}  │  Uptime: {$uptime}");
        $this->bufferLine("  \033[2mPress Ctrl+C to exit  │  Refresh: {$refreshMs}ms\033[0m");
    }

    private function formatUptime(): string
    {
        $seconds = time() - $this->startedAt;

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            $s = $seconds % 60;
            return "{$m}m {$s}s";
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return "{$h}h {$m}m";
    }

    /**
     * Aggregate metrics from per-worker hashes into a summary.
     *
     * @param  array<string, array<string, string>>  $workers
     * @return array<string, mixed>
     */
    private function aggregateFromWorkers(array $workers): array
    {
        $totalProcessed = 0;
        $totalFailed = 0;
        $totalSlots = 0;
        $totalActive = 0;
        $totalMemory = 0;
        $latencySum = 0.0;
        $latencyCount = 0;
        $latestHeartbeat = 0;

        foreach ($workers as $w) {
            $totalProcessed += (int) ($w['jobs_processed'] ?? 0);
            $totalFailed += (int) ($w['jobs_failed'] ?? 0);
            $totalSlots += (int) ($w['total_slots'] ?? 0);
            $totalActive += (int) ($w['active_slots'] ?? 0);
            $totalMemory += (int) ($w['memory_bytes'] ?? 0);
            $heartbeat = (int) ($w['last_heartbeat'] ?? 0);

            if ($heartbeat > $latestHeartbeat) {
                $latestHeartbeat = $heartbeat;
            }

            $avgLatency = (float) ($w['avg_latency_ms'] ?? 0);
            $processed = (int) ($w['jobs_processed'] ?? 0);
            if ($processed > 0) {
                $latencySum += $avgLatency * $processed;
                $latencyCount += $processed;
            }
        }

        // Rolling throughput over a 60-second window.
        $now = microtime(true);
        $totalJobs = $totalProcessed + $totalFailed;

        $this->throughputSamples[$this->throughputCursor] = [$now, $totalJobs];
        $this->throughputCursor = ($this->throughputCursor + 1) % self::THROUGHPUT_WINDOW;

        $throughput = 0.0;
        $oldestIdx = count($this->throughputSamples) >= self::THROUGHPUT_WINDOW
            ? $this->throughputCursor
            : 0;

        $oldest = $this->throughputSamples[$oldestIdx];
        $elapsed = $now - $oldest[0];

        if ($elapsed > 0.5) {
            $throughput = ($totalJobs - $oldest[1]) / $elapsed;
        }

        return [
            'workers' => count($workers),
            'total_slots' => $totalSlots,
            'concurrent' => $totalActive,
            'jobs_processed' => $totalProcessed,
            'jobs_failed' => $totalFailed,
            'throughput' => max(0, $throughput),
            'avg_latency' => $latencyCount > 0 ? $latencySum / $latencyCount : 0,
            'memory_mb' => round($totalMemory / 1024 / 1024, 1),
            'updated_at' => $latestHeartbeat,
        ];
    }
}
