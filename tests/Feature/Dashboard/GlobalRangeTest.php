<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Data\WorkersData;
use Webpatser\Torque\Dashboard\Livewire\Inspector;
use Webpatser\Torque\Dashboard\Livewire\Jobs;
use Webpatser\Torque\Dashboard\Livewire\Overview;
use Webpatser\Torque\Dashboard\Livewire\Queues;
use Webpatser\Torque\Dashboard\Livewire\Workers;
use Webpatser\Torque\Dashboard\Support\Range;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * The time range is dashboard chrome, not per-screen state.
 *
 * It used to live twice, as an `Overview` property driving the throughput chart
 * and a `Jobs` property driving the class table, each with its own seg control
 * in a card head and neither remembered anywhere. One topbar picker now owns it
 * for every screen and the session remembers it, so the throughput chart, the
 * queues table and the workers cards always show the same window.
 */
beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);

    // The service provider registers routes before the test body can tweak
    // config, so re-register to pick up the enabled flag.
    TorqueDashboardController::register();

    Gate::define('viewTorque', fn ($user): bool => true);
});

it('defaults to the configured range and remembers a change for the session', function () {
    Livewire::test(Overview::class)
        ->assertSet('range', '1h')
        ->call('setRange', '90d')
        ->assertSet('range', '90d');

    // A different screen, mounted fresh, opens on the same window.
    Livewire::test(Queues::class)->assertSet('range', '90d');
    Livewire::test(Jobs::class)->assertSet('range', '90d');
    Livewire::test(Workers::class)->assertSet('range', '90d');
});

it('ignores a range it does not know rather than trusting it into the read model', function () {
    Livewire::test(Overview::class)
        ->call('setRange', '24h')
        ->call('setRange', 'forever')
        ->assertSet('range', '24h');

    // A stale or hand-crafted session value degrades to the default too.
    session(['torque.range' => 'forever']);

    Livewire::test(Queues::class)->assertSet('range', Range::DEFAULT);
});

it('honours a configured default range on a fresh session', function () {
    config()->set('torque.dashboard.default_range', '7d');

    Livewire::test(Overview::class)->assertSet('range', '7d');
});

it('renders the picker on every screen that has a time dimension', function () {
    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    expect($html)->toContain('data-torque-method="setRange"')
        ->toContain('class="mono range-label"');
});

it('leaves the picker out of the job inspector', function () {
    // One job's own event stream is bounded by that job's life, not by a
    // fleet-wide clock, so a picker there would describe nothing.
    $html = Livewire::test(Inspector::class)->html();

    expect($html)->not->toContain('data-torque-method="setRange"');
});

it('sizes every screen to the same window', function () {
    try {
        app(MetricsPublisher::class)->recordHostOutcomes(['web-01' => [12, 0]]);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }

    try {
        // 60 minute buckets against 90 day buckets, off the same rollup.
        $hour = app(WorkersData::class)->get('1h')['hosts'];
        $quarter = app(WorkersData::class)->get('90d')['hosts'];

        expect(collect($hour)->firstWhere('host', 'web-01')['history'])->toHaveCount(60)
            ->and(collect($quarter)->firstWhere('host', 'web-01')['history'])->toHaveCount(90);
    } finally {
        $redis = torqueRedis();

        foreach (['minute', 'hour', 'day'] as $tier) {
            rescue(fn () => $redis->execute('DEL', 'torque-test:metrics:rollup:'.$tier.':host'), null, false);
            rescue(fn () => $redis->execute('DEL', 'torque-test:metrics:gauge:'.$tier.':host'), null, false);
        }

        rescue(fn () => $redis->execute('DEL', 'torque-test:metrics:hosts'), null, false);
    }
});

it('keeps no rolling history in component state', function () {
    // Everything these screens draw is served from the persisted rollups now,
    // so a reload shows the same history the previous tab was showing.
    foreach ([Workers::class, Queues::class, Overview::class] as $component) {
        $names = array_map(
            fn (ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass($component))->getProperties(ReflectionProperty::IS_PUBLIC),
        );

        expect($names)->not->toContain('history')
            ->and($names)->toContain('range');
    }
});
