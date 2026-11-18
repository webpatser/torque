<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Support\Narrow;

/**
 * Prune Torque's Redis state, Horizon `horizon:snapshot`/`queue:prune` style.
 *
 *  - dead-letter stream: drop entries older than dead_letter.ttl (XTRIM MINID)
 *    and cap the total length (XTRIM MAXLEN ~) so a failure storm can never
 *    fill Redis again (scrpr 2026-08-27: 3.9M entries, 23 GB, OOM loop)
 *  - consumer groups: XGROUP DELCONSUMER every consumer that has no pending
 *    messages and has been idle longer than --consumer-idle (dead worker
 *    names otherwise accumulate forever; 124k per stream were found)
 *
 * With `--deep` it also runs the upgrade sweep (orphaned per-job event
 * streams, stale index members, stale worker hashes) that the master performs
 * once per version on start. Combine it with `--dry-run` to preview a deploy.
 *
 * The master runs the same {@see StreamHousekeeper} every
 * `dead_letter.prune_interval` seconds, so scheduling this command is
 * optional belt and braces:
 * Schedule::command('torque:prune')->hourly();
 */
final class TorquePruneCommand extends Command
{
    protected $signature = 'torque:prune
        {--dead-letter-max= : Hard cap on dead-letter entries (default torque.dead_letter.max_entries; 0 adds no cap beyond it)}
        {--consumer-idle= : Delete idle consumers without pending work after this many seconds (default 3600)}
        {--deep : Also sweep leftovers from older Torque versions (orphaned job streams, stale indexes, stale worker hashes)}
        {--dry-run : Report what would be removed without touching Redis}';

    protected $description = 'Trim the dead-letter stream and remove stale stream consumers';

    public function handle(DeadLetterHandler $deadLetters): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deep = (bool) $this->option('deep');

        // The same hour the master uses, with or without --deep: a live worker
        // idle between polls has nothing pending either, so a lower default
        // would sweep consumers of running workers.
        $consumerIdle = 3600;

        if ($this->option('consumer-idle') !== null) {
            $consumerIdle = filter_var($this->option('consumer-idle'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($consumerIdle === false) {
                $this->components->error('The --consumer-idle option must be a whole number of seconds, 0 or more.');

                return self::FAILURE;
            }
        }

        // Without the option the configured cap applies, as in the master's own
        // pass; a fixed default here would override dead_letter.max_entries.
        $housekeeper = StreamHousekeeper::fromConfig(
            Narrow::map(config('torque')),
            $deadLetters,
            maxEntries: $this->option('dead-letter-max') !== null ? (int) $this->option('dead-letter-max') : null,
        );

        if ($deep) {
            return $this->runDeepClean($housekeeper, $dryRun, $consumerIdle);
        }

        try {
            $deadLetter = $housekeeper->pruneDeadLetter($dryRun);
        } catch (\Throwable $e) {
            $this->components->error("Cannot connect to Redis: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail(
            'Dead-letter entries',
            number_format($deadLetter['before']).' -> '.number_format($deadLetter['after']),
        );

        $perQueue = $housekeeper->pruneConsumers($consumerIdle, $dryRun);

        foreach ($perQueue as $queue => $stale) {
            $this->components->twoColumnDetail("Stale consumers [{$queue}]", (string) $stale);
        }

        $removed = array_sum($perQueue);

        $this->components->info(($dryRun ? 'Would remove ' : 'Removed ').number_format($removed).' stale consumers'.($dryRun ? ' (dry run)' : '').'.');

        return self::SUCCESS;
    }

    /**
     * Report the full upgrade sweep, one line per category.
     *
     * Same work the master does once per version on start, available on demand
     * so an operator can preview it with --dry-run before a deploy.
     */
    private function runDeepClean(StreamHousekeeper $housekeeper, bool $dryRun, int $consumerIdle): int
    {
        try {
            $counts = $housekeeper->deepClean($dryRun, $consumerIdle);
        } catch (\Throwable $e) {
            $this->components->error("Cannot connect to Redis: {$e->getMessage()}");

            return self::FAILURE;
        }

        $labels = [
            'job_streams' => 'Orphaned job streams',
            'index_members' => 'Stale index members',
            'dead_letter' => 'Dead-letter entries',
            'consumers' => 'Stale consumers',
            'worker_hashes' => 'Stale worker hashes',
        ];

        foreach ($counts as $category => $count) {
            $this->components->twoColumnDetail(
                $labels[$category] ?? ucfirst(str_replace('_', ' ', (string) $category)),
                number_format($count),
            );
        }

        $total = array_sum($counts);

        $this->components->info(($dryRun ? 'Would remove ' : 'Removed ').number_format($total).' leftover keys'.($dryRun ? ' (dry run)' : '').'.');

        return self::SUCCESS;
    }
}
