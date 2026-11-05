<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess, appending one JSON line per observation to a log
 * file. Started by tests/Integration/JobExceptionTest.php; exits by itself once
 * max jobs or its lifetime is hit.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, max jobs,
 * concurrency, max retries, lifetime seconds, listener to break (or "none"; also
 * "failer", "reset", or "no-report" for a broken failer with reporting off),
 * serializer.
 */

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\Events\JobPermanentlyFailed;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $redisUri, $prefix, $group, $queue, $logFile, $maxJobs, $concurrency, $maxRetries, $lifetime, $brokenListener, $serializer] = $argv;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
    'torque.serializer' => $serializer,
    'job_exception_probe' => ['log' => $logFile, 'uri' => $redisUri, 'prefix' => $prefix, 'group' => $group],
]);

$log = static function (array $row) use ($logFile): void {
    file_put_contents($logFile, json_encode($row, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
};

$app->make(ExceptionHandler::class)->reportable(static function (Throwable $e) use ($log): bool {
    $log(['type' => 'reported', 'message' => $e->getMessage(), 'tenant' => Context::get('tenant')]);

    return false;
});

Event::listen(JobFailed::class, static function (JobFailed $event) use ($log): void {
    $log(['type' => 'failed', 'job' => $event->job->payload()['displayName']]);
});

$listeners = [
    'looping' => Looping::class,
    'exception-occurred' => JobExceptionOccurred::class,
    'permanently-failed' => JobPermanentlyFailed::class,
];

if (isset($listeners[$brokenListener])) {
    Event::listen($listeners[$brokenListener], static function () use ($brokenListener): void {
        throw new RuntimeException("{$brokenListener} listener threw");
    });
}

if ($brokenListener === 'no-report') {
    Worker::$reportJobExceptions = false;
}

if (in_array($brokenListener, ['failer', 'no-report'], true)) {
    $app->instance('queue.failer', new class
    {
        public function log(mixed ...$arguments): never
        {
            throw new RuntimeException('failer write threw');
        }
    });
}

if ($brokenListener === 'reset') {
    $app->singleton('queue.worker', static fn ($app): Worker => new Worker(
        $app['queue'],
        $app['events'],
        $app[ExceptionHandler::class],
        static fn (): bool => false,
        static function () use ($log): void {
            $log(['type' => 'reset-attempt']);

            throw new RuntimeException('reset threw');
        },
    ));
}

(new WorkerProcess(array_replace_recursive(config('torque'), [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'serializer' => $serializer,
    'queues' => [$queue],
    'streams' => [$queue => ['max_retries' => (int) $maxRetries, 'retry_after' => 90]],
    'coroutines_per_worker' => (int) $concurrency,
    'block_for' => 100,
    'max_jobs_per_worker' => (int) $maxJobs,
    'max_worker_lifetime' => (int) $lifetime,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => 1,
    'intervals' => require __DIR__.'/fast-intervals.php',
])))->run();
