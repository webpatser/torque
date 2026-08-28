<?php

declare(strict_types=1);

use Webpatser\Torque\Metrics\MetricsCollector;
use Webpatser\Torque\Metrics\WorkerSnapshot;

it('starts with zero counters', function () {
    $collector = new MetricsCollector(totalSlots: 50);

    expect($collector->jobsProcessed)->toBe(0);
    expect($collector->jobsFailed)->toBe(0);
    expect($collector->activeSlots)->toBe(0);
    expect($collector->totalSlots)->toBe(50);
    expect($collector->slotUsageRatio)->toBe(0.0);
});

it('tracks active slots on start and complete', function () {
    $collector = new MetricsCollector(totalSlots: 10);

    $collector->recordJobStarted();
    expect($collector->activeSlots)->toBe(1);

    $collector->recordJobStarted();
    expect($collector->activeSlots)->toBe(2);

    $collector->recordJobCompleted(100.0);
    expect($collector->activeSlots)->toBe(1);
    expect($collector->jobsProcessed)->toBe(1);
});

it('tracks failed jobs', function () {
    $collector = new MetricsCollector(totalSlots: 10);

    $collector->recordJobStarted();
    $collector->recordJobFailed(50.0);

    expect($collector->jobsFailed)->toBe(1);
    expect($collector->activeSlots)->toBe(0);
});

it('calculates slot usage ratio', function () {
    $collector = new MetricsCollector(totalSlots: 4);

    $collector->recordJobStarted();
    expect($collector->slotUsageRatio)->toBe(0.25);

    $collector->recordJobStarted();
    $collector->recordJobStarted();
    expect($collector->slotUsageRatio)->toBe(0.75);
});

it('computes rolling average latency', function () {
    $collector = new MetricsCollector(totalSlots: 10, latencyWindowSize: 3);

    $collector->recordJobStarted();
    $collector->recordJobCompleted(100.0);

    $collector->recordJobStarted();
    $collector->recordJobCompleted(200.0);

    expect($collector->getAverageLatencyMs())->toBe(150.0);
});

it('uses circular buffer for latency', function () {
    $collector = new MetricsCollector(totalSlots: 10, latencyWindowSize: 2);

    $collector->recordJobStarted();
    $collector->recordJobCompleted(100.0);

    $collector->recordJobStarted();
    $collector->recordJobCompleted(200.0);

    // Buffer is full (size 2). Average = (100 + 200) / 2 = 150.
    expect($collector->getAverageLatencyMs())->toBe(150.0);

    // New sample overwrites oldest.
    $collector->recordJobStarted();
    $collector->recordJobCompleted(300.0);

    // Buffer now has [300, 200]. Average = (300 + 200) / 2 = 250.
    expect($collector->getAverageLatencyMs())->toBe(250.0);
});

it('prevents active slots from going below zero', function () {
    $collector = new MetricsCollector(totalSlots: 10);

    $collector->recordJobCompleted(50.0);
    expect($collector->activeSlots)->toBe(0);
});

it('creates a snapshot', function () {
    $collector = new MetricsCollector(totalSlots: 20);

    $collector->recordJobStarted();
    $collector->recordJobStarted();
    $collector->recordJobCompleted(100.0);
    $collector->recordJobStarted();
    $collector->recordJobFailed(50.0);

    $snapshot = $collector->snapshot();

    expect($snapshot)->toBeInstanceOf(WorkerSnapshot::class);
    expect($snapshot->jobsProcessed)->toBe(1);
    expect($snapshot->jobsFailed)->toBe(1);
    // activeSlots in snapshot reports max(current, peak). Peak was 2 (two concurrent starts).
    expect($snapshot->activeSlots)->toBe(2);
    expect($snapshot->totalSlots)->toBe(20);
    expect($snapshot->slotUsageRatio)->toBe(0.1);
    expect($snapshot->averageLatencyMs)->toBe(75.0);
    expect($snapshot->memoryBytes)->toBeGreaterThan(0);
    expect($snapshot->timestamp)->toBeGreaterThan(0);
});

it('resets all counters', function () {
    $collector = new MetricsCollector(totalSlots: 10);

    $collector->recordJobStarted();
    $collector->recordJobCompleted(100.0);
    $collector->recordJobStarted();
    $collector->recordJobFailed(50.0);

    $collector->reset();

    expect($collector->jobsProcessed)->toBe(0);
    expect($collector->jobsFailed)->toBe(0);
    expect($collector->activeSlots)->toBe(0);
    expect($collector->getAverageLatencyMs())->toBe(0.0);
});

it('attributes outcomes to their stream', function () {
    $collector = new MetricsCollector(totalSlots: 10);

    $collector->recordJobStarted();
    $collector->recordJobCompleted(12.0, 'default');
    $collector->recordJobStarted();
    $collector->recordJobCompleted(8.0, 'default');
    $collector->recordJobStarted();
    $collector->recordJobFailed(20.0, 'default');
    $collector->recordJobStarted();
    $collector->recordJobCompleted(5.0, 'high');

    // A job handled outside a named stream still counts in the totals, it just
    // carries no attribution.
    $collector->recordJobStarted();
    $collector->recordJobCompleted(3.0);

    $snapshot = $collector->snapshot();

    expect($snapshot->perQueue)->toBe(['default' => [2, 1], 'high' => [1, 0]])
        ->and($snapshot->jobsProcessed)->toBe(4)
        ->and($snapshot->jobsFailed)->toBe(1);

    $collector->reset();

    expect($collector->snapshot()->perQueue)->toBe([]);
});

it('attributes runtime to the job class that produced it', function () {
    $collector = new MetricsCollector(totalSlots: 10);

    $collector->recordJobStarted();
    $collector->recordJobCompleted(120.0, 'default', 'App\Jobs\ProcessPodcast');
    $collector->recordJobStarted();
    $collector->recordJobCompleted(80.0, 'default', 'App\Jobs\ProcessPodcast');
    $collector->recordJobStarted();
    $collector->recordJobFailed(500.0, 'default', 'App\Jobs\ProcessPodcast');
    $collector->recordJobStarted();
    $collector->recordJobCompleted(10.0, 'high', 'App\Jobs\SendInvoice');

    // No class attribution still counts in the totals.
    $collector->recordJobStarted();
    $collector->recordJobCompleted(5.0, 'default');

    $snapshot = $collector->snapshot();

    expect($snapshot->perJob)->toBe([
        // [processed, failed, runtimeSumMs, runtimeMaxMs]: the failed run is the
        // slowest, and a peak is a high-water mark rather than a sum.
        'App\Jobs\ProcessPodcast' => [2, 1, 700.0, 500.0],
        'App\Jobs\SendInvoice' => [1, 0, 10.0, 10.0],
    ])
        ->and($snapshot->jobsProcessed)->toBe(4)
        ->and($snapshot->jobsFailed)->toBe(1);

    $collector->reset();

    expect($collector->snapshot()->perJob)->toBe([]);
});
