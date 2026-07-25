<?php

declare(strict_types=1);
use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Metrics\WorkerSnapshot;

it('is registered as an artisan command', function () {
    $commands = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all());

    expect($commands->has('torque:status'))->toBeTrue();
});

it('runs without error even with no metrics in Redis', function () {
    // The status command connects to Redis to read metrics.
    // If Redis is unavailable, it will throw — that's expected in CI.
    try {
        $this->artisan('torque:status')
            ->assertSuccessful();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('shows master status as stopped when no PID file exists', function () {
    $pidFile = storage_path('torque.pid');

    if (file_exists($pidFile)) {
        unlink($pidFile);
    }

    try {
        $this->artisan('torque:status')
            ->assertSuccessful()
            ->expectsOutputToContain('STOPPED');
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('renders the worker pid and latency from the published hash', function () {
    $publisher = new MetricsPublisher(
        redisUri: (string) config('torque.redis.uri'),
        prefix: (string) config('torque.redis.prefix'),
    );

    try {
        $publisher->publishWorkerMetrics('web-01-5123-a1b2c3d4', new WorkerSnapshot(
            jobsProcessed: 42,
            jobsFailed: 3,
            activeSlots: 7,
            totalSlots: 50,
            averageLatencyMs: 12.5678,
            slotUsageRatio: 0.14,
            memoryBytes: 1_048_576,
            timestamp: time(),
        ));

        Artisan::call('torque:status');
        $output = Artisan::output();

        expect($output)->toContain('5123')
            ->and($output)->toContain('12.57 ms')
            ->and($output)->toContain('7/50')
            ->and($output)->not->toContain('| ?')
            // Live queue depth and dead-letter counts render as numbers, not
            // placeholders, even without a master-published aggregate.
            ->and($output)->toContain('Pending');

        $publisher->removeWorkerMetrics('web-01-5123-a1b2c3d4');
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});
