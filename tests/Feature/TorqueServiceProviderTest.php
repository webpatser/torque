<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Foundation\DevCommands;
use Illuminate\Queue\QueueManager;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Torque;

it('merges the torque config', function () {
    $config = config('torque');

    expect($config)->toBeArray()
        ->and($config)->toHaveKeys([
            'workers',
            'coroutines_per_worker',
            'redis',
            'consumer_group',
            'streams',
            'dead_letter',
            'dashboard',
        ]);
});

function shippedStreams(array $env): array
{
    $keys = ['TORQUE_QUEUE', 'TORQUE_RETRY_AFTER', 'TORQUE_MAX_RETRIES', 'TORQUE_BACKOFF', 'TORQUE_STREAM_TIMEOUT'];
    $previous = array_map(fn (string $key) => getenv($key), $keys);

    foreach ($keys as $key) {
        putenv($env[$key] ?? null ? "{$key}={$env[$key]}" : $key);
    }

    try {
        return (require dirname(__DIR__, 2).'/config/torque.php')['streams'];
    } finally {
        foreach ($keys as $i => $key) {
            putenv($previous[$i] === false ? $key : "{$key}={$previous[$i]}");
        }
    }
}

it('keeps the literal default stream when no stream env vars are set', function () {
    $streams = shippedStreams([]);

    expect($streams)->toBe(['default' => [
        'priority' => 0,
        'retry_after' => 60,
        'max_retries' => 3,
        'backoff' => 'exponential',
        'timeout' => null,
    ]]);
});

it('reads the stream env vars into the default stream', function () {
    $streams = shippedStreams([
        'TORQUE_RETRY_AFTER' => '90',
        'TORQUE_MAX_RETRIES' => '5',
        'TORQUE_BACKOFF' => '1,5,10',
        'TORQUE_STREAM_TIMEOUT' => '45',
    ]);

    expect($streams['default'])->toMatchArray([
        'retry_after' => 90,
        'max_retries' => 5,
        'backoff' => '1,5,10',
        'timeout' => 45,
    ]);
});

it('keys the shipped stream by TORQUE_QUEUE without leaving a default key', function () {
    $streams = shippedStreams(['TORQUE_QUEUE' => 'emails']);

    expect(array_keys($streams))->toBe(['emails'])
        ->and($streams)->not->toHaveKey('default');
});

it('registers the torque queue connector', function () {
    /** @var QueueManager $manager */
    $manager = app('queue');

    // The connector is registered — attempting to resolve it should not throw
    // an "unsupported driver" exception. It will throw a Redis connection error
    // instead, which proves the connector itself is wired up.
    try {
        $manager->connection('torque');
    } catch (RedisException $e) {
        // Expected — Redis isn't running in CI. The connector resolved.
        expect(true)->toBeTrue();

        return;
    }

    // If Redis IS available, we get a StreamQueue instance.
    expect($manager->connection('torque'))->toBeInstanceOf(
        StreamQueue::class,
    );
});

it('registers MetricsPublisher as a singleton', function () {
    try {
        $instanceA = app(MetricsPublisher::class);
        $instanceB = app(MetricsPublisher::class);

        expect($instanceA)->toBe($instanceB);
    } catch (RedisException $e) {
        // If Redis connect happens eagerly, the binding still exists.
        expect(app()->bound(MetricsPublisher::class))->toBeTrue();
    }
});

it('registers DeadLetterHandler as a singleton', function () {
    try {
        $instanceA = app(DeadLetterHandler::class);
        $instanceB = app(DeadLetterHandler::class);

        expect($instanceA)->toBe($instanceB);
    } catch (RedisException $e) {
        expect(app()->bound(DeadLetterHandler::class))->toBeTrue();
    }
});

it('registers artisan commands', function (string $command) {
    $commands = Artisan::all();

    expect($commands)->toHaveKey($command);
})->with([
    'torque:start',
    'torque:stop',
    'torque:status',
    'torque:pause',
    'torque:supervisor',
]);

it('registers torque:start as a dev command', function () {
    $commands = collect(DevCommands::commands());

    $torque = $commands->firstWhere('name', 'torque');

    expect($torque)->not->toBeNull()
        ->and($torque['command'])->toBe('php artisan torque:start');
});

it('excludes the default queue dev command so it cannot compete for jobs', function () {
    DevCommands::artisan('queue:listen --tries=1', 'queue');

    Torque::registerDevCommands();

    $commands = collect(DevCommands::commands());

    expect($commands->firstWhere('name', 'queue'))->toBeNull()
        ->and($commands->firstWhere('name', 'torque'))->not->toBeNull();
});
