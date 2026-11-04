<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

use function Fledge\Async\delay;

/**
 * Queued job for the real-worker steal tests: logs when its handler starts and
 * ends, so a test can count the runs and see which ones overlapped. Suspends
 * instead of sleeping, so the worker's other slots keep polling meanwhile. See
 * tests/Integration/WorkerStealTest.php.
 */
final class StealProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $name,
        public float $seconds = 0.0,
        public bool $throws = false,
        public bool $releaseOnce = false,
    ) {}

    public function displayName(): string
    {
        return 'steal:'.$this->name;
    }

    public function handle(): void
    {
        $this->log('start');

        if ($this->seconds > 0) {
            delay($this->seconds);
        }

        $this->log('end');

        if ($this->releaseOnce && $this->attempts() <= 1) {
            $this->release();

            return;
        }

        if ($this->throws) {
            throw new RuntimeException('steal probe threw');
        }
    }

    private function log(string $type): void
    {
        file_put_contents(
            (string) config('steal_probe.log'),
            json_encode(['type' => $type, 'job' => $this->name], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND,
        );
    }
}
