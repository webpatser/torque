<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess with JobAttempted listeners attached, appending one
 * JSON line per observation to a log file. Started by
 * tests/Integration/JobAttemptedTest.php; exits by itself once max jobs is hit.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, max jobs,
 * concurrency, max retries, drain grace seconds.
 */

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\Tests\Fixtures\DeferProbeJob;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\delay;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $redisUri, $prefix, $group, $queue, $logFile, $maxJobs, $concurrency, $maxRetries, $drainGrace] = $argv;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
    'attempt_probe.log' => $logFile,
]);

$log = static function (array $row) use ($logFile): void {
    file_put_contents($logFile, json_encode($row, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
};

$app->make(ExceptionHandler::class)->reportable(static function (RuntimeException $e) use ($log): bool {
    $log(['type' => 'reported', 'message' => $e->getMessage()]);

    return false;
});

Event::listen(JobAttempted::class, static function (JobAttempted $event) use ($log): void {
    $job = $event->job->payload()['displayName'];

    $log([
        'type' => 'attempted',
        'connection' => $event->connectionName,
        'job' => $job,
        'attempt' => $event->job->attempts(),
        'exception' => $event->exception === null ? null : $event->exception::class,
        'duration' => $event->duration,
        'successful' => $event->successful(),
        'released' => $event->job->isReleased(),
        'failed' => $event->job->hasFailed(),
    ]);

    DeferProbeJob::mark('ended:'.$job);
});

Queue::whenJobRunsLongerThan(1000, static function ($job, $duration) use ($log): void {
    $log(['type' => 'long', 'job' => $job->payload()['displayName'], 'duration' => $duration]);
});

// Registered last: it stops the listeners after it, but must not stop the slot.
Event::listen(JobAttempted::class, static function (JobAttempted $event): void {
    if ($event->job->payload()['displayName'] === 'probe:boom') {
        throw new RuntimeException('JobAttempted listener threw');
    }
});

// Ends the attempt before fire(), the way the crash guard does.
Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
    if ($event->job->payload()['displayName'] === 'probe:refused') {
        throw MaxAttemptsExceededException::forJob($event->job);
    }
});

// Holds a failing attempt inside its failure handling, past the handler's end.
Event::listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event): void {
    if ($event->job->payload()['displayName'] === 'defer:stuck-in-failure') {
        DeferProbeJob::mark('failing:stuck-in-failure');
        delay(60);
    }
});

(new WorkerProcess(array_replace_recursive(config('torque'), require __DIR__.'/baseline-limits.php', [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'serializer' => 'json',
    'queues' => [$queue],
    'streams' => [$queue => ['max_retries' => (int) $maxRetries, 'retry_after' => 90]],
    'coroutines_per_worker' => (int) $concurrency,
    'block_for' => 100,
    'max_jobs_per_worker' => (int) $maxJobs,
    'max_worker_lifetime' => 60,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => (int) $drainGrace,
    'intervals' => require __DIR__.'/fast-intervals.php',
])))->run();
