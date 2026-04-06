<?php

declare(strict_types=1);

namespace Webpatser\Torque\Manager;

/**
 * Monitors coroutine slot usage across workers and recommends scale up/down decisions.
 *
 * The MasterProcess calls {@see evaluate()} on each monitoring tick, passing the
 * current worker count and per-worker slot metrics. The AutoScaler computes the
 * average slot utilization ratio and compares it against configurable thresholds
 * to recommend scaling actions, with a cooldown to prevent thrashing.
 */
final class AutoScaler
{
    private int $lastScaleAction = 0;

    /** Whether the cooldown period has elapsed since the last scaling action. */
    public bool $canScale {
        get => time() - $this->lastScaleAction >= $this->cooldownSeconds;
    }

    public function __construct(
        private readonly string $redisUri,
        public private(set) int $minWorkers = 2,
        public private(set) int $maxWorkers = 8,
        public private(set) float $scaleUpThreshold = 0.85,
        public private(set) float $scaleDownThreshold = 0.20,
        public private(set) int $cooldownSeconds = 30,
    ) {}

    /**
     * Evaluate current worker metrics and recommend a scaling decision.
     *
     * @param  int  $currentWorkers  Number of active worker processes.
     * @param  array<int, array{active: int, total: int}>  $workerMetrics  Per-worker slot usage.
     *     Each entry has `active` (slots currently processing a job) and `total` (max slots).
     */
    #[\NoDiscard]
    public function evaluate(int $currentWorkers, array $workerMetrics): ScaleDecision
    {
        if ($workerMetrics === []) {
            return ScaleDecision::NoChange;
        }

        $totalActive = 0;
        $totalSlots = 0;

        foreach ($workerMetrics as $metric) {
            $totalActive += $metric['active'];
            $totalSlots += $metric['total'];
        }

        // Guard against division by zero when all workers report zero capacity.
        if ($totalSlots === 0) {
            return ScaleDecision::NoChange;
        }

        $usageRatio = $totalActive / $totalSlots;

        if ($usageRatio > $this->scaleUpThreshold && $currentWorkers < $this->maxWorkers && $this->canScale) {
            return ScaleDecision::ScaleUp;
        }

        if ($usageRatio < $this->scaleDownThreshold && $currentWorkers > $this->minWorkers && $this->canScale) {
            return ScaleDecision::ScaleDown;
        }

        return ScaleDecision::NoChange;
    }

    /**
     * Record that a scaling action was taken, resetting the cooldown timer.
     */
    public function recordAction(): void
    {
        $this->lastScaleAction = time();
    }
}
