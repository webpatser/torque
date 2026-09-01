<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;

/**
 * The per-host rollup folds per-worker deltas, not a per-host total.
 *
 * A host runs many workers and each one resets its counters when it rotates
 * (`max_jobs_per_worker` / `max_worker_lifetime`, so daily at the defaults).
 * Diffing a host sum would go negative on every rotation, and the clamp that
 * keeps a negative out of a two-year history would then hide that host's jobs
 * until the sum climbed back past its old value.
 */
it('folds per-worker deltas into per-host deltas', function () {
    $previous = [
        'web-01-100-aaaaaaaa' => [10, 0],
        'web-01-101-bbbbbbbb' => [20, 1],
        'web-02-200-cccccccc' => [5, 0],
    ];

    $workers = [
        'web-01-100-aaaaaaaa' => ['host' => 'web-01', 'jobs_processed' => 14, 'jobs_failed' => 0],
        'web-01-101-bbbbbbbb' => ['host' => 'web-01', 'jobs_processed' => 26, 'jobs_failed' => 3],
        'web-02-200-cccccccc' => ['host' => 'web-02', 'jobs_processed' => 5, 'jobs_failed' => 0],
    ];

    ['deltas' => $deltas] = MasterProcess::hostDeltas($workers, $previous);

    // 4 + 6 processed and 0 + 2 failed on web-01; web-02 did nothing this tick
    // and is left out rather than written as a zero.
    expect($deltas)->toBe(['web-01' => [10, 2]]);
});

it('contributes nothing for a worker id seen for the first time', function () {
    // A master taking over a running fleet must not dump every worker's
    // lifetime counter into the bucket its first tick lands in.
    $workers = [
        'web-01-100-aaaaaaaa' => ['host' => 'web-01', 'jobs_processed' => 18_234, 'jobs_failed' => 12],
    ];

    ['deltas' => $deltas, 'counters' => $counters] = MasterProcess::hostDeltas($workers, []);

    expect($deltas)->toBe([])
        ->and($counters)->toBe(['web-01-100-aaaaaaaa' => [18_234, 12]]);
});

it('survives one worker on a host restarting', function () {
    $previous = [
        'web-01-100-aaaaaaaa' => [100, 0],
        'web-01-101-bbbbbbbb' => [50, 0],
    ];

    // The first worker rotated and came back with its counters at zero.
    $workers = [
        'web-01-100-aaaaaaaa' => ['host' => 'web-01', 'jobs_processed' => 0, 'jobs_failed' => 0],
        'web-01-101-bbbbbbbb' => ['host' => 'web-01', 'jobs_processed' => 60, 'jobs_failed' => 0],
    ];

    ['deltas' => $deltas] = MasterProcess::hostDeltas($workers, $previous);

    // The rotated worker clamps to zero on its own; its neighbour's 10 jobs
    // still land. A host-level diff would have produced -90, clamped to 0.
    expect($deltas)->toBe(['web-01' => [10, 0]]);
});

it('forgets the ids of workers that have exited', function () {
    $previous = [
        'web-01-100-aaaaaaaa' => [10, 0],
        'web-01-999-dddddddd' => [10, 0],
    ];

    $workers = [
        'web-01-100-aaaaaaaa' => ['host' => 'web-01', 'jobs_processed' => 11, 'jobs_failed' => 0],
    ];

    ['counters' => $counters] = MasterProcess::hostDeltas($workers, $previous);

    // Rebuilt from this tick's ids, so the map is bounded by the live fleet
    // rather than by every id minted this year.
    expect(array_keys($counters))->toBe(['web-01-100-aaaaaaaa']);
});

it('falls back to the host half of the worker id when the field is missing', function () {
    $previous = ['my-host-100-aaaaaaaa' => [1, 0]];
    $workers = ['my-host-100-aaaaaaaa' => ['jobs_processed' => 4, 'jobs_failed' => 0]];

    ['deltas' => $deltas] = MasterProcess::hostDeltas($workers, $previous);

    expect($deltas)->toBe(['my-host' => [3, 0]]);
});
