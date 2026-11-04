<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Job\DeadLetterHandler;

/**
 * Remove dead-letter entries without retrying them.
 *
 * Touches only the dead-letter stream, never a live queue stream. The matching
 * `failed_jobs` row is removed too, by job uuid, when the failer is the
 * uuid-keyed database provider; the dead-letter id (a stream id) and the
 * failer's own id are unrelated, so the payload uuid is the only reliable
 * link and any other failer is left alone.
 *
 * Usage:
 *   php artisan torque:forget 1700000000000-0
 *   php artisan torque:forget --all [--queue=emails] [--force]
 */
final class TorqueForgetCommand extends Command
{
    protected $signature = 'torque:forget
        {id? : Dead-letter stream id to remove, e.g. 1700000000000-0 (shown by the dashboard and torque:tail; not the uuid or id from queue:failed)}
        {--all : Remove every dead-letter entry (after confirmation)}
        {--queue= : With --all, only entries that came from this queue}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Remove dead-letter entries by id, or all of them with --all';

    public function handle(DeadLetterHandler $deadLetters): int
    {
        $id = $this->argument('id');
        $queue = $this->option('queue');

        if ($id === null && ! $this->option('all')) {
            $this->components->error('Give a dead-letter id, or use --all.');

            return self::FAILURE;
        }

        if ($id !== null && preg_match('/^\d+(-\d+)?$/', (string) $id) !== 1) {
            $this->components->error("[{$id}] is not a dead-letter stream id. torque:forget takes the stream id of a dead-letter entry (like 1700000000000-0), not the uuid or id that queue:failed lists.");

            return self::FAILURE;
        }

        $failedJobs = 0;

        try {
            if ($id !== null) {
                $entry = $deadLetters->forget((string) $id);

                if ($entry === null) {
                    $this->components->error("Dead-letter entry [{$id}] not found.");

                    return self::FAILURE;
                }

                $removed = [$entry];

                if ($entry['uuid'] !== null) {
                    $failedJobs += $this->forgetFailedJob($deadLetters, $entry['uuid']);
                }
            } else {
                $queueFilter = is_string($queue) && $queue !== '' ? $queue : null;
                $scope = $queueFilter !== null ? " for queue [{$queueFilter}]" : '';

                if (! $this->option('force') && ! $this->confirm("Remove all dead-letter entries{$scope}?")) {
                    $this->components->info('Nothing removed.');

                    return self::FAILURE;
                }

                // Rows go page by page, before the page's entries, so an abort
                // mid-walk leaves no failed_jobs row for an entry that is gone.
                $removed = $deadLetters->forgetAll(
                    $queueFilter,
                    function (array $uuids) use ($deadLetters, &$failedJobs): void {
                        foreach ($uuids as $uuid) {
                            $failedJobs += $this->forgetFailedJob($deadLetters, $uuid);
                        }
                    },
                );
            }
        } catch (\Throwable $e) {
            $this->components->error("Cannot reach Redis: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info(sprintf('Removed %d dead-letter %s, %d failed_jobs %s.', count($removed), count($removed) === 1 ? 'entry' : 'entries', $failedJobs, $failedJobs === 1 ? 'record' : 'records'));

        return self::SUCCESS;
    }

    /**
     * @return int 1 when a row was removed, else 0.
     */
    private function forgetFailedJob(DeadLetterHandler $deadLetters, string $uuid): int
    {
        try {
            return $deadLetters->forgetFailedJob($uuid) ? 1 : 0;
        } catch (\Throwable) {
            // A database outage must not undo the dead-letter removal.
            return 0;
        }
    }
}
