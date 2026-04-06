<?php

declare(strict_types=1);

namespace Webpatser\Torque\Manager;

/**
 * Represents the outcome of an autoscaling evaluation.
 */
enum ScaleDecision: string
{
    case ScaleUp = 'scale_up';
    case ScaleDown = 'scale_down';
    case NoChange = 'no_change';
}
