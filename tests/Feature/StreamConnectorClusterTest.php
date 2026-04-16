<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamConnector;

it('passes cluster flag from torque config to StreamQueue', function () {
    config()->set('torque.redis.cluster', true);
    config()->set('torque.redis.prefix', 'test:');

    $connector = new StreamConnector();
    $queue = $connector->connect([
        'redis_uri' => 'redis://127.0.0.1:6379',
    ]);

    expect($queue->getStreamKey('default'))->toBe('test:{default}');
});

it('defaults cluster to false when not configured', function () {
    config()->set('torque.redis.cluster', null);
    config()->set('torque.redis.prefix', 'test:');

    $connector = new StreamConnector();
    $queue = $connector->connect([
        'redis_uri' => 'redis://127.0.0.1:6379',
    ]);

    expect($queue->getStreamKey('default'))->toBe('test:default');
});
