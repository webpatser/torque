<?php

declare(strict_types=1);

use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Manager\ScaleDecision;

it('recommends scale up when usage exceeds threshold', function () {
    $scaler = new AutoScaler(
        redisUri: 'redis://127.0.0.1:6379',
        minWorkers: 2,
        maxWorkers: 8,
        scaleUpThreshold: 0.85,
        scaleDownThreshold: 0.20,
        cooldownSeconds: 0, // No cooldown for testing.
    );

    $decision = $scaler->evaluate(4, [
        ['active' => 45, 'total' => 50],
        ['active' => 48, 'total' => 50],
        ['active' => 44, 'total' => 50],
        ['active' => 46, 'total' => 50],
    ]);

    // Average: (45+48+44+46) / 200 = 0.915 > 0.85
    expect($decision)->toBe(ScaleDecision::ScaleUp);
});

it('recommends scale down when usage is below threshold', function () {
    $scaler = new AutoScaler(
        redisUri: 'redis://127.0.0.1:6379',
        minWorkers: 2,
        maxWorkers: 8,
        scaleDownThreshold: 0.20,
        cooldownSeconds: 0,
    );

    $decision = $scaler->evaluate(4, [
        ['active' => 5, 'total' => 50],
        ['active' => 3, 'total' => 50],
        ['active' => 2, 'total' => 50],
        ['active' => 4, 'total' => 50],
    ]);

    // Average: (5+3+2+4) / 200 = 0.07 < 0.20
    expect($decision)->toBe(ScaleDecision::ScaleDown);
});

it('recommends no change when usage is in normal range', function () {
    $scaler = new AutoScaler(
        redisUri: 'redis://127.0.0.1:6379',
        cooldownSeconds: 0,
    );

    $decision = $scaler->evaluate(4, [
        ['active' => 25, 'total' => 50],
        ['active' => 30, 'total' => 50],
        ['active' => 20, 'total' => 50],
        ['active' => 28, 'total' => 50],
    ]);

    // Average: (25+30+20+28) / 200 = 0.515 — between 0.20 and 0.85
    expect($decision)->toBe(ScaleDecision::NoChange);
});

it('does not scale up beyond max workers', function () {
    $scaler = new AutoScaler(
        redisUri: 'redis://127.0.0.1:6379',
        maxWorkers: 4,
        cooldownSeconds: 0,
    );

    $decision = $scaler->evaluate(4, [
        ['active' => 49, 'total' => 50],
        ['active' => 50, 'total' => 50],
        ['active' => 48, 'total' => 50],
        ['active' => 50, 'total' => 50],
    ]);

    expect($decision)->toBe(ScaleDecision::NoChange);
});

it('does not scale down below min workers', function () {
    $scaler = new AutoScaler(
        redisUri: 'redis://127.0.0.1:6379',
        minWorkers: 4,
        cooldownSeconds: 0,
    );

    $decision = $scaler->evaluate(4, [
        ['active' => 1, 'total' => 50],
        ['active' => 0, 'total' => 50],
        ['active' => 2, 'total' => 50],
        ['active' => 1, 'total' => 50],
    ]);

    expect($decision)->toBe(ScaleDecision::NoChange);
});

it('respects cooldown period', function () {
    $scaler = new AutoScaler(
        redisUri: 'redis://127.0.0.1:6379',
        cooldownSeconds: 9999, // Very long cooldown.
    );

    // Record an action to start the cooldown.
    $scaler->recordAction();

    $decision = $scaler->evaluate(4, [
        ['active' => 49, 'total' => 50],
        ['active' => 50, 'total' => 50],
        ['active' => 48, 'total' => 50],
        ['active' => 50, 'total' => 50],
    ]);

    expect($decision)->toBe(ScaleDecision::NoChange);
    expect($scaler->canScale)->toBeFalse();
});

it('returns no change for empty metrics', function () {
    $scaler = new AutoScaler(
        redisUri: 'redis://127.0.0.1:6379',
        cooldownSeconds: 0,
    );

    expect($scaler->evaluate(4, []))->toBe(ScaleDecision::NoChange);
});

it('handles zero total slots gracefully', function () {
    $scaler = new AutoScaler(
        redisUri: 'redis://127.0.0.1:6379',
        cooldownSeconds: 0,
    );

    $decision = $scaler->evaluate(4, [
        ['active' => 0, 'total' => 0],
    ]);

    expect($decision)->toBe(ScaleDecision::NoChange);
});
