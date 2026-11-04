<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess on a file cache store, with maintenance mode held
 * in that same store (so the test process can switch it by writing the
 * `illuminate:foundation:down` key), and appends WorkerStopping and the jobs'
 * own events to a JSON lines log. Started by tests/Integration/WorkerMaintenanceTest.php.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, cache
 * directory, options (JSON: force, memory_headroom_mb, concurrency, grace, lifetime).
 *
 * memory_headroom_mb sets the worker's memory_limit that many megabytes above
 * what this process uses once booted.
 */

use Illuminate\Queue\Events\WorkerStopping;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $redisUri, $prefix, $group, $queue, $logFile, $cacheDir, $optionsJson] = $argv;

$options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'file',
    'cache.stores.file' => ['driver' => 'file', 'path' => $cacheDir],
    'app.maintenance.driver' => 'cache',
    'app.maintenance.store' => 'file',
    'queue.failed.driver' => 'null',
    'restart_probe.log' => $logFile,
]);

app('events')->listen(WorkerStopping::class, static function (WorkerStopping $e) use ($logFile): void {
    file_put_contents($logFile, json_encode([
        'event' => 'WorkerStopping',
        'status' => $e->status,
        'reason' => $e->reason?->value,
    ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
});

$memoryLimit = isset($options['memory_headroom_mb'])
    ? (int) ceil(memory_get_usage(true) / 1048576) + (int) $options['memory_headroom_mb']
    : 0;

(new WorkerProcess(array_replace_recursive(config('torque'), [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'serializer' => 'json',
    'queues' => [$queue],
    'streams' => [$queue => ['max_retries' => 1, 'retry_after' => 90, 'circuit_breaker' => false]],
    'coroutines_per_worker' => (int) ($options['concurrency'] ?? 1),
    'block_for' => 100,
    'max_jobs_per_worker' => 1000,
    'max_worker_lifetime' => (int) ($options['lifetime'] ?? 120),
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => (int) ($options['grace'] ?? 10),
    'memory_limit' => $memoryLimit,
    'force' => (bool) ($options['force'] ?? false),
])))->run();
