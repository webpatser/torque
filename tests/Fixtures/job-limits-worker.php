<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess, appending one JSON line per observation to a log
 * file. Started by tests/Integration/JobLimitsTest.php; exits by itself once max
 * jobs or its lifetime is hit.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, and a JSON
 * object with the stream config and worker options under test.
 *
 * A delayed release keeps its real score long enough to be logged, and is then
 * moved to the front of the delayed set, so tests see the real delay without
 * waiting for it. The worker's one-second migration timer still has to pick it up.
 */

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Event;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\Redis\createRedisClient;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $redisUri, $prefix, $group, $queue, $logFile, $settingsJson] = $argv;

$settings = json_decode($settingsJson, true, flags: JSON_THROW_ON_ERROR);

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
    'job_limits_probe' => ['log' => $logFile],
]);

$log = static function (array $row) use ($logFile): void {
    file_put_contents($logFile, json_encode($row, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
};

$app->make(ExceptionHandler::class)->reportable(static fn (Throwable $e): bool => false);

Event::listen(JobFailed::class, static function (JobFailed $event) use ($log): void {
    $log(['type' => 'failed', 'exception' => $event->exception::class]);
});

$delayedKey = $prefix.$queue.':delayed';
$redis = null;

Event::listen(JobReleasedAfterException::class, static function (JobReleasedAfterException $event) use ($log, $redisUri, $delayedKey, &$redis): void {
    $redis ??= createRedisClient($redisUri);
    $delayedFor = null;

    foreach ($redis->execute('ZRANGE', $delayedKey, '0', '-1') ?? [] as $member) {
        $delayedFor = (int) $redis->execute('ZSCORE', $delayedKey, $member) - time();
        $redis->execute('ZADD', $delayedKey, '0', $member);
    }

    $log(['type' => 'released', 'backoff' => $event->backoff, 'delayed_for' => $delayedFor]);
});

(new WorkerProcess(array_replace_recursive(config('torque'), [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'queues' => [$queue],
    'streams' => [$queue => $settings['stream'] + ['retry_after' => 90]],
    'coroutines_per_worker' => 1,
    'block_for' => 100,
    'max_jobs_per_worker' => $settings['max_jobs'],
    'max_worker_lifetime' => $settings['lifetime'] ?? 8,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => 1,
    'intervals' => require __DIR__.'/fast-intervals.php',
], array_intersect_key($settings, ['job_limits' => true, 'strict_attempts' => true]))))->run();
