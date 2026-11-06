<?php

declare(strict_types=1);

/*
 * Boots a real WorkerProcess on the merged package config alone: baseline-limits.php
 * is deliberately NOT merged. Started by tests/Integration/DefaultsTest.php.
 *
 * Arguments: redis uri, key prefix, consumer group, queue, status file.
 *
 * Writes the effective settings the worker resolved to the status file as JSON,
 * then runs for about a second on an empty queue. Boot WARN lines go to stderr.
 */

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $redisUri, $prefix, $group, $queue, $statusFile] = $argv;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
]);

$worker = new WorkerProcess(array_replace_recursive(config('torque'), [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'queues' => [$queue],
    'streams' => [$queue => ['retry_after' => 90]],
    'slots_per_worker' => 1,
    'poll_interval_ms' => 100,
    'max_worker_lifetime' => 1,
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => 1,
]));

$call = static function (string $method) use ($worker): bool {
    $reflection = new ReflectionMethod($worker, $method);

    return (bool) $reflection->invoke($worker);
};

file_put_contents($statusFile, json_encode([
    'strict_attempts' => $call('enforcesStrictAttempts'),
    'timeouts' => $call('timeoutsEnabled'),
    'memory_limit' => config('torque.memory_limit'),
], JSON_THROW_ON_ERROR));

$worker->run();
