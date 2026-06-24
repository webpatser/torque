<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Dashboard\Http\JobPresenter;
use Webpatser\Torque\Stream\JobStream;

/**
 * Job list (live feed) and single-job event stream (inspector) read-model.
 */
final class JobsData
{
    public function __construct(private readonly JobStream $jobStream) {}

    /**
     * List recent jobs, optionally filtered by status.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $filter = 'all'): array
    {
        $jobs = match ($filter) {
            'active', 'queued' => $this->jobStream->recentJobs('active', 100),
            'completed' => $this->jobStream->recentJobs('completed', 100),
            'failed' => $this->jobStream->recentJobs('failed', 100),
            default => $this->jobStream->recentJobs(null, 100),
        };

        $summaries = array_map(
            static fn (array $job): array => JobPresenter::fromRecent($job),
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
     * Return a single job's summary, full event stream, and payload (if any).
     *
     * @return array{job: array<string, mixed>|null, events: list<array<string, mixed>>, payload: array<string, mixed>|null}
     */
    public function show(string $uuid): array
    {
        $events = $this->jobStream->events($uuid);

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
