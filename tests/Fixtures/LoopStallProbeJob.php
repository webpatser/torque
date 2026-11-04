<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use function Fledge\Async\delay;

/**
 * Logs each run with its worker's PID. `block` freezes the whole event loop with a
 * real sleep(), `suspend` only parks its own Fiber. Runs after $misbehavingRuns
 * finish at once; the run count comes from the log, so it survives the worker.
 */
final class LoopStallProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $behaviour = 'none',
        public int $seconds = 0,
        public int $misbehavingRuns = PHP_INT_MAX,
    ) {}

    public function handle(): void
    {
        $log = (string) config('loop_stall_probe.log');
        $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $run = count(array_filter($lines, static fn (string $line): bool => str_contains($line, '"type":"run"'))) + 1;

        $write = static function (string $type) use ($log, $run): void {
            file_put_contents($log, json_encode(['type' => $type, 'run' => $run, 'pid' => getmypid()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        };

        $write('run');

        if ($run <= $this->misbehavingRuns) {
            match ($this->behaviour) {
                'block' => self::blockFor($this->seconds),
                'suspend' => delay($this->seconds),
                default => null,
            };
        }

        $write('end');
    }

    /**
     * A signal ends sleep() early, and a blocking query would not give up that
     * easily. Going back to sleep keeps the loop frozen through a SIGTERM.
     */
    private static function blockFor(int $seconds): void
    {
        $until = time() + $seconds;

        while (($left = $until - time()) > 0) {
            sleep($left);
        }
    }
}
