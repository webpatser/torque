<?php

declare(strict_types=1);

/*
 * A tenth of the production timer periods, for the worker fixtures. A test that
 * waits out ticks to prove something does not repeat scales its wait with these:
 * it must span at least as many ticks as at the production periods.
 *
 * job-timeouts-worker.php stays on the production periods: its tests size a settle
 * against a drain grace of 0 to 2 seconds, and `drain_grace_seconds` counts whole
 * seconds, so a fast stop check can end such a drain almost a second early.
 * loop-stall-app.php does too: those tests wait out real kill limits, not ticks, and
 * some of them hang Redis, where a fast pause check would pile up on the pool.
 */

return [
    'stop_check_seconds' => 0.1,
    'down_check_seconds' => 0.1,
    'paused_poll_seconds' => 0.1,
    'delayed_check_seconds' => 0.1,
    'pause_check_seconds' => 0.2,
    'watchdog_check_seconds' => 0.2,
];
