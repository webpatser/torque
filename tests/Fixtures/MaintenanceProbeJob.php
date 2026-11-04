<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use function Fledge\Async\delay;

/**
 * Queued job for the memory limit and maintenance mode tests: logs its start,
 * optionally suspends, optionally holds on to memory for the life of the process,
 * then logs its end. See tests/Integration/WorkerMaintenanceTest.php.
 */
final class MaintenanceProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** @var list<string> */
    private static array $retained = [];

    public function __construct(public float $seconds = 0.0, public int $retainMegabytes = 0, public float $holdSeconds = 0.0) {}

    public function handle(): void
    {
        $this->log('start');

        if ($this->seconds > 0) {
            delay($this->seconds);
        }

        if ($this->retainMegabytes > 0) {
            self::$retained[] = str_repeat('x', $this->retainMegabytes * 1024 * 1024);
        }

        if ($this->holdSeconds > 0) {
            delay($this->holdSeconds);
        }

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
