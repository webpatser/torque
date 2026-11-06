<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess with the package's own JobStreamRecorder listeners,
 * pointed at a test prefix through the environment. Started by
 * tests/Integration/JobTagsWorkerTest.php; exits once max jobs is hit.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, max jobs.
 */

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/TagFixtures.php';

[, $redisUri, $prefix, $group, $queue, $maxJobs] = $argv;

// The recorder is built when the provider boots, so the test keyspace must be
// in the environment before the application exists.
foreach (['TORQUE_REDIS_URI' => $redisUri, 'TORQUE_PREFIX' => $prefix] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
]);

(new WorkerProcess(array_replace_recursive(config('torque'), require __DIR__.'/baseline-limits.php', [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'serializer' => 'json',
    'queues' => [$queue],
    'streams' => [$queue => ['max_retries' => 1, 'retry_after' => 90]],
    'coroutines_per_worker' => 2,
    'block_for' => 100,
    'max_jobs_per_worker' => (int) $maxJobs,
    'max_worker_lifetime' => 60,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => 5,
    'intervals' => require __DIR__.'/fast-intervals.php',
])))->run();
