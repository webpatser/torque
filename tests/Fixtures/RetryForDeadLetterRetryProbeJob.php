<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Queue\Attributes\RetryFor;

/**
 * JobLimitsProbeJob with a `#[RetryFor]` window and no retryUntil() method,
 * the case stock's retry command recomputes from the attribute (13.43+).
 */
#[RetryFor(minutes: 5)]
class RetryForDeadLetterRetryProbeJob extends JobLimitsProbeJob {}
