<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Dashboard\Http\JobPresenter;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Stream\JobStream;
use Webpatser\Torque\Support\StreamQueueResolver;

/**
 * Cluster overview read-model: aggregated metrics, queue totals, recent jobs.
 *
 * The single source of truth for the overview screen, reused by the Livewire
 * Overview component. Collaborators are resolved from the container, all pointed
 * at the configured Redis keyspace.
 */
final class OverviewData
{
    public function __construct(
        private readonly MetricsPublisher $metrics,
        private readonly JobStream $jobStream,
        private readonly DeadLetterHandler $deadLetter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        // Prefer the master-published aggregate (has a real throughput); fall
        // back to aggregating live worker hashes when no master is running.
        $agg = $this->metrics->getAggregatedMetrics();

        if ($agg === []) {
            $agg = $this->metrics->aggregateFromWorkers($this->metrics->getAllWorkerMetrics());
        }

        $throughput = (float) ($agg['throughput'] ?? 0);
        $concurrent = (int) ($agg['concurrent'] ?? 0);
        $totalSlots = (int) ($agg['total_slots'] ?? 0);
        $processed = (int) ($agg['jobs_processed'] ?? 0);
        $failed = (int) ($agg['jobs_failed'] ?? 0);

        $pending = 0;
        $delayed = 0;
        $queue = StreamQueueResolver::make();

        foreach (array_keys((array) config('torque.streams', [])) as $name) {
            $pending += $queue->pendingSize((string) $name);
            $delayed += $queue->delayedSize((string) $name);
        }

        $live = array_map(
            static fn (array $job): array => JobPresenter::fromRecent($job),
            $this->jobStream->recentJobs('active', 6),
        );

        return [
            'totals' => [
                'slots' => $totalSlots,
                'busy' => $concurrent,
                'pending' => $pending,
                'delayed' => $delayed,
                'rpm' => (int) round($throughput * 60),
                'util' => $totalSlots > 0 ? round($concurrent / $totalSlots, 4) : 0,
            ],
            'metrics' => [
                'throughput' => $throughput,
                'concurrent' => $concurrent,
                'latencyMs' => (float) ($agg['avg_latency'] ?? 0),
                'memoryMb' => (float) ($agg['memory_mb'] ?? 0),
                'failRate' => $processed > 0 ? round($failed / $processed * 100, 2) : 0,
                'jobsTotal' => $processed,
                'workers' => (int) ($agg['workers'] ?? 0),
            ],
            'live' => $live,
            'deadCount' => $this->deadLetter->count(),
        ];
    }
}
