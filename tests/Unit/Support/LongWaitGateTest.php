<?php

declare(strict_types=1);

use Webpatser\Torque\Support\LongWaitGate;

/*
 * The decision half of long-wait alerts: pure, no Redis and no master. One
 * alert per episode of a queue being over its threshold; a recovery or the
 * cooldown opens the next one.
 */

it('fires once for an episode', function () {
    $gate = new LongWaitGate;

    expect($gate->shouldAlert('q', 90.0, 60, 1000.0))->toBeTrue()
        ->and($gate->shouldAlert('q', 95.0, 60, 1005.0))->toBeFalse()
        ->and($gate->shouldAlert('q', 120.0, 60, 1030.0))->toBeFalse();
});

it('does not fire at or below the threshold', function () {
    $gate = new LongWaitGate;

    expect($gate->shouldAlert('q', 60.0, 60, 1000.0))->toBeFalse()
        ->and($gate->shouldAlert('q', 10.0, 60, 1001.0))->toBeFalse();
});

it('fires again after the queue recovered below the threshold', function () {
    $gate = new LongWaitGate;

    expect($gate->shouldAlert('q', 90.0, 60, 1000.0))->toBeTrue()
        ->and($gate->shouldAlert('q', 5.0, 60, 1030.0))->toBeFalse()
        ->and($gate->shouldAlert('q', 70.0, 60, 1060.0))->toBeTrue();
});

it('treats an empty queue as recovered', function () {
    $gate = new LongWaitGate;

    expect($gate->shouldAlert('q', 90.0, 60, 1000.0))->toBeTrue()
        ->and($gate->shouldAlert('q', null, 60, 1030.0))->toBeFalse()
        ->and($gate->shouldAlert('q', 90.0, 60, 1060.0))->toBeTrue();
});

it('fires again when the cooldown passes while still over', function () {
    $gate = new LongWaitGate;

    expect(LongWaitGate::COOLDOWN_SECONDS)->toBe(900)
        ->and($gate->shouldAlert('q', 90.0, 60, 1000.0))->toBeTrue()
        ->and($gate->shouldAlert('q', 990.0, 60, 1000.0 + LongWaitGate::COOLDOWN_SECONDS - 1))->toBeFalse()
        ->and($gate->shouldAlert('q', 991.0, 60, 1000.0 + LongWaitGate::COOLDOWN_SECONDS))->toBeTrue()
        ->and($gate->shouldAlert('q', 992.0, 60, 1001.0 + LongWaitGate::COOLDOWN_SECONDS))->toBeFalse();
});

it('tracks queues independently', function () {
    $gate = new LongWaitGate;

    expect($gate->shouldAlert('a', 90.0, 60, 1000.0))->toBeTrue()
        ->and($gate->shouldAlert('b', 90.0, 60, 1000.0))->toBeTrue()
        ->and($gate->shouldAlert('a', 90.0, 60, 1001.0))->toBeFalse();
});

it('never fires for a threshold of 0', function () {
    $gate = new LongWaitGate;

    expect($gate->shouldAlert('q', 99999.0, 0, 1000.0))->toBeFalse();
});

it('fires again at the next check after a re-arm', function () {
    $gate = new LongWaitGate;

    expect($gate->shouldAlert('q', 90.0, 60, 1000.0))->toBeTrue();

    $gate->rearm('q');

    expect($gate->shouldAlert('q', 120.0, 60, 1030.0))->toBeTrue();
});

it('shortens the wait for the next alert after a failed one', function () {
    $gate = new LongWaitGate;

    expect($gate->shouldAlert('q', 90.0, 60, 1000.0))->toBeTrue();

    $gate->retryAfter('q', 300);

    expect($gate->shouldAlert('q', 120.0, 60, 1299.0))->toBeFalse()
        ->and($gate->shouldAlert('q', 121.0, 60, 1300.0))->toBeTrue();
});

it('does not invent an alert time for a queue that already recovered', function () {
    $gate = new LongWaitGate;

    $gate->retryAfter('q', 300);

    expect($gate->shouldAlert('q', 90.0, 60, 1000.0))->toBeTrue();
});

it('keeps only queues with a positive threshold', function () {
    expect(LongWaitGate::thresholds(['a' => 60, 'b' => 0, 'c' => '30', 'd' => null, 'e' => -5]))
        ->toBe(['a' => 60, 'c' => 30])
        ->and(LongWaitGate::thresholds([]))->toBe([]);
});
