<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Dashboard\Http\JobPresenter;
use Webpatser\Torque\Dashboard\Support\Range;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * Per-job-class read-model for the jobs screen.
 *
 * Reads the per-class rollups the master writes every tick, so the numbers are
 * a real history rather than whatever happened while the tab was open.
 *
 * There is deliberately no memory column: Torque runs many jobs concurrently as
 * fibers inside one process, so a before/after memory delta around a single job
 * measures its neighbours as much as itself.
 */
final class JobMetricsData
{
    /** Sort keys the screen offers, mapped to the row field they order by. */
    private const array SORTS = [
        'throughput' => 'throughput',
        'runtime' => 'avgRuntimeMs',
        'failures' => 'failed',
        'name' => 'class',
    ];

    public function __construct(private readonly MetricsPublisher $metrics) {}

    /**
     * @return array{jobs: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function get(string $range = '1h', string $sort = 'throughput', string $direction = 'desc'): array
    {
        $window = Range::make($range);
        $isMinuteTier = $window->tier === MetricsPublisher::TIER_MINUTE;
        $jobs = [];

        foreach ($this->metrics->jobClasses() as $class) {
            $series = $this->metrics->jobSeries($class, $window->tier, $window->count);

            $processed = 0;
            $failed = 0;
            $runtimeSum = 0.0;
            $runtimeMax = 0.0;

            foreach ($series as $bucket) {
                $processed += $bucket['processed'];
                $failed += $bucket['failed'];
                $runtimeSum += $bucket['runtimeSumMs'];
                $runtimeMax = max($runtimeMax, $bucket['runtimeMaxMs']);
            }

            $finished = $processed + $failed;

            // A class that ran once a year ago should not sit in the table for
            // a range it has no activity in.
            if ($finished === 0) {
                continue;
            }

            // The minute tier is already the spark's resolution, so reuse it
            // instead of reading the same class twice.
            $spark = $isMinuteTier
                ? $series
                : $this->metrics->jobSeries($class, MetricsPublisher::TIER_MINUTE, 60);

            ['ns' => $ns, 'cls' => $cls] = JobPresenter::splitName($class);

            $jobs[] = [
                'class' => $class,
                'ns' => $ns,
                'cls' => $cls,
                // Jobs per minute across the whole selected range, so the
                // column means the same thing at 1h and at 90d.
                'throughput' => round($finished / $window->minutes, 2),
                'processed' => $processed,
                'failed' => $failed,
                'failRate' => round($failed / $finished * 100, 2),
                'avgRuntimeMs' => round($runtimeSum / $finished, 1),
                'maxRuntimeMs' => round($runtimeMax, 1),
                'history' => array_map(
                    static fn (array $bucket): int => $bucket['processed'],
                    array_values(array_slice($spark, -60, preserve_keys: true)),
                ),
            ];
        }

        $jobs = self::sortRows($jobs, $sort, $direction);

        return [
            'jobs' => $jobs,
            'totals' => [
                'classes' => count($jobs),
                'processed' => array_sum(array_column($jobs, 'processed')),
                'failed' => array_sum(array_column($jobs, 'failed')),
                'slowest' => $jobs === [] ? 0.0 : max(array_column($jobs, 'maxRuntimeMs')),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private static function sortRows(array $jobs, string $sort, string $direction): array
    {
        $field = self::SORTS[$sort] ?? self::SORTS['throughput'];
        $ascending = $direction === 'asc';

        usort($jobs, static function (array $a, array $b) use ($field, $ascending): int {
            $comparison = $field === 'class'
                ? strcasecmp((string) $a[$field], (string) $b[$field])
                : $a[$field] <=> $b[$field];

            return $ascending ? $comparison : -$comparison;
        });

        return array_values($jobs);
    }

    #[\NoDiscard]
    public static function isValidRange(string $range): bool
    {
        return Range::isValid($range);
    }

    #[\NoDiscard]
    public static function isValidSort(string $sort): bool
    {
        return array_key_exists($sort, self::SORTS);
    }
}
