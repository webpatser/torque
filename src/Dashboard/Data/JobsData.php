<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Dashboard\Http\JobPresenter;
use Webpatser\Torque\Stream\JobStream;
use Webpatser\Torque\Support\PayloadSanitizer;

/**
 * Job list (live feed) and single-job event stream (inspector) read-model.
 */
final class JobsData
{
    public function __construct(private readonly JobStream $jobStream) {}

    /**
     * List recent jobs, optionally filtered by status and by a time window.
     *
     * @param  int|null  $since  Unix timestamp; null lists the whole tail.
     * @return list<array<string, mixed>>
     */
    public function list(string $filter = 'all', ?int $since = null, ?string $tag = null): array
    {
        $jobs = match ($filter) {
            'active', 'queued' => $this->jobStream->recentJobs('active', 100, $since),
            'completed' => $this->jobStream->recentJobs('completed', 100, $since),
            'failed' => $this->jobStream->recentJobs('failed', 100, $since),
            default => $this->jobStream->recentJobs(null, 100, $since),
        };

        if ($tag !== null && $tag !== '') {
            $jobs = array_values(array_filter(
                $jobs,
                static fn (array $job): bool => in_array($tag, self::tagsOf($job), true),
            ));
        }

        $summaries = array_map(
            static fn (array $job): array => JobPresenter::fromRecent($job) + ['tags' => self::tagsOf($job)],
            $jobs,
        );

        if ($filter === 'queued') {
            $summaries = array_values(array_filter(
                $summaries,
                static fn (array $job): bool => $job['status'] === 'queued',
            ));
        }

        return $summaries;
    }

    /**
     * Tags of a listed job: every event of an attempt carries them, so its last
     * event has them, or its first when something else wrote the last.
     *
     * @param  array<string, mixed>  $job
     * @return list<string>
     */
    private static function tagsOf(array $job): array
    {
        foreach (['last_event', 'first_event'] as $which) {
            $raw = $job[$which]['data']['tags'] ?? null;

            if (is_string($raw) && is_array($tags = json_decode($raw, true))) {
                return array_values(array_filter($tags, 'is_string'));
            }
        }

        return [];
    }

    /**
     * Return a single job's summary, full event stream, and payload (if any).
     *
     * @return array{job: array<string, mixed>|null, events: list<array<string, mixed>>, payload: array<string, mixed>|null}
     */
    public function show(string $uuid): array
    {
        // Event data carries exception messages and traces, which can hold
        // credentials; redact before anything reaches a view.
        $events = array_values(PayloadSanitizer::sanitizeEvents($this->jobStream->events($uuid)));

        if ($events === []) {
            return ['job' => null, 'events' => [], 'payload' => null];
        }

        $withTs = array_map(static function (array $event): array {
            $event['ts'] = JobPresenter::timestamp($event['id']);

            return $event;
        }, $events);

        $job = JobPresenter::summary($uuid, $events[0], $events[count($events) - 1]);
        $job['connection'] = 'torque';

        return [
            'job' => $job,
            'events' => $withTs,
            // The per-job event stream records lifecycle events only; the raw
            // dispatch payload is not retained, so there is nothing to inspect.
            'payload' => null,
        ];
    }
}
