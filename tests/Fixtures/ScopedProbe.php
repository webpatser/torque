<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

/**
 * A user's scoped binding. Each instance carries a number of its own, so a job
 * can tell whether the worker reset the container since the last one looked.
 */
final class ScopedProbe
{
    public static int $built = 0;

    public int $number;

    public function __construct()
    {
        $this->number = ++self::$built;
    }
}
