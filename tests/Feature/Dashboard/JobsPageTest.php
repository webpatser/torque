<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Livewire\Jobs;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * The jobs screen is Horizon's per-class metrics read off the per-class
 * rollups. Range and sort are server-owned properties, because the screen polls
 * and a morph would be free to reset anything the browser held on its own.
 */
$classes = ['App\Jobs\Slow', 'App\Jobs\Busy', 'App\Jobs\Broken'];

beforeEach(function () use ($classes) {
    try {
        $redis = torqueRedis();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }

    foreach (['minute', 'hour', 'day'] as $tier) {
        foreach ($classes as $class) {
            $redis->execute('DEL', 'torque-test:metrics:rollup:'.$tier.':job:'.$class);
        }
    }

    $redis->execute('DEL', 'torque-test:metrics:jobs');

    $now = time();
    $publisher = app(MetricsPublisher::class);

    // Busy: high throughput, quick, clean. Slow: rare but heavy.
    // Broken: mostly failing.
    $publisher->recordJobOutcomes(['App\Jobs\Busy' => [600, 0, 6000.0, 25.0]], $now);
    $publisher->recordJobOutcomes(['App\Jobs\Slow' => [6, 0, 90000.0, 30000.0]], $now);
    $publisher->recordJobOutcomes(['App\Jobs\Broken' => [2, 18, 1000.0, 90.0]], $now);
});

afterEach(function () use ($classes) {
    rescue(function () use ($classes): void {
        $redis = torqueRedis();

        foreach (['minute', 'hour', 'day'] as $tier) {
            foreach ($classes as $class) {
                $redis->execute('DEL', 'torque-test:metrics:rollup:'.$tier.':job:'.$class);
            }
        }

        $redis->execute('DEL', 'torque-test:metrics:jobs');
    }, null, false);
});

it('renders a row per job class with runtime and failure figures', function () {
    config()->set('torque.dashboard.enabled', true);
    TorqueDashboardController::register();
    Gate::define('viewTorque', fn ($user): bool => true);

    $html = $this->actingAs(torqueTestUser())->get('/torque/jobs')->assertOk()->getContent();

    expect($html)
        // Namespace dim and split off, full class in the title, the way the
        // feed and dead-letter tables render job names.
        ->toContain('<span class="ns">App\Jobs\\</span>')
        ->toContain('title="App\Jobs\\Busy"')
        ->toContain('title="App\Jobs\\Slow"')
        ->toContain('title="App\Jobs\\Broken"')
        // Average runtime of the Slow class: 90000ms over 6 jobs.
        ->and($html)->toContain('15000.0')
        // Failure badge carries both the count and the rate.
        ->and($html)->toContain('18 · 90.0%')
        // The nav entry sits under Inspect.
        ->and($html)->toContain('>Jobs</span>');
});

it('sorts server-side and flips direction on a repeated column', function () {
    $component = Livewire::test(Jobs::class)
        ->assertSet('sort', 'throughput')
        ->assertSet('direction', 'desc');

    // Busiest first by default.
    expect(array_column($component->viewData('jobs'), 'cls'))->toBe(['Busy', 'Broken', 'Slow']);

    $component->call('sortBy', 'runtime');

    expect($component->get('direction'))->toBe('desc')
        ->and(array_column($component->viewData('jobs'), 'cls'))->toBe(['Slow', 'Broken', 'Busy']);

    // Same column again flips it rather than re-sorting the same way.
    $component->call('sortBy', 'runtime');

    expect($component->get('direction'))->toBe('asc')
        ->and(array_column($component->viewData('jobs'), 'cls'))->toBe(['Busy', 'Broken', 'Slow']);

    $component->call('sortBy', 'failures');

    expect(array_column($component->viewData('jobs'), 'cls'))->toBe(['Broken', 'Busy', 'Slow']);

    // Names read best A to Z, so that column opens ascending.
    $component->call('sortBy', 'name');

    expect($component->get('direction'))->toBe('asc')
        ->and(array_column($component->viewData('jobs'), 'cls'))->toBe(['Broken', 'Busy', 'Slow']);

    // An unknown key is ignored rather than trusted into the read model.
    $component->call('sortBy', 'memory');

    expect($component->get('sort'))->toBe('name');
});

it('switches the metric range without touching client state', function () {
    $component = Livewire::test(Jobs::class)
        ->assertSet('range', '1h')
        ->assertSee('last 60 minutes');

    // 600 jobs in the last hour is 10 a minute; over 24 hours the same 600 jobs
    // average out far lower, which is what makes the column comparable.
    expect(collect($component->viewData('jobs'))->firstWhere('cls', 'Busy')['throughput'])->toBe(10.0);

    $component->call('setRange', '24h')->assertSee('last 24 hours');

    expect(collect($component->viewData('jobs'))->firstWhere('cls', 'Busy')['throughput'])->toBe(0.42);

    $component->call('setRange', 'forever');

    expect($component->get('range'))->toBe('24h');
});
