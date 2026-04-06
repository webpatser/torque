<?php

declare(strict_types=1);

namespace Webpatser\Torque\Metrics;

use SplFixedArray;

/**
 * Gathers real-time stats from a single worker process.
 *
 * Called periodically by the worker's Revolt timer to build a
 * {@see WorkerSnapshot} that the {@see MetricsPublisher} pushes to Redis.
 *
 * Latency samples are stored in a fixed-size circular buffer so memory
 * usage is constant regardless of how many jobs the worker has processed.
 */
final class MetricsCollector
{
    public private(set) int $jobsProcessed = 0;

    public private(set) int $jobsFailed = 0;

    public private(set) int $activeSlots = 0;

    public private(set) int $totalSlots;

    /** Slot usage as a ratio between 0.0 and 1.0. */
    public float $slotUsageRatio {
        get => $this->totalSlots > 0
            ? $this->activeSlots / $this->totalSlots
            : 0.0;
    }

    /** Circular buffer holding the most recent latency samples (ms). */
    private SplFixedArray $latencyBuffer;

    /** Write cursor into the circular buffer. */
    private int $latencyCursor = 0;

    /** Total number of latency samples recorded (may exceed buffer size). */
    private int $latencySamplesRecorded = 0;

    /**
     * @param  int  $totalSlots          Coroutine concurrency limit from config.
     * @param  int  $latencyWindowSize   Number of recent samples to keep for the rolling average.
     */
    public function __construct(
        int $totalSlots,
        private int $latencyWindowSize = 100,
    ) {
        $this->totalSlots = $totalSlots;
        $this->latencyBuffer = new SplFixedArray($this->latencyWindowSize);
    }

    /**
     * Record that a job has started processing (occupying a coroutine slot).
     */
    public function recordJobStarted(): void
    {
        $this->activeSlots++;
    }

    /**
     * Record that a job completed successfully.
     *
     * Frees one coroutine slot, increments the processed counter,
     * and records the latency sample.
     */
    public function recordJobCompleted(float $durationMs): void
    {
        $this->activeSlots = max(0, $this->activeSlots - 1);
        $this->jobsProcessed++;
        $this->recordLatency($durationMs);
    }

    /**
     * Record that a job failed.
     *
     * Frees one coroutine slot, increments the failure counter,
     * and records the latency sample.
     */
    public function recordJobFailed(float $durationMs): void
    {
        $this->activeSlots = max(0, $this->activeSlots - 1);
        $this->jobsFailed++;
        $this->recordLatency($durationMs);
    }

    /**
     * Compute the rolling average latency from the most recent samples.
     *
     * Returns 0.0 if no samples have been recorded yet.
     */
    #[\NoDiscard]
    public function getAverageLatencyMs(): float
    {
        $count = min($this->latencySamplesRecorded, $this->latencyWindowSize);

        if ($count === 0) {
            return 0.0;
        }

        $sum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $sum += (float) $this->latencyBuffer[$i];
        }

        return $sum / $count;
    }

    /**
     * Create an immutable snapshot of all current metrics.
     */
    #[\NoDiscard]
    public function snapshot(): WorkerSnapshot
    {
        return new WorkerSnapshot(
            jobsProcessed: $this->jobsProcessed,
            jobsFailed: $this->jobsFailed,
            activeSlots: $this->activeSlots,
            totalSlots: $this->totalSlots,
            averageLatencyMs: $this->getAverageLatencyMs(),
            slotUsageRatio: $this->slotUsageRatio,
            memoryBytes: memory_get_usage(true),
            timestamp: time(),
        );
    }

    /**
     * Reset all counters and latency samples.
     *
     * Primarily useful for testing.
     */
    public function reset(): void
    {
        $this->jobsProcessed = 0;
        $this->jobsFailed = 0;
        $this->activeSlots = 0;
        $this->latencyCursor = 0;
        $this->latencySamplesRecorded = 0;
        $this->latencyBuffer = new SplFixedArray($this->latencyWindowSize);
    }

    /**
     * Append a latency sample to the circular buffer.
     *
     * When the buffer is full, the oldest sample is overwritten.
     */
    private function recordLatency(float $durationMs): void
    {
        $this->latencyBuffer[$this->latencyCursor] = $durationMs;
        $this->latencyCursor = ($this->latencyCursor + 1) % $this->latencyWindowSize;
        $this->latencySamplesRecorded++;
    }
}
