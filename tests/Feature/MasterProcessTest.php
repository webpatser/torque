<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;

it('pidFilePath returns storage path', function () {
    $path = MasterProcess::pidFilePath();

    expect($path)->toContain('storage')
        ->and($path)->toEndWith('torque.pid');
});

it('readPid returns null when no pid file exists', function () {
    // Ensure no PID file exists.
    $path = MasterProcess::pidFilePath();

    if (file_exists($path)) {
        unlink($path);
    }

    expect(MasterProcess::readPid())->toBeNull();
});

it('readPid returns null for symlinks', function () {
    $path = MasterProcess::pidFilePath();
    $tmpFile = $path . '.symlink-test';

    // Clean up any previous test artifacts.
    if (is_link($path)) {
        unlink($path);
    }
    if (file_exists($path)) {
        unlink($path);
    }

    // Create a temp file and symlink the PID path to it.
    file_put_contents($tmpFile, (string) getmypid());
    symlink($tmpFile, $path);

    expect(MasterProcess::readPid())->toBeNull();

    // Clean up.
    if (is_link($path)) {
        unlink($path);
    }
    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

it('readPid returns null for invalid pid content', function () {
    $path = MasterProcess::pidFilePath();

    // Clean up symlinks first.
    if (is_link($path)) {
        unlink($path);
    }

    file_put_contents($path, '0');

    expect(MasterProcess::readPid())->toBeNull();

    // Clean up.
    if (file_exists($path) && ! is_link($path)) {
        unlink($path);
    }
});

it('constructor accepts config and logger', function () {
    $logMessages = [];
    $logger = function (string $message) use (&$logMessages) {
        $logMessages[] = $message;
    };

    $master = new MasterProcess(
        config: ['workers' => 2],
        logger: $logger,
    );

    expect($master)->toBeInstanceOf(MasterProcess::class)
        ->and($master->workerPids)->toBe([])
        ->and($master->workerCount)->toBe(0);
});
