<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess with a Worker::popUsing callback registered under the
 * name Torque reports ('torque'). Started by tests/Integration/PopUsingTest.php.
 *
 * Arguments: redis uri, key prefix, consumer group, queues (comma separated), log
 * file, cache directory, options (JSON: concurrency, max_concurrency, mode,
 * strict_attempts, retry_after).
 *
 * The callback logs `Callback` rows to the log file and does what the file
 * `pop-mode` in the cache directory says (re-read on every call, `mode` seeds it):
 *   pass        return $pop($queue), the slot's read for the queues it was given
 *   null        return null without reading
 *   drop        read with $pop($queue), then return null
 *   other:NAME  return $pop(NAME)
 *   throw       throw
 *   foreign     return a job that did not come from $pop
 *
 * Jobs (JobEventsProbeJob, RedeliveryProbeJob) append their own row to the same log file.
 */

use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Worker;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $redisUri, $prefix, $group, $queueList, $logFile, $cacheDir, $optionsJson] = $argv;

$options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
$queues = explode(',', $queueList);

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'file',
    'cache.stores.file' => ['driver' => 'file', 'path' => $cacheDir],
    'queue.failed.driver' => 'null',
    'restart_probe.log' => $logFile,
    'steal_probe.log' => $logFile,
]);

file_put_contents($cacheDir.'/pop-mode', (string) ($options['mode'] ?? 'pass'));

Worker::popUsing('torque', function (Closure $pop, string $queue) use ($cacheDir, $logFile): mixed {
    $mode = trim((string) @file_get_contents($cacheDir.'/pop-mode'));

    file_put_contents($logFile, json_encode(['event' => 'Callback', 'mode' => $mode, 'queue' => $queue], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

    if (str_starts_with($mode, 'other:')) {
        return $pop(substr($mode, 6));
    }

    return match ($mode) {
        'pass' => $pop($queue),
        'drop' => $pop($queue) === null ? null : null,
        'throw' => throw new RuntimeException('pop callback failed'),
        'foreign' => new SyncJob(app(), json_encode(['job' => 'x', 'data' => []], JSON_THROW_ON_ERROR), 'sync', 'foreign'),
        default => null,
    };
});

$streams = [];

foreach ($queues as $queue) {
    $streams[$queue] = ['max_retries' => 1, 'retry_after' => (int) ($options['retry_after'] ?? 90), 'circuit_breaker' => false]
        + (isset($options['max_concurrency']) ? ['max_concurrency' => (int) $options['max_concurrency']] : []);
}

(new WorkerProcess(array_replace_recursive(config('torque'), [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'serializer' => 'json',
    'queues' => $queues,
    'streams' => $streams,
    'coroutines_per_worker' => (int) ($options['concurrency'] ?? 1),
    'block_for' => 100,
    'max_jobs_per_worker' => 1000,
    'max_worker_lifetime' => 120,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => 5,
    'strict_attempts' => (bool) ($options['strict_attempts'] ?? false),
])))->run();
