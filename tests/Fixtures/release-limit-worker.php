<?php

declare(strict_types=1);

/*
 * Runs a real WorkerProcess for tests/Integration/ReleaseLimitTest.php, with the
 * real Laravel middleware in front of the probe job. One JSON line per observation
 * goes to a log file (see ReleaseLimitProbeJob).
 *
 * Arguments: redis uri, key prefix, consumer group, queue, log file, and a JSON
 * object with the settings under test (probe, release_limit_seconds, lifetime).
 *
 * The middleware release a job for seconds, so a timer pulls every delayed entry to
 * the front of the delayed set: the release limit runs on real time, the delays do
 * not have to. A second timer ends the worker once a dead letter exists, so a job
 * that did not stop shows up as a worker that ran to its lifetime.
 */

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Revolt\EventLoop;
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
    // StreamQueue reads the release limit from here, not from the worker's options.
    "torque.streams.{$queue}" => $settings['stream'],
    'torque.release_limit_seconds' => $settings['release_limit_seconds'],
]);

// What the middleware reacts to. The array cache lives in this process, which is the worker's.
RateLimiter::for('release-limit-probe', static fn (): Limit => Limit::perMinute(1, 10)->by('k'));

if ($settings['probe'] === 'rate_limit') {
    RateLimiter::hit(md5('release-limit-probe'.'k'), 600);
}

if ($settings['probe'] === 'overlap') {
    $held = Cache::lock('rl:probe', 600);

    if (! $held->get()) {
        throw new RuntimeException('Could not take the lock.');
    }
}

$redis = createRedisClient($redisUri);
$delayedKey = $prefix.$queue.':delayed';
$stopping = false;

EventLoop::repeat(0.1, static function () use ($redis, $delayedKey, $prefix, &$stopping): void {
    foreach ($redis->execute('ZRANGE', $delayedKey, '0', '-1') ?? [] as $member) {
        // XX: the worker may have migrated the member since the ZRANGE, and re-adding it would duplicate the job.
        $redis->execute('ZADD', $delayedKey, 'XX', '0', $member);
    }

    if (! $stopping && (int) $redis->execute('EXISTS', $prefix.'dead-letter') === 1) {
        $stopping = true;
        posix_kill(getmypid(), SIGTERM);
    }
});

(new WorkerProcess(array_replace_recursive(config('torque'), require __DIR__.'/baseline-limits.php', [
    'redis' => ['uri' => $redisUri, 'prefix' => $prefix, 'cluster' => false],
    'consumer_group' => $group,
    'queues' => [$queue],
    'streams' => [$queue => $settings['stream'] + ['retry_after' => 90]],
    'coroutines_per_worker' => 1,
    'block_for' => 100,
    'max_jobs_per_worker' => 100000,
    'max_worker_lifetime' => $settings['lifetime'],
    'max_worker_lifetime_jitter' => 0,
    'drain_grace_seconds' => 1,
    'strict_attempts' => true,
    'intervals' => require __DIR__.'/fast-intervals.php',
])))->run();
