<?php

declare(strict_types=1);

namespace Webpatser\Torque\Metrics;

/**
 * Immutable point-in-time snapshot of a single worker's metrics.
 *
 * Created by {@see MetricsCollector::snapshot()} and consumed by
 * {@see MetricsPublisher} for Redis publication and dashboard display.
 */
readonly class WorkerSnapshot
{
    public function __construct(
        public int $jobsProcessed,
        public int $jobsFailed,
        public int $activeSlots,
        public int $totalSlots,
        public float $averageLatencyMs,
        public float $slotUsageRatio,
        public int $memoryBytes,
        public int $timestamp,
    ) {}
}
