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
        /**
         * Per-stream counters, queue name => [processed, failed]. Defaults to
         * empty so a snapshot from a context without queue attribution (the
         * status command, tests) stays valid.
         *
         * @var array<string, array{0: int, 1: int}>
         */
        public array $perQueue = [],
        /**
         * Per-job-class counters, class => [processed, failed, runtimeSumMs,
         * runtimeMaxMs]. No memory figure on purpose: fibers run many jobs at
         * once in one process, so a per-job memory delta measures the
         * neighbours, not the job.
         *
         * @var array<string, array{0: int, 1: int, 2: float, 3: float}>
         */
        public array $perJob = [],
    ) {}
}
