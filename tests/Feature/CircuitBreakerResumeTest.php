<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Torque;

/*
 * Operators must always win over a cooldown: both `torque:pause continue` and
 * the framework's `queue:resume` force a tripped breaker closed, so a stream
 * starts flowing again the moment the upstream problem is fixed.
 */

beforeEach(function () {
    $this->redis = torqueRedis();

    config([
        'torque.streams' => ['default' => [], 'reports' => []],
        'torque.circuit_breaker' => [
            'enabled' => true,
            'window' => 100,
            'min_samples' => 2,
            'threshold' => 0.9,
            'cooldown' => 300,
            'half_open_max' => 5,
            'retention' => 3600,
        ],
    ]);

    $this->breaker = app(CircuitBreaker::class);
    $this->trip = function (string $queue): void {
        $this->breaker->recordFailure($queue);
        $this->breaker->recordFailure($queue);
    };
});

afterEach(function () {
    foreach ($this->redis->execute('KEYS', 'torque-test:cb:*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }

    $this->redis->execute('DEL', 'torque-test:paused');
});

it('closes every breaker on torque:pause continue', function () {
    ($this->trip)('default');
    ($this->trip)('reports');

    expect($this->breaker->openQueues(['default', 'reports']))->toBe(['default', 'reports']);

    Artisan::call('torque:pause', ['action' => 'continue']);

    expect($this->breaker->openQueues(['default', 'reports']))->toBe([])
        ->and(Artisan::output())->toContain('Closed the circuit breaker on: default, reports.');
});

it('closes the matching breaker on queue:resume', function () {
    ($this->trip)('default');
    ($this->trip)('reports');

    app('queue')->resume(Torque::CONNECTION, 'reports');

    expect($this->breaker->openQueues(['default', 'reports']))->toBe(['default']);
});

it('ignores a resume for another connection', function () {
    ($this->trip)('default');

    app('queue')->resume('redis', 'default');

    expect($this->breaker->openQueues(['default']))->toBe(['default']);
});

it('closes every breaker on queue:resume --all', function () {
    ($this->trip)('default');
    ($this->trip)('reports');

    app('queue')->resumeAll();

    expect($this->breaker->openQueues(['default', 'reports']))->toBe([]);
});
