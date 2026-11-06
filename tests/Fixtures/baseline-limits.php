<?php

declare(strict_types=1);

/*
 * The 1.x defaults of the settings whose package defaults change in 2.0, for the
 * worker and app fixtures. Merged between config('torque') and the fixture's own
 * values, so a fixture that deliberately tests strict attempts, timeouts, a memory
 * limit or the stall watchdog sets them itself and wins, and every other fixture
 * keeps running on the behaviour its tests were written against.
 */

return [
    'strict_attempts' => false,
    'timeouts' => ['enabled' => false],
    'memory_limit' => 0,
    'loop_stall_kill_seconds' => 0,
    'drain_grace_seconds' => 10,
];
