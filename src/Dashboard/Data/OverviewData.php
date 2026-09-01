<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Dashboard\Http\JobPresenter;
use Webpatser\Torque\Dashboard\Support\Range;
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
     * @param  string  $range  A {@see Range} key; the dashboard's global window.
     * @return array<string, mixed>
     */
    public function get(string $range = '1h'): array
    {
        // Prefer the master-published aggregate (has a real throughput); fall
        // back to aggregating live worker hashes when no master is running.
        $agg = $this->metrics->getAggregatedMetrics();

        if ($agg === []) {
            $agg = $this->metrics->aggregateFromWorkers($this->metrics->getAllWorkerMetrics());
        }

        // Minute buckets are the persisted history; unlike the component's
        // rolling arrays they survive a page reload, and unlike the
        // instantaneous throughput they do not read as zero between bursts.
        // The gauge always reads the last hour of minutes, whatever range the
        // chart below it is showing.
        $buckets = $this->metrics->minuteBuckets(60);
        $jobsLastHour = array_sum($buckets);

        $window = Range::make($range);
        $counters = $this->metrics->series($window->tier, $window->count);

        $history = array_map(
            static fn (array $outcome): int => $outcome['processed'],
            array_values($counters),
        );

        // Gauge samples recorded by the master, so these sparklines survive a
        // reload instead of starting empty and filling up one poll at a time.
        // They follow the chart's range, and the failure ratio is derived from
        // the counters rather than stored twice.
        $gauges = $this->metrics->gaugeSeriesMulti([
            MetricsPublisher::GAUGE_LATENCY,
            MetricsPublisher::GAUGE_CONCURRENT,
            MetricsPublisher::GAUGE_MEMORY,
            MetricsPublisher::GAUGE_WORKER_MEMORY_PEAK,
            MetricsPublisher::GAUGE_PENDING,
            MetricsPublisher::GAUGE_DELAYED,
        ], $window->tier, $window->count);

        $series = [
            'latency' => array_map(
                static fn (array $sample): float => round($sample['avg'] / 1000, 3),
                array_values($gauges[MetricsPublisher::GAUGE_LATENCY]),
            ),
            'concurrent' => self::gaugeAverages($gauges[MetricsPublisher::GAUGE_CONCURRENT]),
            'memory' => self::gaugeAverages($gauges[MetricsPublisher::GAUGE_MEMORY]),
            'memoryPeak' => array_map(
                static fn (array $sample): float => $sample['max'],
                array_values($gauges[MetricsPublisher::GAUGE_WORKER_MEMORY_PEAK]),
            ),
            'pending' => self::gaugeAverages($gauges[MetricsPublisher::GAUGE_PENDING]),
            'delayed' => self::gaugeAverages($gauges[MetricsPublisher::GAUGE_DELAYED]),
            'failRate' => array_map(
                static function (array $outcome): float {
                    $finished = $outcome['processed'] + $outcome['failed'];

                    return $finished > 0 ? round($outcome['failed'] / $finished * 100, 2) : 0.0;
                },
                array_values($counters),
            ),
        ];

        // The master damps the 5-minute rate with an EMA before publishing.
        // Without a master there is no smoothed value, so derive the plain
        // 5-minute average from the same buckets.
        $perMinute = isset($agg['throughput_smoothed'])
            ? (float) $agg['throughput_smoothed']
            : MetricsPublisher::perMinuteRate($buckets, 5);
        $perMinute1 = isset($agg['throughput_1m'])
            ? (float) $agg['throughput_1m']
            : MetricsPublisher::perMinuteRate($buckets, 1);

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
                'rpm' => (int) round($perMinute),
                'gaugeMax' => self::gaugeMax($perMinute, $buckets),
                'util' => $totalSlots > 0 ? round($concurrent / $totalSlots, 4) : 0,
            ],
            'metrics' => [
                'throughput' => $throughput,
                'throughputPerMinute' => round($perMinute1, 2),
                'jobsLastHour' => $jobsLastHour,
                'concurrent' => $concurrent,
                'latencyMs' => (float) ($agg['avg_latency'] ?? 0),
                'memoryMb' => (float) ($agg['memory_mb'] ?? 0),
                'failRate' => $processed > 0 ? round($failed / $processed * 100, 2) : 0,
                'jobsTotal' => $processed,
                'workers' => (int) ($agg['workers'] ?? 0),
            ],
            'history' => $history,
            'series' => $series,
            // Always the last hour of minutes, for the widgets that are not
            // tied to the chart's range selector.
            'minuteHistory' => array_values($buckets),
            'live' => $live,
            'deadCount' => $this->deadLetter->count(),
        ];
    }

    /**
     * Ceiling for the throughput gauge, in jobs per minute.
     *
     * A fixed scale is useless on a bursty queue: 1500 jobs every five minutes
     * either pins the needle or leaves it flat against the wrong maximum. The
     * auto scale tracks the busiest minute of the last hour, rounded up to a
     * round number so the tick labels stay sane, and never drops below the
     * value currently being displayed.
     *
     * @param  array<int, int>  $buckets  Per-minute job counts.
     */
    /**
     * Project a gauge series onto its per-bucket averages.
     *
     * @param  array<int, array{avg: float, max: float}>  $series
     * @return list<float>
     */
    private static function gaugeAverages(array $series): array
    {
        return array_map(static fn (array $sample): float => $sample['avg'], array_values($series));
    }

    /**
     * Whether a chart range key is one this read-model knows about.
     */
    #[\NoDiscard]
    public static function isValidRange(string $range): bool
    {
        return Range::isValid($range);
    }

    #[\NoDiscard]
    public static function gaugeMax(float $current, array $buckets): int
    {
        $configured = config('torque.dashboard.gauge_max');

        if ($configured !== null) {
            return max(1, (int) $configured);
        }

        $peak = max($current, $buckets === [] ? 0 : max($buckets));

        return max(100, self::roundUpToNice($peak));
    }

    /**
     * Round up to the next 1, 2 or 5 times a power of ten (100, 200, 500,
     * 1000, ...), the scale steps a physical gauge would use.
     */
    private static function roundUpToNice(float $value): int
    {
        if ($value <= 0) {
            return 100;
        }

        $magnitude = 10 ** (int) floor(log10($value));
        $fraction = $value / $magnitude;

        $step = match (true) {
            $fraction <= 1 => 1,
            $fraction <= 2 => 2,
            $fraction <= 5 => 5,
            default => 10,
        };

        return (int) round($step * $magnitude);
    }
}
