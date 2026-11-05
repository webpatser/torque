<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess on a file cache store, so the test process can write
 * the queue:restart key from outside. Started by
 * tests/Integration/QueueRestartTest.php.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, cache
 * directory, restartable (1 or 0).
 */

use Illuminate\Queue\Worker;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $redisUri, $prefix, $group, $queue, $logFile, $cacheDir, $restartable] = $argv;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'file',
    'cache.stores.file' => ['driver' => 'file', 'path' => $cacheDir],
    'queue.failed.driver' => 'null',
    'restart_probe.log' => $logFile,
]);

Worker::$restartable = $restartable === '1';

(new WorkerProcess(array_replace_recursive(config('torque'), [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'serializer' => 'json',
    'queues' => [$queue],
    'streams' => [$queue => ['max_retries' => 1, 'retry_after' => 90]],
    'coroutines_per_worker' => 1,
    'block_for' => 100,
    'max_jobs_per_worker' => 1000,
    'max_worker_lifetime' => 120,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => 10,
    'intervals' => require __DIR__.'/fast-intervals.php',
])))->run();
