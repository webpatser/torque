<?php

declare(strict_types=1);

use Webpatser\Torque\Dashboard\Data\OverviewData;

/**
 * The overview tachometer needs a ceiling that suits the workload. A queue that
 * receives one burst of 1500 jobs every five minutes reads as flat zero against
 * a fixed 2000 scale, so the auto scale follows the busiest minute of the hour.
 */
it('rounds the auto gauge scale up to a 1, 2 or 5 times a power of ten', function (float $current, array $buckets, int $expected) {
    expect(OverviewData::gaugeMax($current, $buckets))->toBe($expected);
})->with([
    // Nothing has run yet: the floor keeps the tick labels meaningful.
    'idle' => [0.0, [], 100],
    'all quiet' => [0.0, [0, 0, 0], 100],
    'below the floor' => [12.0, [30, 12], 100],
    'exactly the floor' => [100.0, [100], 100],
    'just over the floor' => [101.0, [101], 200],
    'mid hundreds' => [300.0, [420, 12], 500],
    'a burst of 1500' => [300.0, [1500, 0, 0, 0, 0], 2000],
    'round thousand' => [900.0, [1000], 1000],
    'past the decade' => [0.0, [5001], 10000],
    // The needle must never sit past the end of the scale, even when the peak
    // minute has already aged out of the window.
    'current above the peak bucket' => [880.0, [10], 1000],
]);

it('freezes the gauge scale when one is configured', function () {
    config()->set('torque.dashboard.gauge_max', 750);

    expect(OverviewData::gaugeMax(300.0, [1500]))->toBe(750);
});
