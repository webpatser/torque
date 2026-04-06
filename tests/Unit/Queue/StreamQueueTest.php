<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;

it('resolves queue names correctly', function () {
    $queue = new StreamQueue(
        redisUri: 'redis://127.0.0.1:6379',
        default: 'high',
        prefix: 'app:',
    );

    expect($queue->getQueue())->toBe('high');
    expect($queue->getQueue('low'))->toBe('low');
    expect($queue->getStreamKey())->toBe('app:high');
    expect($queue->getStreamKey('low'))->toBe('app:low');
});

it('uses default queue name', function () {
    $queue = new StreamQueue(
        redisUri: 'redis://127.0.0.1:6379',
        default: 'default',
        prefix: 'torque:',
    );

    expect($queue->getQueue())->toBe('default');
    expect($queue->getStreamKey())->toBe('torque:default');
    expect($queue->getConsumerGroup())->toBe('torque');
    expect($queue->getConsumerId())->toContain(gethostname());
    expect($queue->getRetryAfter())->toBe(90);
});

it('uses custom consumer group and retry settings', function () {
    $queue = new StreamQueue(
        redisUri: 'redis://127.0.0.1:6379',
        consumerGroup: 'my-group',
        retryAfter: 120,
    );

    expect($queue->getConsumerGroup())->toBe('my-group');
    expect($queue->getRetryAfter())->toBe(120);
});
