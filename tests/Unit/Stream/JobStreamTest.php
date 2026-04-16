<?php

declare(strict_types=1);

use Webpatser\Torque\Stream\JobStream;
use Webpatser\Torque\Stream\JobStreamRecorder;

beforeEach(function () {
    $this->redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $this->uuid = 'test-' . bin2hex(random_bytes(8));
    $this->key = 'torque-test:job:' . $this->uuid;
    $this->redis->execute('DEL', $this->key);
});

afterEach(function () {
    $this->redis->execute('DEL', $this->key);
});

it('returns empty array when no events exist', function () {
    $stream = new JobStream(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    expect($stream->events($this->uuid))->toBe([]);
});

it('reads all recorded events', function () {
    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->record($this->uuid, 'queued', ['queue' => 'scrpr']);
    $recorder->record($this->uuid, 'started', ['worker' => 'w1']);
    $recorder->record($this->uuid, 'completed', [], terminal: true);

    $stream = new JobStream(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $events = $stream->events($this->uuid);

    expect($events)->toHaveCount(3);
    expect($events[0]['type'])->toBe('queued');
    expect($events[0]['data']['queue'])->toBe('scrpr');
    expect($events[1]['type'])->toBe('started');
    expect($events[2]['type'])->toBe('completed');

    // Each event has an id.
    expect($events[0]['id'])->toContain('-');
});

it('reports finished for completed jobs', function () {
    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $stream = new JobStream(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->record($this->uuid, 'queued', []);
    expect($stream->isFinished($this->uuid))->toBeFalse();

    $recorder->record($this->uuid, 'started', []);
    expect($stream->isFinished($this->uuid))->toBeFalse();

    $recorder->record($this->uuid, 'completed', [], terminal: true);
    expect($stream->isFinished($this->uuid))->toBeTrue();
});

it('reports finished for failed jobs', function () {
    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $stream = new JobStream(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->record($this->uuid, 'queued', []);
    $recorder->record($this->uuid, 'failed', ['exception_class' => 'RuntimeException'], terminal: true);

    expect($stream->isFinished($this->uuid))->toBeTrue();
});

it('reports not finished for nonexistent jobs', function () {
    $stream = new JobStream(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    expect($stream->isFinished('nonexistent-uuid'))->toBeFalse();
});

it('tails events and stops on terminal event', function () {
    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    // Pre-populate events.
    $recorder->record($this->uuid, 'queued', ['queue' => 'default']);
    $recorder->record($this->uuid, 'started', ['worker' => 'w1']);
    $recorder->record($this->uuid, 'completed', [], terminal: true);

    $stream = new JobStream(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $collected = [];
    foreach ($stream->tail($this->uuid, timeout: 5.0) as $event) {
        $collected[] = $event['type'];
    }

    expect($collected)->toBe(['queued', 'started', 'completed']);
});
