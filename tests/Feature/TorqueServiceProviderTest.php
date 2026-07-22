<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Foundation\DevCommands;
use Illuminate\Queue\QueueManager;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Queue\StreamQueue;

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
