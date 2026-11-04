<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess on a file cache store and appends every worker
 * lifecycle event it dispatches to a JSON lines log. Started by
 * tests/Integration/WorkerEventsTest.php.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, cache
 * directory, options (JSON: lifetime, max_jobs, circuit_breaker, concurrency,
 * max_retries (null leaves it out of the stream config), job_events, grace,
 * memory_limit, force, stopping_delay).
 *
 * With `stopping_delay` the WorkerStopping listener suspends that many seconds
 * after logging, then logs WorkerStoppingDone.
 *
 * Two control files in the cache directory steer the Looping listener while the
 * worker runs: `looping-false` makes it return false, `looping-throw` makes it throw.
 *
 * With `job_events` every job event is logged too, along with its Fiber and the
 * Context its listener sees. JobPopped's listener adds the job's label to Context
 * as `popped`. A control file `throw-{EventName}` makes that event's listener throw.
 */

use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleased;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Queue\Events\WorkerQueuePaused;
use Illuminate\Queue\Events\WorkerQueueResumed;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
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
    'restart_probe.log' => $logFile,
]);

$write = static function (string $event, array $extra = []) use ($logFile): void {
    file_put_contents($logFile, json_encode(['event' => $event] + $extra, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
};

$events = app('events');

$events->listen(WorkerStarting::class, fn (WorkerStarting $e) => $write('WorkerStarting', [
    'queue' => $e->queue,
    'options' => $e->workerOptions instanceof WorkerOptions,
    'memory' => $e->workerOptions->memory,
    'force' => $e->workerOptions->force,
    'maxTries' => $e->workerOptions->maxTries,
]));
$events->listen(WorkerStopping::class, function (WorkerStopping $e) use ($write, $options): void {
    $write('WorkerStopping', [
        'status' => $e->status,
        'reason' => $e->reason?->value,
        'options' => $e->workerOptions instanceof WorkerOptions,
        'jobs' => $e->jobsProcessed,
        'memory' => $e->memoryUsage,
        'at' => microtime(true),
    ]);

    if (($options['stopping_delay'] ?? 0) > 0) {
        delay((float) $options['stopping_delay']);
        $write('WorkerStoppingDone');
    }
});
$events->listen(WorkerIdle::class, fn (WorkerIdle $e) => $write('WorkerIdle', ['options' => $e->workerOptions instanceof WorkerOptions]));
$events->listen(WorkerQueuePaused::class, fn (WorkerQueuePaused $e) => $write('WorkerQueuePaused', ['queue' => $e->queue]));
$events->listen(WorkerQueueResumed::class, fn (WorkerQueueResumed $e) => $write('WorkerQueueResumed', ['queue' => $e->queue]));

if ($options['job_events'] ?? false) {
    $jobEvents = [JobPopping::class, JobPopped::class, JobProcessing::class, JobProcessed::class, JobExceptionOccurred::class, JobReleased::class, JobReleasedAfterException::class, JobAttempted::class];

    foreach ($jobEvents as $class) {
        $events->listen($class, function (object $event) use ($class, $write, $cacheDir): void {
            $name = class_basename($class);
            $job = $event->job ?? null;
            $label = $job?->payload()['displayName'] ?? null;

            if ($event instanceof JobPopped) {
                Context::add('popped', $label);
            }

            $write($name, [
                'tag' => $label,
                'attempt' => $job?->attempts(),
                'fiber' => spl_object_id(Fiber::getCurrent()),
                'context' => Context::get('popped'),
                'connection' => $event->connectionName,
                'queue' => $event instanceof JobPopping ? $event->queue : $job?->getQueue(),
                'backoff' => $event instanceof JobReleasedAfterException ? $event->backoff : null,
                'exception' => ($event->exception ?? null)?->getMessage(),
            ]);

            if (file_exists("{$cacheDir}/throw-{$name}")) {
                throw new RuntimeException("{$name} listener failed");
            }
        });
    }
} else {
    $events->listen(JobProcessed::class, fn () => $write('JobProcessed'));
}

$events->listen(Looping::class, function () use ($cacheDir) {
    if (file_exists($cacheDir.'/looping-throw')) {
        throw new RuntimeException('looping listener failed');
    }

    return file_exists($cacheDir.'/looping-false') ? false : null;
});

(new WorkerProcess(array_replace_recursive(config('torque'), [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'serializer' => 'json',
    'queues' => [$queue],
    'streams' => [$queue => (array_key_exists('max_retries', $options) && $options['max_retries'] === null ? [] : ['max_retries' => (int) ($options['max_retries'] ?? 1)]) + ['retry_after' => 90, 'circuit_breaker' => $options['circuit_breaker'] ?? false]],
    'coroutines_per_worker' => (int) ($options['concurrency'] ?? 1),
    'block_for' => 100,
    'max_jobs_per_worker' => (int) ($options['max_jobs'] ?? 1000),
    'max_worker_lifetime' => (int) ($options['lifetime'] ?? 120),
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => (int) ($options['grace'] ?? 10),
    'memory_limit' => (int) ($options['memory_limit'] ?? 0),
    'force' => (bool) ($options['force'] ?? false),
])))->run();
