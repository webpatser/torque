<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;

/*
 * `MasterProcess::drainWorstCaseSeconds()` is the single number the three
 * shutdown commands derive their deadline from. A drain runs on two clocks:
 * the master waits up to `drain_grace_seconds` for an idle fleet, then every
 * worker gets that window again for the job it still holds. Anything shorter
 * than the sum gives up on a fleet that is draining normally.
 */

it('covers both halves of a drain plus slack', function () {
    expect(MasterProcess::drainWorstCaseSeconds(10))->toBe(35);
});

it('keeps parity with the historic 30s+5 default at the default grace', function () {
    // The old hard-coded `--timeout=30` plus its `+ 5` slack landed on 35s,
    // so installations that never raised the grace see no behaviour change.
    expect(MasterProcess::drainWorstCaseSeconds(10))->toBe(30 + 5);
});

it('scales with a grace sized for long jobs', function () {
    expect(MasterProcess::drainWorstCaseSeconds(7200))->toBe(14415);
});

it('floors a zero or negative grace at the slack alone', function () {
    expect(MasterProcess::drainWorstCaseSeconds(0))->toBe(15)
        ->and(MasterProcess::drainWorstCaseSeconds(-100))->toBe(15);
});
