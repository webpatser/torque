<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess on a file cache store and appends the job and worker
 * events a timeout touches to a JSON lines log, with the Context each listener
 * sees. Started by tests/Integration/JobTimeoutsTest.php.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, cache
 * directory, options (JSON: enabled, concurrency, grace, max_jobs, memory_limit,
 * stream (merged into the stream's config), hang_on (the event whose listener
 * never returns), slow_on and slow_seconds (the event whose listener takes that
 * long before it logs), database (a sqlite file for the jobs, failed_jobs and batches),
 * failed_driver (queue.failed.driver with a database)).
 *
 * WorkerStopping also logs which jobs are still in memory, as `alive`.
 */

use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobInterrupted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleased;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Context;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\delay;

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
    'queue.failed.driver' => 'null',
    'timeout_probe.log' => $logFile,
]);

if (isset($options['database'])) {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => $options['database'], 'prefix' => ''],
        'queue.failed' => ['driver' => $options['failed_driver'], 'database' => 'sqlite', 'table' => 'failed_jobs'],
        'queue.batching' => ['database' => 'sqlite', 'table' => 'job_batches'],
    ]);
}

$write = static function (string $event, array $extra = []) use ($logFile): void {
    file_put_contents($logFile, json_encode(['event' => $event, 'at' => microtime(true)] + $extra, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
};

$events = app('events');

$jobEvents = [JobProcessed::class, JobFailed::class, JobAttempted::class, JobExceptionOccurred::class, JobReleased::class, JobReleasedAfterException::class, JobTimedOut::class, JobInterrupted::class];

/** @var array<string, WeakReference<object>> $seen */
$seen = [];

foreach ($jobEvents as $class) {
    $events->listen($class, static function (object $event) use ($class, $write, $options, &$seen): void {
        $tag = $event->job->payload()['displayName'] ?? null;
        $seen[$tag] = WeakReference::create($event->job);

        if (($options['slow_on'] ?? null) === class_basename($class)) {
            delay((float) $options['slow_seconds']);
        }

        $write(class_basename($class), [
            'tag' => $tag,
            'context' => Context::get('label'),
            'exception' => isset($event->exception) ? $event->exception::class : null,
            'timeout' => $event instanceof JobTimedOut ? $event->timeout : null,
            'backoff' => $event instanceof JobReleasedAfterException ? $event->backoff : null,
            'signal' => $event instanceof JobInterrupted ? $event->signal : null,
            'duration' => $event instanceof JobAttempted ? $event->duration : null,
        ]);

        if (($options['hang_on'] ?? null) === class_basename($class)) {
            delay(30);
        }
    });
}

$events->listen(WorkerStopping::class, static function (WorkerStopping $e) use ($write, &$seen): void {
    gc_collect_cycles();

    $write('WorkerStopping', [
        'status' => $e->status,
        'reason' => $e->reason?->value,
        'jobs' => $e->jobsProcessed,
        'alive' => array_keys(array_filter($seen, static fn (WeakReference $job): bool => $job->get() !== null)),
    ]);
});

(new WorkerProcess(array_replace_recursive(config('torque'), require __DIR__.'/baseline-limits.php', [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'serializer' => 'json',
    'queues' => [$queue],
    'streams' => [$queue => ($options['stream'] ?? []) + ['max_retries' => 3, 'retry_after' => 90, 'circuit_breaker' => false]],
    'slots_per_worker' => (int) ($options['concurrency'] ?? 1),
    'poll_interval_ms' => 100,
    'max_jobs_per_worker' => (int) ($options['max_jobs'] ?? 1000),
    'max_worker_lifetime' => 60,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => (int) ($options['grace'] ?? 1),
    'memory_limit' => (int) ($options['memory_limit'] ?? 0),
    // The tests size a release against the grace they pin, unless they set their own.
    'timeouts' => [
        'enabled' => (bool) ($options['enabled'] ?? true),
        'release_delay_seconds' => (int) ($options['release_delay'] ?? $options['grace'] ?? 1),
    ],
])))->run();
