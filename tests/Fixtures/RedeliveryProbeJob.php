<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use function Fledge\Async\delay;

/**
 * Logs each run and then misbehaves for its first $misbehavingRuns runs. The run
 * count comes from the log, so it survives the worker: `kill` sends the worker a
 * real SIGKILL from inside the job.
 */
final class RedeliveryProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public ?int $tries;

    public function __construct(
        public string $behaviour = 'none',
        public int $misbehavingRuns = PHP_INT_MAX,
        ?int $tries = null,
    ) {
        $this->tries = $tries;
    }

    public function handle(): void
    {
        $log = (string) config('steal_probe.log');
        $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $run = count(array_filter($lines, static fn (string $line): bool => str_contains($line, '"type":"run"'))) + 1;

        file_put_contents($log, json_encode(['type' => 'run', 'run' => $run, 'attempts' => $this->attempts()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        if ($run > $this->misbehavingRuns) {
            return;
        }

        match ($this->behaviour) {
            'kill' => posix_kill(getmypid(), SIGKILL),
            'hang' => delay(30),
            'slow' => delay(2.5),
            'release' => $this->release(),
            'throw' => throw new \RuntimeException('probe failure'),
            default => null,
        };
    }
}
