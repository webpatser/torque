<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Webpatser\Torque\Support\Narrow;

/**
 * Read-model for the batches screen, built on Laravel's own BatchRepository.
 *
 * Read-only, and never throws at the screen. A missing `job_batches` table is a
 * setup state the screen explains. Anything else (an outage, a misconfigured
 * driver, a corrupt row) is reported and shown as a generic error, so it cannot
 * pass for "not set up" or "not found".
 */
final class BatchesData
{
    /** SQLSTATE for a missing table: MySQL, MariaDB and SQL Server, then PostgreSQL. */
    private const array MISSING_TABLE_STATES = ['42S02', '42P01'];

    private const string REPORTED_KEY = 'torque:batches:reported';

    private const int REPORT_EVERY_SECONDS = 60;

    /**
     * One page of batches, newest first.
     *
     * The repository orders by its (time-ordered) id descending, so the cursor
     * is simply the id of the last batch on the page.
     *
     * @return array{available: bool, error: bool, batches: list<array<string, mixed>>, next: string|null}
     */
    public function list(?string $before = null, int $limit = 20): array
    {
        try {
            // One extra row tells us whether another page exists.
            $rows = array_values(app(BatchRepository::class)->get($limit + 1, $before));
        } catch (Throwable $e) {
            $missing = $this->isMissingTable($e);

            if (! $missing) {
                $this->reportThrottled($e);
            }

            return ['available' => false, 'error' => ! $missing, 'batches' => [], 'next' => null];
        }

        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        return [
            'available' => true,
            'error' => false,
            'batches' => array_map($this->present(...), $page),
            'next' => $hasMore && $page !== [] ? (string) end($page)->id : null,
        ];
    }

    /**
     * One batch, null when it does not exist, or `['unavailable' => true]` when
     * reading it failed.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        try {
            $batch = app(BatchRepository::class)->find($id);
        } catch (Throwable $e) {
            if ($this->isMissingTable($e)) {
                return null;
            }

            $this->reportThrottled($e);

            return ['unavailable' => true];
        }

        return $batch instanceof Batch ? $this->present($batch) : null;
    }

    /**
     * The screen polls about once a second per tab, so an outage reports once a
     * minute. A cache that is down too must not hide the outage: it reports anyway.
     */
    private function reportThrottled(Throwable $e): void
    {
        try {
            if (! Cache::add(self::REPORTED_KEY, 1, self::REPORT_EVERY_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            // Fall through to the report.
        }

        report($e);
    }

    /**
     * SQLite has no SQLSTATE for this (always HY000), so it is matched on its
     * message.
     */
    private function isMissingTable(Throwable $e): bool
    {
        return $e instanceof QueryException
            && (in_array((string) $e->getCode(), self::MISSING_TABLE_STATES, true)
                || str_contains($e->getMessage(), 'no such table'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Batch $batch): array
    {
        return [
            'id' => (string) $batch->id,
            'name' => (string) $batch->name,
            'progress' => (int) $batch->progress(),
            'pending' => (int) $batch->pendingJobs,
            'failed' => (int) $batch->failedJobs,
            'total' => (int) $batch->totalJobs,
            'failedJobIds' => array_values(array_map(static fn (mixed $id): string => Narrow::string($id), $batch->failedJobIds)),
            'createdAt' => $batch->createdAt->getTimestamp(),
            'finishedAt' => $batch->finishedAt?->getTimestamp(),
            'cancelled' => $batch->cancelled(),
        ];
    }
}
