<?php

declare(strict_types=1);

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
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
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
    } catch (\Amp\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
