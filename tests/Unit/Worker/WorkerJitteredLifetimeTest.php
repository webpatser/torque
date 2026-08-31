<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

/**
 * The master forks its whole fleet inside one second, so an unjittered
 * max_worker_lifetime expires on every worker in the same second and the
 * entire fleet drains at once (scrpr 2026-08-31: 16 workers started 18:23:17,
 * all 16 hit their 24 h lifetime at 18:23:17 the next day). Jitter turns that
 * into a rolling rotation.
 *
 * It may only ever subtract: MasterProcess prunes stream consumers idle for
 * longer than a full worker lifetime, and that threshold assumes no worker
 * outlives the configured value.
 */
it('returns the base lifetime when jitter is disabled', function () {
    expect(WorkerProcess::jitteredLifetime(3600, 0.0))->toBe(3600)
        ->and(WorkerProcess::jitteredLifetime(3600, -1.0))->toBe(3600);
});

it('never returns more than the configured lifetime', function () {
    foreach (range(1, 200) as $ignored) {
        expect(WorkerProcess::jitteredLifetime(3600, 0.1))->toBeLessThanOrEqual(3600);
    }
});

it('stays within the jitter band', function () {
    foreach (range(1, 200) as $ignored) {
        expect(WorkerProcess::jitteredLifetime(86_400, 0.1))
            ->toBeGreaterThanOrEqual(77_760)
            ->toBeLessThanOrEqual(86_400);
    }
});

it('actually spreads the deadlines apart', function () {
    $values = [];

    foreach (range(1, 50) as $ignored) {
        $values[] = WorkerProcess::jitteredLifetime(86_400, 0.1);
    }

    // A fleet whose workers all land on the same second is the bug this fixes.
    expect(count(array_unique($values)))->toBeGreaterThan(1);
});

it('never returns a lifetime below one second', function () {
    foreach (range(1, 100) as $ignored) {
        expect(WorkerProcess::jitteredLifetime(10, 1.0))->toBeGreaterThanOrEqual(1)
            ->and(WorkerProcess::jitteredLifetime(10, 5.0))->toBeGreaterThanOrEqual(1);
    }
});

it('leaves a degenerate lifetime alone', function () {
    expect(WorkerProcess::jitteredLifetime(1, 0.5))->toBe(1)
        ->and(WorkerProcess::jitteredLifetime(0, 0.5))->toBe(0);
});
