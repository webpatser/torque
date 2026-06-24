<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Http;

/**
 * Maps raw per-job stream events into the dashboard `jobSummary` shape.
 *
 * This is the single source of truth for the event -> summary transform used by
 * both the overview (`live`) and jobs endpoints, so the contract stays in one
 * place. Wall-clock timestamps are derived from the Redis entry id, never from
 * `data.timestamp` (which is `hrtime(true)` nanoseconds, not epoch).
 */
final class JobPresenter
{
    /**
     * Stream event types that map to the `failed` job status.
     *
     * @var list<string>
     */
    private const FAILED_TYPES = ['failed', 'dead_lettered'];

    /**
     * Fallback max attempts when a job's stream does not record one.
     *
     * Matches the `streams.default.max_retries` config default.
     */
    private const MAX_TRIES_DEFAULT = 3;

    /**
     * Build a `jobSummary` from the `recentJobs()` shape (uuid + first/last events).
     *
     * @param  array{uuid: string, first_event: array{id: string, type: string, data: array<string, string>}, last_event: array{id: string, type: string, data: array<string, string>}}  $job
     * @return array<string, mixed>
     */
    public static function fromRecent(array $job): array
    {
        return self::summary($job['uuid'], $job['first_event'], $job['last_event']);
    }

    /**
     * Build a `jobSummary` from a uuid plus its first and last events.
     *
     * @param  array{id: string, type: string, data: array<string, string>}  $first
     * @param  array{id: string, type: string, data: array<string, string>}  $last
     * @return array<string, mixed>
     */
    public static function summary(string $uuid, array $first, array $last): array
    {
        $firstData = $first['data'];
        $lastData = $last['data'];

        $displayName = $firstData['displayName'] ?? $lastData['displayName'] ?? '';
        ['ns' => $ns, 'cls' => $cls, 'name' => $name] = self::splitName($displayName);

        $firstTs = self::timestamp($first['id']);
        $lastTs = self::timestamp($last['id']);

        $worker = $lastData['worker'] ?? $firstData['worker'] ?? null;
        $progress = isset($lastData['progress']) ? (float) $lastData['progress'] : null;

        return [
            'id' => $uuid,
            'ns' => $ns,
            'cls' => $cls,
            'name' => $name,
            'queue' => $firstData['queue'] ?? $lastData['queue'] ?? '',
            'status' => self::status($last['type']),
            'attempt' => (int) ($lastData['attempt'] ?? $firstData['attempt'] ?? 1),
            'maxTries' => self::MAX_TRIES_DEFAULT,
            'worker' => $worker !== null && $worker !== '' ? $worker : null,
            'progress' => $progress,
            'runtime' => $lastTs > $firstTs ? round(($lastTs - $firstTs) / 1000, 2) : null,
            'ts' => $lastTs,
        ];
    }

    /**
     * Derive a wall-clock millisecond epoch from a Redis stream entry id.
     *
     * Entry ids are formatted `{ms}-{sequence}`; the millisecond portion is the
     * real wall-clock time, unlike `data.timestamp` (hrtime nanoseconds).
     */
    public static function timestamp(string $entryId): int
    {
        return (int) explode('-', $entryId)[0];
    }

    /**
     * Map a job's last event type to its dashboard status.
     */
    public static function status(string $lastType): string
    {
        return match ($lastType) {
            'started', 'progress' => 'running',
            'queued' => 'queued',
            'completed' => 'completed',
            'exception' => 'exception',
            default => in_array($lastType, self::FAILED_TYPES, true) ? 'failed' : $lastType,
        };
    }

    /**
     * Split a fully-qualified job class into namespace, class, and full name so
     * the frontend never has to handle backslashes.
     *
     * @return array{ns: string, cls: string, name: string}
     */
    public static function splitName(string $displayName): array
    {
        if ($displayName === '') {
            return ['ns' => '', 'cls' => '(unknown)', 'name' => ''];
        }

        $pos = strrpos($displayName, '\\');

        if ($pos === false) {
            return ['ns' => '', 'cls' => $displayName, 'name' => $displayName];
        }

        return [
            'ns' => substr($displayName, 0, $pos + 1),
            'cls' => substr($displayName, $pos + 1),
            'name' => $displayName,
        ];
    }
}
