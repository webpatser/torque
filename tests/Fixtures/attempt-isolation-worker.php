<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess whose IsolationProbeJob jobs append one JSON line per
 * observation to a log file. Started by tests/Integration/AttemptIsolationTest.php;
 * exits by itself once max jobs is hit.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, max jobs,
 * concurrency, whether the reset suspends (1 or 0).
 */

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobInterrupted;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\LivewireServiceProvider;
use Monolog\Formatter\JsonFormatter;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\Tests\Fixtures\ScopedProbe;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\delay;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $redisUri, $prefix, $group, $queue, $logFile, $maxJobs, $concurrency, $slowReset] = $argv;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

// A job's dispatch() resolves the torque connection through these, so it has to
// reach the same Redis and prefix as the worker.
config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
    'queue.default' => 'torque',
    'queue.connections.torque' => ['driver' => 'torque', 'queue' => $queue],
    'attempt_isolation.log' => $logFile,
    'torque.redis.uri' => $redisUri,
    'torque.redis.prefix' => $prefix,
    'torque.redis.cluster' => false,
    'torque.consumer_group' => $group,
    'torque.serializer' => 'json',
    'logging.default' => 'records',
    'logging.channels.records' => ['driver' => 'single', 'name' => 'records', 'path' => "{$logFile}.records", 'formatter' => JsonFormatter::class],
    'logging.channels.other' => ['driver' => 'single', 'name' => 'other', 'path' => "{$logFile}.records", 'formatter' => JsonFormatter::class],
]);

// Boot-time values, through the framework's own managers: the worker has not
// installed anything yet.
Log::withContext(['boot_context' => 'yes']);
Log::shareContext(['boot_shared' => 'yes']);
Auth::setUser(new GenericUser(['id' => 'boot']));

// Written where a signal callback's ambient scope sees it, so a checkpoint job
// that carries ambient Context shows it.
Event::listen(WorkerInterrupted::class, static function (): void {
    Context::add('ambient', 'signal');
});

Event::listen(JobInterrupted::class, static function () use ($logFile): void {
    file_put_contents($logFile, json_encode(['type' => 'job-interrupted-event', 'job' => 'listener', 'tenant' => Context::get('tenant')], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
});

$app->scoped(ScopedProbe::class);
$app->scoped('isolation.named-binding', static fn (): ScopedProbe => new ScopedProbe);

// A reset that suspends, as an app's own reset closure may, around stock's.
if ($slowReset === '1') {
    $stock = (new ReflectionProperty(Worker::class, 'resetScope'))->getValue($app->make('queue.worker'));

    $logReset = static function (string $type) use ($logFile): void {
        file_put_contents($logFile, json_encode(['type' => $type, 'job' => 'worker'], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    };

    $app->singleton('queue.worker', static fn ($app): Worker => new Worker(
        $app['queue'],
        $app['events'],
        $app[ExceptionHandler::class],
        static fn (): bool => false,
        static function () use ($stock, $logReset): void {
            $logReset('reset-start');
            delay(1.5);
            $stock();
            $logReset('reset-end');
        },
    ));
}

(new WorkerProcess(array_replace_recursive(config('torque'), require __DIR__.'/baseline-limits.php', [
    'queues' => [$queue],
    'streams' => [$queue => ['max_retries' => 1, 'retry_after' => 90]],
    'slots_per_worker' => (int) $concurrency,
    'poll_interval_ms' => 100,
    'max_jobs_per_worker' => (int) $maxJobs,
    'max_worker_lifetime' => 60,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => 10,
    'intervals' => require __DIR__.'/fast-intervals.php',
])))->run();
