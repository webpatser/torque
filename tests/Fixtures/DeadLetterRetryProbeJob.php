<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use DateTimeInterface;

/**
 * JobLimitsProbeJob with a retryUntil() method, the one stock's retry command
 * refreshes. Always an hour ahead of now.
 */
class DeadLetterRetryProbeJob extends JobLimitsProbeJob
{
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }
}
