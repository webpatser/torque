<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Facades\Gate;
use Webpatser\Torque\Dashboard\Data\QueuesData;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * The queues screen reads its counts from the per-stream metric rollups and its
 * breaker state from the shared CircuitBreaker. Both are Redis-backed, so these
 * drive the real keyspace on the test database.
 */
beforeEach(function () {
    $this->prefix = 'torque-test:';

    try {
        $redis = torqueRedis();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }

    foreach (['minute', 'hour', 'day'] as $tier) {
        $redis->execute('DEL', $this->prefix.'metrics:rollup:'.$tier);
        $redis->execute('DEL', $this->prefix.'metrics:rollup:'.$tier.':default');
    }

    $redis->execute('DEL', $this->prefix.'cb:default:state');

    // reservedSize() reads the consumer group's pending list, which errors with
    // NOGROUP until the stream and group exist.
    $redis->execute('DEL', $this->prefix.'default');
    $redis->execute('XGROUP', 'CREATE', $this->prefix.'default', 'torque-test', '$', 'MKSTREAM');
});

afterEach(function () {
    rescue(function (): void {
        $redis = torqueRedis();

        foreach (['minute', 'hour', 'day'] as $tier) {
            $redis->execute('DEL', $this->prefix.'metrics:rollup:'.$tier);
            $redis->execute('DEL', $this->prefix.'metrics:rollup:'.$tier.':default');
        }

        $redis->execute('DEL', $this->prefix.'cb:default:state');
        $redis->execute('DEL', $this->prefix.'default');
    }, null, false);
});

it('reports per-stream daily counts and a per-minute throughput', function () {
    $now = time();

    app(MetricsPublisher::class)->recordOutcomes(12, 2, ['default' => [12, 2]], $now);

    $queues = app(QueuesData::class)->get()['queues'];
    $default = collect($queues)->firstWhere('name', 'default');

    expect($default)->toHaveKeys([
        'name', 'pending', 'delayed', 'reserved', 'processedToday', 'failedToday',
        'throughput', 'wait', 'history', 'paused', 'circuit',
    ])
        ->and($default['processedToday'])->toBe(12)
        ->and($default['failedToday'])->toBe(2)
        // Jobs per minute over the 5-minute window, not per second.
        ->and($default['throughput'])->toBeGreaterThan(0.0)
        // No collector for queue wait time yet, so the column stays hidden.
        ->and($default['wait'])->toBeNull()
        ->and($default['circuit'])->toBeNull();
});

it('exposes an open circuit breaker with the seconds until it resumes', function () {
    // The state key with a TTL is exactly what the breaker's Lua writes when it
    // trips; seeding it directly keeps this test about the read model.
    torqueRedis()->execute('SET', $this->prefix.'cb:default:state', 'open', 'EX', '240');

    $default = collect(app(QueuesData::class)->get()['queues'])->firstWhere('name', 'default');

    expect($default['circuit']['state'])->toBe('open')
        ->and($default['circuit']['resumesIn'])->toBeGreaterThan(230)->toBeLessThanOrEqual(240);
});

it('renders a circuit badge on the queues screen', function () {
    config()->set('torque.dashboard.enabled', true);
    TorqueDashboardController::register();
    Gate::define('viewTorque', fn ($user): bool => true);

    torqueRedis()->execute('SET', $this->prefix.'cb:default:state', 'open', 'EX', '240');

    $html = $this->actingAs(torqueTestUser())->get('/torque/queues')->assertOk()->getContent();

    expect($html)->toContain('circuit open · resumes in 4m 0s')
        ->toContain('badge s-failed tiny');
});
