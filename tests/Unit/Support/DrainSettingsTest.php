<?php

declare(strict_types=1);

use Webpatser\Torque\Support\DrainSettings;

/*
 * The one reader of the drain settings. 1.x had a single `drain_grace_seconds`
 * for the worker's window, the master's wait, the release delay and the settle
 * budget; each of those has its own setting now.
 */

it('resolves the package defaults', function () {
    $config = require dirname(__DIR__, 3).'/config/torque.php';

    expect($config['drain_grace_seconds'])->toBeNull()
        ->and(DrainSettings::graceSeconds($config))->toBe(60)
        ->and(DrainSettings::reloadPauseSeconds($config))->toBe(10)
        ->and(DrainSettings::releaseDelaySeconds($config))->toBe(10)
        ->and(DrainSettings::settleSeconds($config))->toBe(5.0)
        ->and(DrainSettings::drainPauseTtlSeconds($config))->toBe(130)
        ->and(DrainSettings::slackSeconds($config))->toBe(15)
        ->and(DrainSettings::drainWorstCaseSeconds($config))->toBe(85);
});

it('resolves the same defaults for a published config without the new keys', function () {
    expect(DrainSettings::graceSeconds([]))->toBe(60)
        ->and(DrainSettings::reloadPauseSeconds([]))->toBe(10)
        ->and(DrainSettings::releaseDelaySeconds([]))->toBe(10)
        ->and(DrainSettings::settleSeconds([]))->toBe(5.0)
        ->and(DrainSettings::drainWorstCaseSeconds([]))->toBe(85);
});

it('derives the grace from the longest stream', function (array $streams, int $expected) {
    expect(DrainSettings::graceSeconds(['streams' => $streams]))->toBe($expected)
        ->and(DrainSettings::derivedGraceSeconds(['streams' => $streams]))->toBe($expected);
})->with([
    'retry_after alone' => [['a' => ['retry_after' => 90]], 90],
    'timeout wins over retry_after' => [['a' => ['timeout' => 30, 'retry_after' => 90]], 30],
    'a timeout of 0 falls back to retry_after' => [['a' => ['timeout' => 0, 'retry_after' => 90]], 90],
    'the longest stream counts' => [['a' => ['timeout' => 30, 'retry_after' => 90], 'b' => ['retry_after' => 45], 'c' => ['timeout' => 120]], 120],
    'neither set is the worker default' => [['a' => []], 60],
    'a retry_after of 0 never derives a grace of 0' => [['a' => ['retry_after' => 0]], 60],
    'no streams' => [[], 60],
    'capped at 300' => [['a' => ['retry_after' => 3600]], 300],
    'a long timeout is capped too' => [['a' => ['timeout' => 7200, 'retry_after' => 7300]], 300],
    'exactly 300' => [['a' => ['retry_after' => 300]], 300],
]);

it('lets an explicit grace win over the streams and the cap', function (mixed $grace, int $expected) {
    $config = ['drain_grace_seconds' => $grace, 'streams' => ['a' => ['retry_after' => 90]]];

    expect(DrainSettings::graceSeconds($config))->toBe($expected)
        ->and(DrainSettings::derivedGraceSeconds($config))->toBe(90);
})->with([
    'below the derived value' => [10, 10],
    'above the cap' => [7200, 7200],
    'zero' => [0, 0],
    'as an env string' => ['45', 45],
    'negative is floored' => [-5, 0],
]);

it('derives the grace when the env value is empty or unset', function (mixed $grace) {
    expect(DrainSettings::graceSeconds(['drain_grace_seconds' => $grace, 'streams' => ['a' => ['retry_after' => 90]]]))->toBe(90);
})->with([
    'null' => [null],
    'empty string' => [''],
]);

it('reads the pause and the release delay on their own, independent of the grace', function () {
    $config = [
        'drain_grace_seconds' => 7200,
        'reload_pause_seconds' => 3,
        'timeouts' => ['release_delay_seconds' => 4],
    ];

    expect(DrainSettings::reloadPauseSeconds($config))->toBe(3)
        ->and(DrainSettings::releaseDelaySeconds($config))->toBe(4)
        ->and(DrainSettings::reloadPauseSeconds(['reload_pause_seconds' => 0]))->toBe(0)
        ->and(DrainSettings::reloadPauseSeconds(['reload_pause_seconds' => -1]))->toBe(0)
        ->and(DrainSettings::releaseDelaySeconds(['timeouts' => ['release_delay_seconds' => 0]]))->toBe(0)
        ->and(DrainSettings::releaseDelaySeconds(['timeouts' => ['release_delay_seconds' => -1]]))->toBe(0);
});

it('caps the settle budget at half the grace', function (int $grace, ?int $settle, float $expected) {
    $config = ['drain_grace_seconds' => $grace];

    if ($settle !== null) {
        $config['timeouts'] = ['settle_seconds' => $settle];
    }

    expect(DrainSettings::settleSeconds($config))->toBe($expected);
})->with([
    'default within the grace' => [60, null, 5.0],
    'default at exactly half' => [10, null, 5.0],
    'default over half' => [6, null, 3.0],
    'half a second at a grace of 1' => [1, null, 0.5],
    'nothing at a grace of 0' => [0, null, 0.0],
    'a raised value within half' => [60, 20, 20.0],
    'a raised value over half' => [60, 45, 30.0],
    'a lowered value' => [60, 1, 1.0],
    'negative is floored' => [60, -1, 0.0],
]);

it('sets the drain pause TTL to pause + grace + 60', function () {
    expect(DrainSettings::drainPauseTtlSeconds(['reload_pause_seconds' => 2, 'drain_grace_seconds' => 3]))->toBe(65)
        ->and(DrainSettings::drainPauseTtlSeconds(['reload_pause_seconds' => 0, 'drain_grace_seconds' => 0]))->toBe(60);
});

it('keeps the 15 second drain slack without the key, and floors it at 1', function () {
    expect(DrainSettings::slackSeconds([]))->toBe(15)
        ->and(DrainSettings::slackSeconds(['intervals' => ['drain_slack_seconds' => 3]]))->toBe(3)
        ->and(DrainSettings::slackSeconds(['intervals' => ['drain_slack_seconds' => 0]]))->toBe(1);
});

/*
 * The single number the three shutdown commands derive their deadline from. A
 * drain runs on two clocks: the master waits up to the pause for an idle fleet,
 * then every worker gets the grace for the job it still holds. Anything shorter
 * than the sum gives up on a fleet that is draining normally.
 */

it('makes the worst case pause + grace + slack', function () {
    expect(DrainSettings::drainWorstCaseSeconds([
        'reload_pause_seconds' => 7,
        'drain_grace_seconds' => 100,
        'intervals' => ['drain_slack_seconds' => 3],
    ]))->toBe(110);
});

it('keeps parity with the historic 30s+5 default at a pinned grace of 10', function () {
    // The old hard-coded `--timeout=30` plus its `+ 5` slack landed on 35s, so an
    // installation pinning the 1.x grace sees no behaviour change.
    expect(DrainSettings::drainWorstCaseSeconds(['drain_grace_seconds' => 10]))->toBe(35);
});

it('scales with a grace sized for long jobs', function () {
    expect(DrainSettings::drainWorstCaseSeconds(['drain_grace_seconds' => 7200]))->toBe(7225);
});

it('takes the derived grace into the worst case', function () {
    expect(DrainSettings::drainWorstCaseSeconds(['streams' => ['a' => ['timeout' => 7200]]]))->toBe(325);
});

it('floors a zero or negative grace and pause at the slack alone', function () {
    expect(DrainSettings::drainWorstCaseSeconds(['reload_pause_seconds' => 0, 'drain_grace_seconds' => 0]))->toBe(15)
        ->and(DrainSettings::drainWorstCaseSeconds(['reload_pause_seconds' => -5, 'drain_grace_seconds' => -100]))->toBe(15);
});
