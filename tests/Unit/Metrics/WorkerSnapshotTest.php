<?php

declare(strict_types=1);

use Webpatser\Torque\Metrics\WorkerSnapshot;

it('sets all readonly properties via constructor', function () {
    $snapshot = new WorkerSnapshot(
        jobsProcessed: 42,
        jobsFailed: 3,
        activeSlots: 7,
        totalSlots: 50,
        averageLatencyMs: 12.5,
        slotUsageRatio: 0.14,
        memoryBytes: 1_048_576,
        timestamp: 1700000000,
    );

    expect($snapshot->jobsProcessed)->toBe(42);
    expect($snapshot->jobsFailed)->toBe(3);
    expect($snapshot->activeSlots)->toBe(7);
    expect($snapshot->totalSlots)->toBe(50);
    expect($snapshot->averageLatencyMs)->toBe(12.5);
    expect($snapshot->slotUsageRatio)->toBe(0.14);
    expect($snapshot->memoryBytes)->toBe(1_048_576);
    expect($snapshot->timestamp)->toBe(1700000000);
});

it('is a readonly class', function () {
    $reflection = new ReflectionClass(WorkerSnapshot::class);

    expect($reflection->isReadOnly())->toBeTrue();
});

it('has all properties publicly accessible', function () {
    $snapshot = new WorkerSnapshot(
        jobsProcessed: 0,
        jobsFailed: 0,
        activeSlots: 0,
        totalSlots: 10,
        averageLatencyMs: 0.0,
        slotUsageRatio: 0.0,
        memoryBytes: 0,
        timestamp: 0,
    );

    $reflection = new ReflectionClass($snapshot);
    $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

    expect($properties)->toHaveCount(8);

    $names = array_map(fn (ReflectionProperty $p) => $p->getName(), $properties);

    expect($names)->toContain('jobsProcessed')
        ->toContain('jobsFailed')
        ->toContain('activeSlots')
        ->toContain('totalSlots')
        ->toContain('averageLatencyMs')
        ->toContain('slotUsageRatio')
        ->toContain('memoryBytes')
        ->toContain('timestamp');
});

it('accepts zero values for all fields', function () {
    $snapshot = new WorkerSnapshot(
        jobsProcessed: 0,
        jobsFailed: 0,
        activeSlots: 0,
        totalSlots: 0,
        averageLatencyMs: 0.0,
        slotUsageRatio: 0.0,
        memoryBytes: 0,
        timestamp: 0,
    );

    expect($snapshot->jobsProcessed)->toBe(0);
    expect($snapshot->totalSlots)->toBe(0);
    expect($snapshot->averageLatencyMs)->toBe(0.0);
});

it('accepts large values', function () {
    $snapshot = new WorkerSnapshot(
        jobsProcessed: PHP_INT_MAX,
        jobsFailed: PHP_INT_MAX,
        activeSlots: 10_000,
        totalSlots: 10_000,
        averageLatencyMs: 999_999.99,
        slotUsageRatio: 1.0,
        memoryBytes: 8_589_934_592, // 8 GB
        timestamp: 2_000_000_000,
    );

    expect($snapshot->jobsProcessed)->toBe(PHP_INT_MAX);
    expect($snapshot->memoryBytes)->toBe(8_589_934_592);
});
