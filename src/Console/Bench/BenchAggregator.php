<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console\Bench;

/**
 * Pure data aggregator for bench samples.
 *
 * Holds one float (latency in ms) and one int (handle time in ns) per sample,
 * plus a workload tag, then computes percentiles by sorting the array. At the
 * scales we care about (10K - 100K samples) this is a sub-second operation.
 *
 * No t-digest dependency. No streaming math. Keep it boring.
 */
final class BenchAggregator
{
    /** @var list<float> End-to-end latency samples in milliseconds. */
    private array $latenciesMs = [];

    /** @var list<int> Per-job handle time samples in nanoseconds. */
    private array $handleNsSamples = [];

    /** @var array<string, int> Count per workload tag. */
    private array $workloadCounts = [];

    private ?float $wallStartedAt = null;

    private ?float $wallStoppedAt = null;

    public function wallStart(): void
    {
        $this->wallStartedAt = microtime(true);
    }

    public function wallStop(): void
    {
        $this->wallStoppedAt = microtime(true);
    }

    public function addSample(float $totalMs, int $handleNs, string $workload): void
    {
        $this->latenciesMs[] = $totalMs;
        $this->handleNsSamples[] = $handleNs;
        $this->workloadCounts[$workload] = ($this->workloadCounts[$workload] ?? 0) + 1;
    }

    public function count(): int
    {
        return count($this->latenciesMs);
    }

    /**
     * Build the final summary array.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $count = $this->count();
        $wall = $this->wallSeconds();

        $latencyStats = $this->computeLatencyStats();
        $handleMedian = $this->median($this->handleNsSamples);

        return [
            'count' => $count,
            'wall_seconds' => $wall,
            'throughput_per_sec' => ($wall !== null && $wall > 0.0) ? $count / $wall : 0.0,
            'latency_ms' => $latencyStats,
            'handle_ns_median' => $handleMedian,
            'workloads' => $this->workloadCounts,
            // TODO(bench): per-stage breakdown via BenchProbe (see plan section A5).
            'stages_ns_median' => null,
        ];
    }

    public function wallSeconds(): ?float
    {
        if ($this->wallStartedAt === null || $this->wallStoppedAt === null) {
            return null;
        }

        return max(0.0, $this->wallStoppedAt - $this->wallStartedAt);
    }

    /**
     * @return array{p50: float|null, p95: float|null, p99: float|null, mean: float|null, stddev: float|null}
     */
    private function computeLatencyStats(): array
    {
        if ($this->latenciesMs === []) {
            return ['p50' => null, 'p95' => null, 'p99' => null, 'mean' => null, 'stddev' => null];
        }

        $samples = $this->latenciesMs;
        sort($samples, SORT_NUMERIC);

        $count = count($samples);
        $mean = array_sum($samples) / $count;

        $variance = 0.0;
        foreach ($samples as $s) {
            $variance += ($s - $mean) ** 2;
        }
        $stddev = $count > 1 ? sqrt($variance / ($count - 1)) : 0.0;

        return [
            'p50' => $this->percentileFromSorted($samples, 0.50),
            'p95' => $this->percentileFromSorted($samples, 0.95),
            'p99' => $this->percentileFromSorted($samples, 0.99),
            'mean' => $mean,
            'stddev' => $stddev,
        ];
    }

    /**
     * Median of an int sample set, or null when empty.
     *
     * @param  list<int>  $samples
     */
    private function median(array $samples): ?int
    {
        if ($samples === []) {
            return null;
        }

        sort($samples, SORT_NUMERIC);

        $count = count($samples);
        $mid = (int) floor(($count - 1) / 2);

        return $samples[$mid];
    }

    /**
     * Percentile-by-index on an already-sorted list.
     *
     * @param  list<float>  $sorted
     */
    private function percentileFromSorted(array $sorted, float $p): float
    {
        $count = count($sorted);
        $idx = (int) floor($p * ($count - 1));
        $idx = max(0, min($count - 1, $idx));

        return $sorted[$idx];
    }
}
