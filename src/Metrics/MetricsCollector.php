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

    /** Peak active slots since last snapshot — captures true concurrency. */
    private int $peakActiveSlots = 0;

    public private(set) int $totalSlots;

    /** Slot usage as a ratio between 0.0 and 1.0. */
    public float $slotUsageRatio {
        get => $this->totalSlots > 0
            ? $this->activeSlots / $this->totalSlots
            : 0.0;
    }

    /**
     * Per-stream counters, queue name => [processed, failed].
     *
     * One entry per configured stream at most, so this stays a handful of
     * integers regardless of throughput.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $perQueue = [];

    /**
     * Per-job-class counters, class => [processed, failed, runtimeSumMs,
     * runtimeMaxMs].
     *
     * Bounded by the number of distinct job classes the worker handles, and the
     * worker is recycled long before that becomes interesting.
     *
     * @var array<string, array{0: int, 1: int, 2: float, 3: float}>
     */
    private array $perJob = [];

    /** Circular buffer holding the most recent latency samples (ms). */
    private SplFixedArray $latencyBuffer;

    /** Write cursor into the circular buffer. */
    private int $latencyCursor = 0;

    /** Total number of latency samples recorded (may exceed buffer size). */
    private int $latencySamplesRecorded = 0;

    /**
     * @param  int  $totalSlots  Coroutine concurrency limit from config.
     * @param  int  $latencyWindowSize  Number of recent samples to keep for the rolling average.
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

        if ($this->activeSlots > $this->peakActiveSlots) {
            $this->peakActiveSlots = $this->activeSlots;
        }
    }

    /**
     * Record that a job completed successfully.
     *
     * Frees one coroutine slot, increments the processed counter,
     * and records the latency sample.
     */
    public function recordJobCompleted(float $durationMs, ?string $queue = null, ?string $jobClass = null): void
    {
        $this->activeSlots = max(0, $this->activeSlots - 1);
        $this->jobsProcessed++;
        $this->recordLatency($durationMs);
        $this->recordQueueOutcome($queue, processed: 1, failed: 0);
        $this->recordJobClassOutcome($jobClass, $durationMs, processed: 1, failed: 0);
    }

    /**
     * Record that a job failed.
     *
     * Frees one coroutine slot, increments the failure counter,
     * and records the latency sample.
     */
    public function recordJobFailed(float $durationMs, ?string $queue = null, ?string $jobClass = null): void
    {
        $this->activeSlots = max(0, $this->activeSlots - 1);
        $this->jobsFailed++;
        $this->recordLatency($durationMs);
        $this->recordQueueOutcome($queue, processed: 0, failed: 1);
        $this->recordJobClassOutcome($jobClass, $durationMs, processed: 0, failed: 1);
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
        $peak = $this->peakActiveSlots;
        $this->peakActiveSlots = $this->activeSlots; // Reset for next interval.

        return new WorkerSnapshot(
            jobsProcessed: $this->jobsProcessed,
            jobsFailed: $this->jobsFailed,
            activeSlots: max($this->activeSlots, $peak),
            totalSlots: $this->totalSlots,
            averageLatencyMs: $this->getAverageLatencyMs(),
            slotUsageRatio: $this->totalSlots > 0
                ? max($this->activeSlots, $peak) / $this->totalSlots
                : 0.0,
            memoryBytes: memory_get_usage(true),
            timestamp: time(),
            perQueue: $this->perQueue,
            perJob: $this->perJob,
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
        $this->perQueue = [];
        $this->perJob = [];
        $this->latencyCursor = 0;
        $this->latencySamplesRecorded = 0;
        $this->latencyBuffer = new SplFixedArray($this->latencyWindowSize);
    }

    /**
     * Attribute an outcome to its stream.
     *
     * Jobs handled outside a named stream are still counted in the totals, they
     * just carry no per-stream attribution.
     */
    private function recordQueueOutcome(?string $queue, int $processed, int $failed): void
    {
        if ($queue === null || $queue === '') {
            return;
        }

        [$currentProcessed, $currentFailed] = $this->perQueue[$queue] ?? [0, 0];

        $this->perQueue[$queue] = [$currentProcessed + $processed, $currentFailed + $failed];
    }

    /**
     * Attribute an outcome and its runtime to the job class that produced it.
     *
     * Runtime is kept as a sum and a high-water mark rather than a list, so the
     * dashboard can show an average and a peak at constant memory.
     */
    private function recordJobClassOutcome(?string $jobClass, float $durationMs, int $processed, int $failed): void
    {
        if ($jobClass === null || $jobClass === '') {
            return;
        }

        [$currentProcessed, $currentFailed, $runtimeSum, $runtimeMax] = $this->perJob[$jobClass] ?? [0, 0, 0.0, 0.0];

        $this->perJob[$jobClass] = [
            $currentProcessed + $processed,
            $currentFailed + $failed,
            $runtimeSum + $durationMs,
            max($runtimeMax, $durationMs),
        ];
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
