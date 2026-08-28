<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Data\OverviewData;
use Webpatser\Torque\Dashboard\Livewire\Overview;
use Webpatser\Torque\Metrics\MetricsPublisher;

beforeEach(function () {
    try {
        $redis = torqueRedis();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }

    // OverviewData reads stream depths, which error with NOGROUP until the
    // stream and its consumer group exist.
    $redis->execute('DEL', 'torque-test:default');
    $redis->execute('XGROUP', 'CREATE', 'torque-test:default', 'torque-test', '$', 'MKSTREAM');

    foreach (['minute', 'hour', 'day'] as $tier) {
        $redis->execute('DEL', 'torque-test:metrics:rollup:'.$tier);
        $redis->execute('DEL', 'torque-test:metrics:gauge:'.$tier);
    }
});

afterEach(function () {
    rescue(function (): void {
        $redis = torqueRedis();
        $redis->execute('DEL', 'torque-test:default');

        foreach (['minute', 'hour', 'day'] as $tier) {
            $redis->execute('DEL', 'torque-test:metrics:rollup:'.$tier);
            $redis->execute('DEL', 'torque-test:metrics:gauge:'.$tier);
        }
    }, null, false);
});

/**
 * The throughput chart range is a server-rendered Livewire property rather than
 * Alpine state: the screen polls, and a morph would otherwise be free to reset
 * whatever the browser was holding.
 */
it('defaults to the last hour and switches range through a server round trip', function () {
    Livewire::test(Overview::class)
        ->assertSet('range', '1h')
        ->assertSee('jobs / minute · last 60 min')
        ->call('setRange', '24h')
        ->assertSet('range', '24h')
        ->assertSee('jobs / hour · last 24 hours')
        ->call('setRange', '90d')
        ->assertSet('range', '90d')
        ->assertSee('jobs / day · last 90 days')
        // An unknown range is ignored rather than trusted into the read model.
        ->call('setRange', 'forever')
        ->assertSet('range', '90d');
});

it('sizes the chart series to the requested range', function () {
    expect(OverviewData::isValidRange('7d'))->toBeTrue()
        ->and(OverviewData::isValidRange('nope'))->toBeFalse();

    $data = app(OverviewData::class);

    expect($data->get('1h')['history'])->toHaveCount(60)
        ->and($data->get('24h')['history'])->toHaveCount(24)
        ->and($data->get('7d')['history'])->toHaveCount(168)
        ->and($data->get('90d')['history'])->toHaveCount(90)
        // An unknown range falls back to the hour view rather than erroring.
        ->and($data->get('bogus')['history'])->toHaveCount(60)
        // The gauge widgets always read the last hour of minutes, whatever the
        // chart is showing.
        ->and($data->get('90d')['minuteHistory'])->toHaveCount(60);
});

it('rolls the same job up into every tier the chart can ask for', function () {
    app(MetricsPublisher::class)->recordOutcomes(42, 1, [], time());

    $history = app(OverviewData::class)->get('90d')['history'];

    // Newest bucket last, and the day tier saw the same 42 jobs the minute
    // tier did.
    expect(end($history))->toBe(42)
        ->and(app(OverviewData::class)->get('24h')['history'])->toHaveCount(24);
});

it('serves every overview sparkline from the persisted gauge tier', function () {
    $now = time();
    $publisher = app(MetricsPublisher::class);

    $publisher->recordGauges([
        MetricsPublisher::GAUGE_LATENCY => 410.0,
        MetricsPublisher::GAUGE_CONCURRENT => 38,
        MetricsPublisher::GAUGE_MEMORY => 256.5,
        MetricsPublisher::GAUGE_WORKER_MEMORY_PEAK => 96.0,
        MetricsPublisher::GAUGE_PENDING => 1200,
        MetricsPublisher::GAUGE_DELAYED => 40,
    ], $now);
    $publisher->recordOutcomes(8, 2, [], $now);

    $series = app(OverviewData::class)->get('1h')['series'];

    // One entry per bucket of the selected range, gap-filled, newest last.
    foreach (['latency', 'concurrent', 'memory', 'memoryPeak', 'pending', 'delayed', 'failRate'] as $key) {
        expect($series[$key])->toHaveCount(60);
    }

    expect(end($series['latency']))->toBe(0.41)      // milliseconds rendered as seconds
        ->and(end($series['concurrent']))->toBe(38.0)
        ->and(end($series['memory']))->toBe(256.5)
        ->and(end($series['memoryPeak']))->toBe(96.0)
        ->and(end($series['pending']))->toBe(1200.0)
        ->and(end($series['delayed']))->toBe(40.0)
        // Failure ratio is derived from the counters rather than stored twice.
        ->and(end($series['failRate']))->toBe(20.0);

    // The sparklines follow the chart's range switch.
    expect(app(OverviewData::class)->get('90d')['series']['pending'])->toHaveCount(90);
});

it('keeps no rolling history in component state', function () {
    $properties = (new ReflectionClass(Overview::class))->getProperties(ReflectionProperty::IS_PUBLIC);
    $names = array_map(fn (ReflectionProperty $p) => $p->getName(), $properties);

    // Everything this screen draws is persisted server-side now, so a reload
    // shows the same history the previous tab was showing.
    expect($names)->not->toContain('throughput')
        ->not->toContain('latency')
        ->not->toContain('concurrent')
        ->not->toContain('memory')
        ->not->toContain('failRate')
        ->not->toContain('pending')
        ->toContain('range');
});
