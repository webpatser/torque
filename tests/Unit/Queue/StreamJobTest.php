<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;

beforeEach(function () {
    $this->container = new Container();
    $this->streamQueue = Mockery::mock(StreamQueue::class);
});

function makeJob(
    Container $container,
    mixed $streamQueue,
    ?string $payload = null,
    string $messageId = '1680000000000-0',
    string $connectionName = 'torque',
    string $queue = 'default',
): StreamJob {
    $payload ??= json_encode([
        'uuid' => 'test-uuid-123',
        'displayName' => 'App\\Jobs\\TestJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'App\\Jobs\\TestJob', 'command' => 'serialized'],
        'attempts' => 0,
    ]);

    return new StreamJob(
        container: $container,
        streamQueue: $streamQueue,
        rawBody: $payload,
        messageId: $messageId,
        connectionName: $connectionName,
        queue: $queue,
    );
}

it('returns the UUID from payload as job ID', function () {
    $job = makeJob($this->container, $this->streamQueue);

    expect($job->getJobId())->toBe('test-uuid-123');
});

it('falls back to message ID when UUID is missing', function () {
    $payload = json_encode(['displayName' => 'TestJob', 'attempts' => 0]);

    $job = makeJob($this->container, $this->streamQueue, payload: $payload);

    expect($job->getJobId())->toBe('1680000000000-0');
});

it('returns raw body', function () {
    $payload = json_encode(['uuid' => 'abc', 'attempts' => 0]);
    $job = makeJob($this->container, $this->streamQueue, payload: $payload);

    expect($job->getRawBody())->toBe($payload);
});

it('calculates attempts correctly', function () {
    $payload = json_encode(['uuid' => 'abc', 'attempts' => 2]);
    $job = makeJob($this->container, $this->streamQueue, payload: $payload);

    // attempts in payload is 2, +1 for current = 3
    expect($job->attempts())->toBe(3);
});

it('treats missing attempts as first attempt', function () {
    $payload = json_encode(['uuid' => 'abc']);
    $job = makeJob($this->container, $this->streamQueue, payload: $payload);

    expect($job->attempts())->toBe(1);
});

it('calls deleteAndAcknowledge on delete', function () {
    $this->streamQueue
        ->shouldReceive('deleteAndAcknowledge')
        ->once()
        ->with('default', '1680000000000-0');

    $job = makeJob($this->container, $this->streamQueue);
    $job->delete();

    expect($job->isDeleted())->toBeTrue();
});

it('calls release on the stream queue', function () {
    $this->streamQueue
        ->shouldReceive('release')
        ->once()
        ->with('default', Mockery::type(StreamJob::class), 30);

    $job = makeJob($this->container, $this->streamQueue);
    $job->release(30);

    expect($job->isReleased())->toBeTrue();
});

it('exposes message ID via getter and property', function () {
    $job = makeJob($this->container, $this->streamQueue, messageId: '9999-1');

    expect($job->getMessageId())->toBe('9999-1');
    expect($job->messageId)->toBe('9999-1');
});

it('exposes stream queue via property', function () {
    $job = makeJob($this->container, $this->streamQueue);

    expect($job->streamQueue)->toBe($this->streamQueue);
});
