<?php

declare(strict_types=1);

use Webpatser\Torque\Process\DrainPause;

/*
 * `DrainPause::isStale()` decides whether a `{prefix}paused` key is a drain
 * left behind by a master that is gone. The rule, in order:
 *
 *   - not a `drain:<pid>` value  -> keep (a manual `torque:pause` is an
 *                                   operator decision, never cleared for them)
 *   - our own PID                -> stale (a master writes its drain key only
 *                                   while draining, so this is a recycled PID)
 *   - the PID is not a live master -> stale
 *   - otherwise                  -> keep, the drain still has an owner
 */

$dead = fn (int $pid): bool => false;
$alive = fn (int $pid): bool => true;

it('treats a drain key whose master is gone as stale', function () use ($dead) {
    expect(DrainPause::isStale('drain:4242', 99, $dead))->toBeTrue();
});

it('keeps a drain key while its master is still running', function () use ($alive) {
    expect(DrainPause::isStale('drain:4242', 99, $alive))->toBeFalse();
});

it('treats our own PID as stale even when the liveness probe says alive', function () use ($alive) {
    // We are alive by definition; a drain key carrying our PID at start can
    // only come from a previous process whose number was recycled.
    expect(DrainPause::isStale('drain:4242', 4242, $alive))->toBeTrue();
});

it('never clears a deliberate pause, whatever the probe says', function (string $value) use ($dead) {
    expect(DrainPause::isStale($value, 99, $dead))->toBeFalse();
})->with([
    'timestamp written by torque:pause' => '1756400000',
    'legacy drain timestamp' => '1756400000.5',
    'empty value' => '',
]);

it('keeps a malformed drain value rather than guessing', function (?string $value) use ($dead) {
    expect(DrainPause::isStale($value, 99, $dead))->toBeFalse();
})->with([
    'missing key' => null,
    'no pid' => 'drain:',
    'not a number' => 'drain:abc',
    'negative' => 'drain:-1',
    'zero' => 'drain:0',
    'trailing junk' => 'drain:123abc',
]);

it('never asks the probe about a value it will not clear', function () {
    $asked = [];

    DrainPause::isStale('1756400000', 99, function (int $pid) use (&$asked): bool {
        $asked[] = $pid;

        return false;
    });

    expect($asked)->toBe([]);
});

it('reads the owning master PID out of a drain value', function () {
    expect(DrainPause::ownerPid('drain:4242'))->toBe(4242)
        ->and(DrainPause::ownerPid('1756400000'))->toBeNull()
        ->and(DrainPause::ownerPid(null))->toBeNull();
});
