<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Support\Collection;
use Webpatser\Torque\Queue\StreamQueue;

/*
|--------------------------------------------------------------------------
| StreamQueue Inspection Tests
|--------------------------------------------------------------------------
|
| Cover the Laravel 13.8 inspection methods (allPendingJobs, allReservedJobs,
| allDelayedJobs) on the Redis Streams queue driver. These require a running
| Redis instance and use a unique key prefix per run.
|
*/

$testPrefix = 'torque-inspect-'.bin2hex(random_bytes(4)).':';

beforeEach(function () use ($testPrefix) {
    $redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');

    $this->testPrefix = $testPrefix;

    try {
        $this->streamQueue = new StreamQueue(
            redisUri: $redisUri,
            default: 'default',
            retryAfter: 90,
            blockFor: 100,
            prefix: $testPrefix,
            consumerGroup: 'inspect-group',
        );

        $this->streamQueue->setContainer(app());
        $this->streamQueue->setConnectionName('torque');

        $this->streamQueue->size();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

afterEach(function () use ($testPrefix) {
    if (! isset($this->streamQueue)) {
        return;
    }

    try {
        $redis = $this->streamQueue->getRedisClient();

        $cursor = '0';
        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $testPrefix.'*', 'COUNT', '100');
            $cursor = (string) $result[0];
            $keys = $result[1] ?? [];

            foreach ($keys as $key) {
                $redis->execute('DEL', (string) $key);
            }
        } while ($cursor !== '0');
    } catch (RedisException) {
        // Cleanup best-effort.
    }
});

// -------------------------------------------------------------------------
//  allPendingJobs
// -------------------------------------------------------------------------

it('returns an empty pending collection when no jobs have been pushed', function () {
    $jobs = $this->streamQueue->allPendingJobs();

    expect($jobs)->toBeInstanceOf(Collection::class);
    expect($jobs)->toBeEmpty();
});

it('lists pending jobs aggregated across multiple queue names', function () {
    (void) $this->streamQueue->pushRaw(
        json_encode(['uuid' => 'a-1', 'displayName' => 'JobA', 'attempts' => 0]),
        'default',
    );
    (void) $this->streamQueue->pushRaw(
        json_encode(['uuid' => 'b-1', 'displayName' => 'JobB', 'attempts' => 0]),
        'priority',
    );
    (void) $this->streamQueue->pushRaw(
        json_encode(['uuid' => 'b-2', 'displayName' => 'JobB', 'attempts' => 0]),
        'priority',
    );

    $pending = $this->streamQueue->allPendingJobs();

    expect($pending)->toHaveCount(3);
    expect($pending->every(fn (InspectedJob $job) => $job instanceof InspectedJob))->toBeTrue();

    $uuids = $pending->map(fn (InspectedJob $job) => $job->uuid)->sort()->values()->all();
    expect($uuids)->toBe(['a-1', 'b-1', 'b-2']);
});

it('moves a job from pending to reserved when it is popped', function () {
    (void) $this->streamQueue->pushRaw(
        json_encode(['uuid' => 'job-x', 'displayName' => 'JobX', 'attempts' => 0]),
    );

    expect($this->streamQueue->allPendingJobs())->toHaveCount(1);
    expect($this->streamQueue->allReservedJobs())->toHaveCount(0);

    $popped = $this->streamQueue->pop();
    expect($popped)->not->toBeNull();

    expect($this->streamQueue->allPendingJobs())->toHaveCount(0);
    expect($this->streamQueue->allReservedJobs())->toHaveCount(1);
    expect($this->streamQueue->allReservedJobs()->first()->uuid)->toBe('job-x');
});

// -------------------------------------------------------------------------
//  allReservedJobs
// -------------------------------------------------------------------------

it('returns an empty reserved collection when nothing is in the PEL', function () {
    $jobs = $this->streamQueue->allReservedJobs();

    expect($jobs)->toBeInstanceOf(Collection::class);
    expect($jobs)->toBeEmpty();
});

it('exposes XPENDING delivery count as the reserved job attempts', function () {
    (void) $this->streamQueue->pushRaw(
        json_encode(['uuid' => 'redeliver-me', 'displayName' => 'JobR', 'attempts' => 0]),
    );

    // Pop once. Delivery count = 1, message lands in the PEL.
    $first = $this->streamQueue->pop();
    expect($first)->not->toBeNull();

    $reservedFirstPass = $this->streamQueue->allReservedJobs();
    expect($reservedFirstPass)->toHaveCount(1);
    expect($reservedFirstPass->first()->attempts)->toBe(1);

    // Re-deliver via XCLAIM with the same consumer to bump the delivery count.
    $redis = $this->streamQueue->getRedisClient();
    $streamKey = $this->streamQueue->getStreamKey();
    $consumerId = $this->streamQueue->getConsumerId();
    $messageId = $first->messageId;

    $redis->execute(
        'XCLAIM',
        $streamKey,
        $this->streamQueue->getConsumerGroup(),
        $consumerId,
        '0',
        $messageId,
    );

    $reservedSecondPass = $this->streamQueue->allReservedJobs();
    expect($reservedSecondPass)->toHaveCount(1);
    expect($reservedSecondPass->first()->attempts)->toBe(2);
    expect($reservedSecondPass->first()->uuid)->toBe('redeliver-me');
});

it('aggregates reserved jobs across multiple queue names', function () {
    (void) $this->streamQueue->pushRaw(
        json_encode(['uuid' => 'd-1', 'displayName' => 'JobD', 'attempts' => 0]),
        'default',
    );
    (void) $this->streamQueue->pushRaw(
        json_encode(['uuid' => 'p-1', 'displayName' => 'JobP', 'attempts' => 0]),
        'priority',
    );

    expect($this->streamQueue->pop('default'))->not->toBeNull();
    expect($this->streamQueue->pop('priority'))->not->toBeNull();

    $reserved = $this->streamQueue->allReservedJobs();
    expect($reserved)->toHaveCount(2);

    $uuids = $reserved->map(fn (InspectedJob $job) => $job->uuid)->sort()->values()->all();
    expect($uuids)->toBe(['d-1', 'p-1']);
});

// -------------------------------------------------------------------------
//  allDelayedJobs
// -------------------------------------------------------------------------

it('returns an empty delayed collection when nothing is delayed', function () {
    $jobs = $this->streamQueue->allDelayedJobs();

    expect($jobs)->toBeInstanceOf(Collection::class);
    expect($jobs)->toBeEmpty();
});

it('lists delayed jobs aggregated across multiple queue names', function () {
    (void) $this->streamQueue->later(60, 'JobA', '', 'default');
    (void) $this->streamQueue->later(120, 'JobB', '', 'priority');
    (void) $this->streamQueue->later(180, 'JobC', '', 'priority');

    $delayed = $this->streamQueue->allDelayedJobs();

    expect($delayed)->toHaveCount(3);
    expect($delayed->every(fn (InspectedJob $job) => $job instanceof InspectedJob))->toBeTrue();
});

// -------------------------------------------------------------------------
//  Queue enumeration filters auxiliary keys
// -------------------------------------------------------------------------

it('excludes auxiliary keys (paused, :delayed) from queue enumeration', function () {
    (void) $this->streamQueue->pushRaw(
        json_encode(['uuid' => 'real-1', 'displayName' => 'JobR', 'attempts' => 0]),
        'default',
    );

    // Inject auxiliary keys that should be ignored by allQueueNames().
    $redis = $this->streamQueue->getRedisClient();
    $redis->execute('SET', $this->testPrefix.'paused', '1');
    (void) $this->streamQueue->later(60, 'JobLater', '', 'default'); // creates :delayed

    $pending = $this->streamQueue->allPendingJobs();

    expect($pending)->toHaveCount(1);
    expect($pending->first()->uuid)->toBe('real-1');
});
