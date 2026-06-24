<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * Per-worker read-model for the workers screen.
 *
 * Fields the collector does not yet publish (pool usage, per-worker rpm, peak
 * memory, uptime) are returned as `null` so the UI hides those widgets rather
 * than rendering fabricated values.
 */
final class WorkersData
{
    public function __construct(private readonly MetricsPublisher $metrics) {}

    /**
     * @return array{workers: list<array<string, mixed>>}
     */
    public function get(): array
    {
        $workers = [];

        foreach ($this->metrics->getAllWorkerMetrics() as $id => $w) {
            [$host, $pid] = self::splitId((string) $id);

            $workers[] = [
                'id' => (string) $id,
                'host' => $host,
                'pid' => $pid,
                'slots' => (int) ($w['total_slots'] ?? 0),
                'busy' => (int) ($w['active_slots'] ?? 0),
                'stalled' => 0,
                'memMb' => round(((int) ($w['memory_bytes'] ?? 0)) / 1_048_576, 2),
                'memPeakMb' => null,
                'processed' => (int) ($w['jobs_processed'] ?? 0),
                'failed' => (int) ($w['jobs_failed'] ?? 0),
                'rpm' => null,
                'latencyMs' => (float) ($w['avg_latency_ms'] ?? 0),
                'uptime' => null,
                'status' => 'active',
                'pools' => null,
                'history' => [],
            ];
        }

        return ['workers' => $workers];
    }

    /**
     * Split a `{host}-{pid}` worker id on the LAST dash (hostnames may contain
     * dashes). Returns a null pid when the tail is not numeric.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function splitId(string $id): array
    {
        $pos = strrpos($id, '-');

        if ($pos === false) {
            return [$id, null];
        }

        $tail = substr($id, $pos + 1);

        return [substr($id, 0, $pos), ctype_digit($tail) ? (int) $tail : null];
    }
}
