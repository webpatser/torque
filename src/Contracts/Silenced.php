<?php

declare(strict_types=1);

namespace Webpatser\Torque\Contracts;

/**
 * Marker for queued jobs that should stay out of the dashboard's completed
 * job records when they succeed.
 *
 * Failures are always recorded. A job can also be silenced without touching its
 * class, through the `silenced` (class names) and `silenced_tags` config keys.
 */
interface Silenced {}
