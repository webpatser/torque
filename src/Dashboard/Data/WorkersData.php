<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Support\WorkerId;

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
            // Prefer the published pid/host fields; fall back to parsing the
            // `{host}-{pid}-{hex}` worker id for rows written by older code.
            $parsed = WorkerId::parse((string) $id);
            $pid = isset($w['pid']) ? (int) $w['pid'] : $parsed->pid;
            $host = $w['host'] ?? $parsed->host;

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
}
