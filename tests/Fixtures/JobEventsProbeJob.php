<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use RuntimeException;
use Throwable;

use function Fledge\Async\delay;

/**
 * Queued job for the job event tests: logs its run to the worker's event log,
 * including the Context it sees, then ends the way its mode asks for. The label
 * is the display name, so the worker's listeners can tell jobs apart.
 * See tests/Integration/WorkerEventsTest.php.
 */
final class JobEventsProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  'ok'|'throw'|'release'|'release-throw'|'release-caught'  $mode
     */
    public function __construct(
        public string $label,
        public string $mode = 'ok',
        public float $seconds = 0.0,
    ) {}

    public function displayName(): string
    {
        return $this->label;
    }

    public function handle(): void
    {
        $this->log('JobHandle');

        if ($this->seconds > 0) {
            delay($this->seconds);
        }

        match ($this->mode) {
            'throw' => throw new RuntimeException('probe failed'),
            'release' => $this->release(30),
            'release-throw' => $this->releaseThenThrow(),
            'release-caught' => $this->releaseCatchingFailure(),
            default => null,
        };
    }

    private function releaseThenThrow(): void
    {
        $this->release(30);

        throw new RuntimeException('probe failed');
    }

    private function releaseCatchingFailure(): void
    {
        try {
            $this->release(30);
        } catch (Throwable) {
            $this->log('JobReleaseFailed');
        }
    }

    private function log(string $event): void
    {
        file_put_contents(
            (string) config('restart_probe.log'),
            json_encode([
                'event' => $event,
                'tag' => $this->label,
                'attempt' => $this->attempts(),
                'fiber' => spl_object_id(\Fiber::getCurrent()),
                'context' => Context::get('popped'),
            ], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND,
        );
    }
}
