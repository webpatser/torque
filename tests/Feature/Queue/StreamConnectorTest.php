<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamConnector;
use Webpatser\Torque\Queue\StreamQueue;

it('returns a StreamQueue instance', function () {
    $connector = new StreamConnector();

    $queue = $connector->connect([
        'redis_uri' => 'redis://127.0.0.1:6379',
    ]);

    expect($queue)->toBeInstanceOf(StreamQueue::class);
});

it('passes config values through to the queue', function () {
    // Temporarily clear torque config so connect() params take precedence.
    config()->set('torque.redis.prefix', null);
    config()->set('torque.consumer_group', null);

    $connector = new StreamConnector();

    $queue = $connector->connect([
        'redis_uri' => 'redis://10.0.0.1:6380',
        'queue' => 'high-priority',
        'retry_after' => 300,
        'block_for' => 5000,
        'prefix' => 'myapp:',
        'consumer_group' => 'workers',
    ]);

    expect($queue->getQueue())->toBe('high-priority');
    expect($queue->getStreamKey())->toBe('myapp:high-priority');
    expect($queue->getRetryAfter())->toBe(300);
    expect($queue->getConsumerGroup())->toBe('workers');
});

it('uses torque config as source of truth for prefix and consumer group', function () {
    config()->set('torque.redis.prefix', 'torque-test:');
    config()->set('torque.consumer_group', 'torque-test');

    $connector = new StreamConnector();

    $queue = $connector->connect([
        'prefix' => 'ignored:',
        'consumer_group' => 'ignored',
    ]);

    expect($queue->getStreamKey())->toBe('torque-test:default');
    expect($queue->getConsumerGroup())->toBe('torque-test');
});

it('uses defaults for missing config keys', function () {
    config()->set('torque.redis.prefix', null);
    config()->set('torque.consumer_group', null);

    $connector = new StreamConnector();

    $queue = $connector->connect([]);

    expect($queue->getQueue())->toBe('default');
    expect($queue->getStreamKey())->toBe('torque:default');
    expect($queue->getRetryAfter())->toBe(90);
    expect($queue->getConsumerGroup())->toBe('torque');
    expect($queue->getConsumerId())->toContain(gethostname());
});

it('casts retry_after and block_for to integers', function () {
    $connector = new StreamConnector();

    $queue = $connector->connect([
        'retry_after' => '120',
        'block_for' => '3000',
    ]);

    expect($queue->getRetryAfter())->toBe(120);
});
