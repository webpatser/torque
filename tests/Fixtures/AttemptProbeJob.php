<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Queued job for the real-worker JobAttempted test: each mode ends an attempt
 * in a different way. See tests/Integration/JobAttemptedTest.php.
 */
final class AttemptProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $mode) {}

    public function displayName(): string
    {
        return 'probe:'.$this->mode;
    }

    public function handle(): void
    {
        match ($this->mode) {
            'slow' => usleep(1_100_000),
            'throw' => throw new RuntimeException('probe threw'),
            'fail' => $this->fail(new RuntimeException('probe failed')),
            'release' => $this->attempts() === 1 ? $this->release() : null,
            default => null,
        };
    }
}
