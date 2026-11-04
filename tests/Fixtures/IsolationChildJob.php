<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Dispatched by IsolationProbeJob. Never consumed: the test reads its raw
 * payload from the stream. See tests/Integration/AttemptIsolationTest.php.
 */
final class IsolationChildJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $parent) {}

    public function displayName(): string
    {
        return 'child-of:'.$this->parent;
    }

    public function handle(): void {}
}
