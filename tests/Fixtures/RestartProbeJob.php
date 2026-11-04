<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use function Fledge\Async\delay;

/**
 * Queued job for the queue:restart test: logs its start, suspends for a few
 * seconds so the restart signal arrives mid-flight, then logs its end.
 * See tests/Integration/QueueRestartTest.php.
 */
final class RestartProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public float $seconds = 3.0) {}

    public function handle(): void
    {
        $this->log('start');

        delay($this->seconds);

        $this->log('done');
    }

    private function log(string $event): void
    {
        file_put_contents(
            (string) config('restart_probe.log'),
            json_encode(['event' => $event], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND,
        );
    }
}
