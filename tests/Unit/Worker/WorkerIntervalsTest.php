<?php

declare(strict_types=1);

use Webpatser\Torque\Support\DrainSettings;
use Webpatser\Torque\Worker\WorkerProcess;

it('ships the production periods as the config defaults', function () {
    $config = require dirname(__DIR__, 3).'/config/torque.php';

    expect($config['intervals'])->toBe([
        'stop_check_seconds' => 1.0,
        'down_check_seconds' => 1.0,
        'paused_poll_seconds' => 1.0,
        'pause_check_seconds' => 2.0,
        'watchdog_check_seconds' => 2.0,
        'delayed_check_seconds' => 1.0,
        'drain_slack_seconds' => 15,
        'reload_signal_slack_seconds' => 5,
    ]);
});

it('takes a period from the config', function () {
    expect(WorkerProcess::intervalSeconds(['intervals' => ['stop_check_seconds' => 0.2]], 'stop_check_seconds', 1.0))->toBe(0.2)
        ->and(WorkerProcess::intervalSeconds(['intervals' => ['stop_check_seconds' => '3']], 'stop_check_seconds', 1.0))->toBe(3.0);
});

it('falls back to the default without a usable value, as a published config has none', function (array $config) {
    expect(WorkerProcess::intervalSeconds($config, 'stop_check_seconds', 2.0))->toBe(2.0);
})->with([
    'no intervals' => [[]],
    'no key' => [['intervals' => []]],
    'null' => [['intervals' => ['stop_check_seconds' => null]]],
    'not a number' => [['intervals' => ['stop_check_seconds' => 'often']]],
]);

it('floors a period so a bad value cannot spin the loop', function (mixed $value) {
    expect(WorkerProcess::intervalSeconds(['intervals' => ['stop_check_seconds' => $value]], 'stop_check_seconds', 1.0))->toBe(0.05);
})->with([0, -1, 0.001, '0']);

it('keeps the 15 second drain slack without the key, and floors it at 1', function () {
    expect(DrainSettings::slackSeconds([]))->toBe(15)
        ->and(DrainSettings::slackSeconds(['intervals' => ['drain_slack_seconds' => 3]]))->toBe(3)
        ->and(DrainSettings::slackSeconds(['intervals' => ['drain_slack_seconds' => 0]]))->toBe(1);
});
