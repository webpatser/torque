<?php

declare(strict_types=1);

use Webpatser\Torque\Stream\JobStreamRecorder;

it('does not record when disabled', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-disabled-' . bin2hex(random_bytes(8));
    $key = 'torque-test:job:' . $uuid;

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
        enabled: false,
    );

    $recorder->record($uuid, 'queued', ['queue' => 'default']);

    expect((int) $redis->execute('EXISTS', $key))->toBe(0);
});

it('does not record with empty uuid', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->record('', 'queued', ['queue' => 'default']);

    // No key should exist for empty uuid.
    expect((int) $redis->execute('EXISTS', 'torque-test:job:'))->toBe(0);
});

it('records a queued event to redis stream', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-' . bin2hex(random_bytes(8));
    $key = 'torque-test:job:' . $uuid;

    // Clean up before test.
    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->onQueued($uuid, 'scrpr', 'App\\Jobs\\ScrapeKvK');

    $entries = $redis->execute('XRANGE', $key, '-', '+');

    expect($entries)->toBeArray()->not->toBeEmpty();

    $fields = [];
    for ($i = 0, $c = count($entries[0][1]); $i < $c; $i += 2) {
        $fields[(string) $entries[0][1][$i]] = (string) $entries[0][1][$i + 1];
    }

    expect($fields['type'])->toBe('queued');
    expect($fields['queue'])->toBe('scrpr');
    expect($fields['displayName'])->toBe('App\\Jobs\\ScrapeKvK');
    expect($fields)->toHaveKey('timestamp');

    // Clean up.
    $redis->execute('DEL', $key);
});

it('records custom events via emitCustom', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-' . bin2hex(random_bytes(8));
    $key = 'torque-test:job:' . $uuid;

    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->emitCustom($uuid, 'Processing item 5/10', progress: 0.5, data: ['item_id' => '42']);

    $entries = $redis->execute('XRANGE', $key, '-', '+');

    expect($entries)->toHaveCount(1);

    $fields = [];
    for ($i = 0, $c = count($entries[0][1]); $i < $c; $i += 2) {
        $fields[(string) $entries[0][1][$i]] = (string) $entries[0][1][$i + 1];
    }

    expect($fields['type'])->toBe('progress');
    expect($fields['message'])->toBe('Processing item 5/10');
    expect($fields['progress'])->toBe('0.5');
    expect($fields['item_id'])->toBe('42');

    $redis->execute('DEL', $key);
});

it('sets TTL on terminal events', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-' . bin2hex(random_bytes(8));
    $key = 'torque-test:job:' . $uuid;

    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
        ttl: 60,
    );

    // Non-terminal event — no TTL.
    $recorder->record($uuid, 'started', ['worker' => 'test']);
    $ttl = (int) $redis->execute('TTL', $key);
    expect($ttl)->toBe(-1); // No expiry.

    // Terminal event — TTL set.
    $recorder->record($uuid, 'completed', ['duration_ms' => '100'], terminal: true);
    $ttl = (int) $redis->execute('TTL', $key);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $redis->execute('DEL', $key);
});

it('records multiple lifecycle events in order', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-' . bin2hex(random_bytes(8));
    $key = 'torque-test:job:' . $uuid;

    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->record($uuid, 'queued', ['queue' => 'default']);
    $recorder->record($uuid, 'started', ['worker' => 'w1']);
    $recorder->record($uuid, 'completed', ['duration_ms' => '250'], terminal: true);

    $entries = $redis->execute('XRANGE', $key, '-', '+');

    expect($entries)->toHaveCount(3);

    $types = [];
    foreach ($entries as $entry) {
        for ($i = 0, $c = count($entry[1]); $i < $c; $i += 2) {
            if ((string) $entry[1][$i] === 'type') {
                $types[] = (string) $entry[1][$i + 1];
            }
        }
    }

    expect($types)->toBe(['queued', 'started', 'completed']);

    $redis->execute('DEL', $key);
});
