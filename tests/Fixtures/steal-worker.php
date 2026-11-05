<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess for tests/Integration/WorkerStealTest.php, appending
 * one JSON line per finished attempt to a log file; StealProbeJob logs its own
 * handler runs to the same file. Exits by itself once max jobs is hit.
 *
 * Arguments: log file, worker config overrides as JSON.
 */

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Event;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $logFile, $settings] = $argv;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
    'steal_probe.log' => $logFile,
]);

$app->make(ExceptionHandler::class)->reportable(static fn (RuntimeException $e): bool => false);

Event::listen(JobAttempted::class, static function (JobAttempted $event) use ($logFile): void {
    file_put_contents($logFile, json_encode([
        'type' => 'attempted',
        'job' => $event->job->payload()['displayName'],
        'queue' => $event->job->getQueue(),
        'released' => $event->job->isReleased(),
        'failed' => $event->job->hasFailed(),
    ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
});

// Plain assignment for these two: merging would keep the default config's entries.
$settings = json_decode($settings, true, flags: JSON_THROW_ON_ERROR);
$config = array_replace_recursive(config('torque'), $settings);
$config['queues'] = $settings['queues'];
$config['streams'] = $settings['streams'];
$config['intervals'] = require __DIR__.'/fast-intervals.php';

(new WorkerProcess($config))->run();
