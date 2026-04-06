<?php

declare(strict_types=1);

afterEach(function () {
    // Clean up any PID files created during tests.
    $pidFile = storage_path('torque.pid');

    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
});

it('shows error when no PID file exists', function () {
    // Ensure no PID file exists.
    $pidFile = storage_path('torque.pid');

    if (file_exists($pidFile)) {
        unlink($pidFile);
    }

    $this->artisan('torque:stop')
        ->assertFailed()
        ->expectsOutputToContain('no PID file found');
});

it('cleans up stale PID file when process is not running', function () {
    $pidFile = storage_path('torque.pid');

    // Write a PID that definitely does not correspond to a running process.
    // PID 2147483647 is the maximum 32-bit PID and almost certainly not in use.
    file_put_contents($pidFile, '2147483647');

    $this->artisan('torque:stop')
        ->assertSuccessful()
        ->expectsOutputToContain('not running');

    expect(file_exists($pidFile))->toBeFalse();
});

it('shows error when PID file contains invalid PID', function () {
    $pidFile = storage_path('torque.pid');

    file_put_contents($pidFile, '0');

    $this->artisan('torque:stop')
        ->assertFailed()
        ->expectsOutputToContain('invalid PID');

    // PID file should be cleaned up.
    expect(file_exists($pidFile))->toBeFalse();
});

it('shows error when PID file contains negative PID', function () {
    $pidFile = storage_path('torque.pid');

    file_put_contents($pidFile, '-1');

    $this->artisan('torque:stop')
        ->assertFailed()
        ->expectsOutputToContain('invalid PID');
});

it('shows error when PID file contains non-numeric content', function () {
    $pidFile = storage_path('torque.pid');

    file_put_contents($pidFile, 'not-a-pid');

    $this->artisan('torque:stop')
        ->assertFailed()
        ->expectsOutputToContain('invalid PID');
});
