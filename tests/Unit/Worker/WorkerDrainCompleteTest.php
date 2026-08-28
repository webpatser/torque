<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

/**
 * Once limits are reached the reader Fibers stop polling, so a worker whose
 * slots are all idle has nothing left to drain and must exit immediately
 * instead of sleeping out drain_grace_seconds (scrpr 2026-08-28: every
 * rotation, and every deploy behind it, waited the full 7200 s window).
 */
it('reports the drain complete when no slot is busy', function () {
    expect(WorkerProcess::drainComplete([]))->toBeTrue();
});

it('keeps draining while any slot still processes a job', function () {
    expect(WorkerProcess::drainComplete([2 => time() - 5]))->toBeFalse()
        ->and(WorkerProcess::drainComplete([0 => time(), 3 => time() - 900]))->toBeFalse();
});
