<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Dashboard\Support\Range;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Support\WorkerId;

/**
 * Per-host read-model for the workers screen.
 *
 * Grouped by host rather than by worker process, because that is the only
 * identity with a history: a worker mints a fresh `{host}-{pid}-{hex}` name on
 * every start, so a fleet that rotates daily would otherwise fill a 90 day view
 * with thousands of dead rows. The live per-process detail (slot pressure,
 * memory now, pid) still comes from the heartbeat hashes and sits inside its
 * host's card; the ranged columns come from the per-host rollups the master
 * writes every tick.
 *
 * Pool usage and uptime are still returned as `null`: the collector does not
 * publish them, and the UI hides those widgets rather than inventing values.
 */
final class WorkersData
{
    public function __construct(private readonly MetricsPublisher $metrics) {}

    /**
     * @param  string  $range  A {@see Range} key; scopes the counter columns.
     * @return array{hosts: list<array<string, mixed>>}
     */
    public function get(string $range = Range::DEFAULT): array
    {
        $window = Range::make($range);
        $live = $this->liveByHost();

        // The index is scored by last-seen, so a host that stopped reporting
        // longer ago than the range simply is not returned: no per-host read
        // is needed to discover it has nothing to say about this window.
        $seen = $this->metrics->hostsSeen($window->sinceEpoch());
        $names = array_values(array_unique([...array_keys($live), ...array_keys($seen)]));
        sort($names);

        if ($names === []) {
            return ['hosts' => []];
        }

        $counters = $this->metrics->hostSeriesMulti($names, $window->tier, $window->count);
        $gauges = $this->metrics->hostGaugeSeriesMulti([
            MetricsPublisher::GAUGE_HOST_BUSY_SLOTS,
            MetricsPublisher::GAUGE_HOST_TOTAL_SLOTS,
            MetricsPublisher::GAUGE_HOST_WORKER_MEMORY_PEAK,
        ], $names, $window->tier, $window->count);

        $hosts = [];

        foreach ($names as $host) {
            $workers = $live[$host] ?? [];
            $series = $counters[MetricsPublisher::normaliseHost($host)] ?? [];

            $processed = 0;
            $failed = 0;
            $history = [];

            foreach ($series as $outcome) {
                $processed += $outcome['processed'];
                $failed += $outcome['failed'];
                $history[] = $outcome['processed'];
            }

            $finished = $processed + $failed;
            $hostGauges = $gauges[MetricsPublisher::normaliseHost($host)] ?? [];

            $hosts[] = [
                'host' => $host,
                'workers' => $workers,
                // A host in the index with no heartbeat ran in this range but
                // is not running now; it stays in the list so the range is
                // honest about what did the work.
                'status' => $workers === [] ? 'gone' : 'active',
                'lastSeen' => $seen[$host] ?? null,
                'slots' => array_sum(array_column($workers, 'slots')),
                'busy' => array_sum(array_column($workers, 'busy')),
                'stalled' => array_sum(array_column($workers, 'stalled')),
                'memMb' => round(array_sum(array_column($workers, 'memMb')), 2),
                'memPeakMb' => self::gaugePeak($hostGauges[MetricsPublisher::GAUGE_HOST_WORKER_MEMORY_PEAK] ?? []),
                'processed' => $processed,
                'failed' => $failed,
                'failRate' => $finished > 0 ? round($failed / $finished * 100, 2) : 0.0,
                // Jobs per minute across the range, matching the Queues and
                // Jobs screens so the three columns are comparable.
                'rpm' => round($finished / $window->minutes, 1),
                'history' => $history,
                'busyHistory' => self::gaugeAverages($hostGauges[MetricsPublisher::GAUGE_HOST_BUSY_SLOTS] ?? []),
                'slotHistory' => self::gaugeAverages($hostGauges[MetricsPublisher::GAUGE_HOST_TOTAL_SLOTS] ?? []),
                'pools' => null,
                'uptime' => null,
            ];
        }

        // Live hosts first, then the busiest, so an incident is at the top.
        usort($hosts, static fn (array $a, array $b): int => [$b['status'] === 'active', $b['processed']]
            <=> [$a['status'] === 'active', $a['processed']]);

        return ['hosts' => $hosts];
    }

    /**
     * The live worker processes, grouped by the host they run on.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function liveByHost(): array
    {
        $hosts = [];

        foreach ($this->metrics->getAllWorkerMetrics() as $id => $w) {
            // Prefer the published pid/host fields; fall back to parsing the
            // `{host}-{pid}-{hex}` worker id for rows written by older code.
            $parsed = WorkerId::parse((string) $id);
            $host = trim((string) ($w['host'] ?? ''));
            $host = $host !== '' ? $host : $parsed->host;

            if ($host === '') {
                continue;
            }

            $hosts[$host][] = [
                'id' => (string) $id,
                'pid' => isset($w['pid']) ? (int) $w['pid'] : $parsed->pid,
                'slots' => (int) ($w['total_slots'] ?? 0),
                'busy' => (int) ($w['active_slots'] ?? 0),
                'stalled' => 0,
                'memMb' => round(((int) ($w['memory_bytes'] ?? 0)) / 1_048_576, 2),
                // Lifetime counters straight off the heartbeat hash: the host
                // row above is the range-scoped view, this is per process.
                'processed' => (int) ($w['jobs_processed'] ?? 0),
                'failed' => (int) ($w['jobs_failed'] ?? 0),
                'latencyMs' => (float) ($w['avg_latency_ms'] ?? 0),
                'status' => 'active',
            ];
        }

        return $hosts;
    }

    /**
     * @param  array<int, array{avg: float, max: float}>  $series
     * @return list<float>
     */
    private static function gaugeAverages(array $series): array
    {
        return array_map(static fn (array $sample): float => $sample['avg'], array_values($series));
    }

    /**
     * @param  array<int, array{avg: float, max: float}>  $series
     */
    private static function gaugePeak(array $series): ?float
    {
        $peaks = array_column($series, 'max');
        $peak = $peaks === [] ? 0.0 : max($peaks);

        return $peak > 0.0 ? round($peak, 2) : null;
    }
}
