<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\AttemptProbeJob;

/*
|--------------------------------------------------------------------------
| StreamQueue::release() Tests
|--------------------------------------------------------------------------
|
| A release must write the new copy before it acknowledges the old one, in one
| script, or a failed write loses the job. Under Redis OOM with noeviction this
| is deterministic: XACK and XDEL are allowed there, XADD and ZADD are not.
|
| The failures are forced per key so the shared server's config stays untouched:
| the delayed key pre-created as a string makes ZADD return WRONGTYPE, and a
| stream whose last id is at the maximum makes XADD refuse to add.
|
| Requires a running Redis instance.
|
*/

const TORQUE_RELEASE_GROUP = 'release-group';
const TORQUE_RELEASE_QUEUE = 'releasing';
const TORQUE_RELEASE_MAX_STREAM_ID = '18446744073709551615-18446744073709551615';

function torque_release_queue(string $prefix, bool $cluster): StreamQueue
{
    $queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: TORQUE_RELEASE_QUEUE,
        retryAfter: 90,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: TORQUE_RELEASE_GROUP,
        cluster: $cluster,
    );
    $queue->setContainer(app());
    $queue->setConnectionName('torque');
    $queue->ensureConsumerGroup($queue->getStreamKey(), TORQUE_RELEASE_GROUP);

    return $queue;
}

/**
 * Push a job and read it through the real consumer group, so it sits in the PEL.
 */
function torque_release_reserved_job(StreamQueue $queue): StreamJob
{
    (void) $queue->push(new AttemptProbeJob('ok'), '', TORQUE_RELEASE_QUEUE);

    $job = $queue->pop(TORQUE_RELEASE_QUEUE);

    expect($job)->toBeInstanceOf(StreamJob::class)
        ->and($queue->reservedSize(TORQUE_RELEASE_QUEUE))->toBe(1)
        ->and($queue->pendingSize(TORQUE_RELEASE_QUEUE))->toBe(0);

    return $job;
}

/**
 * The original entry is untouched: in the stream, in the PEL, and claimable.
 */
function torque_release_expect_entry_kept(StreamQueue $queue, StreamJob $job): void
{
    $redis = $queue->getRedisClient();
    $key = $queue->getStreamKey(TORQUE_RELEASE_QUEUE);
    $id = $job->getMessageId();

    $rows = $redis->execute('XRANGE', $key, $id, $id);
    expect($rows)->toHaveCount(1)
        ->and((string) $rows[0][1][1])->toBe($job->getRawBody());

    $pending = $redis->execute('XPENDING', $key, TORQUE_RELEASE_GROUP, '-', '+', '10');
    expect($pending)->toHaveCount(1)
        ->and((string) $pending[0][0])->toBe($id);

    expect((int) $redis->execute('XLEN', $key))->toBe(1);

    // What the worker's reclaim does once the owner's retry_after has passed.
    $claim = $redis->execute('XAUTOCLAIM', $key, TORQUE_RELEASE_GROUP, 'other-consumer', '0', '0-0', 'COUNT', '1');
    expect((string) $claim[1][0][0])->toBe($id)
        ->and((string) $claim[1][0][1][1])->toBe($job->getRawBody());
}

function torque_release_cleanup(string $prefix): void
{
    $redis = torqueRedis();
    $cursor = '0';

    do {
        [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');
        $cursor = (string) $cursor;

        foreach ($keys ?? [] as $key) {
            $redis->execute('DEL', (string) $key);
        }
    } while ($cursor !== '0');
}

dataset('torque release key naming', [
    'plain' => [false],
    'cluster-tagged' => [true],
]);

it('moves a released job to the stream as exactly one new copy', function (bool $cluster) {
    $prefix = 'torque-release-'.bin2hex(random_bytes(4)).':';
    $queue = torque_release_queue($prefix, $cluster);
    $redis = $queue->getRedisClient();
    $key = $queue->getStreamKey(TORQUE_RELEASE_QUEUE);

    try {
        expect($key)->toBe($prefix.($cluster ? '{'.TORQUE_RELEASE_QUEUE.'}' : TORQUE_RELEASE_QUEUE));

        $job = torque_release_reserved_job($queue);
        $job->release();

        $rows = $redis->execute('XRANGE', $key, '-', '+');
        $copy = StreamQueue::decodePayload((string) $rows[0][1][1], 'json');

        expect($job->isReleased())->toBeTrue()
            ->and($queue->reservedSize(TORQUE_RELEASE_QUEUE))->toBe(0)
            ->and($queue->pendingSize(TORQUE_RELEASE_QUEUE))->toBe(1)
            ->and($rows)->toHaveCount(1)
            ->and((string) $rows[0][0])->not->toBe($job->getMessageId())
            ->and($copy['attempts'])->toBe(1)
            ->and($copy['uuid'])->toBe($job->uuid())
            ->and($queue->delayedSize(TORQUE_RELEASE_QUEUE))->toBe(0);
    } finally {
        torque_release_cleanup($prefix);
    }
})->with('torque release key naming');

it('moves a delayed release to the delayed set as exactly one new copy', function (bool $cluster) {
    $prefix = 'torque-release-'.bin2hex(random_bytes(4)).':';
    $queue = torque_release_queue($prefix, $cluster);
    $redis = $queue->getRedisClient();
    $key = $queue->getStreamKey(TORQUE_RELEASE_QUEUE);

    try {
        $job = torque_release_reserved_job($queue);

        $before = time();
        $job->release(30);
        $after = time();

        $members = $redis->execute('ZRANGE', $key.':delayed', '0', '-1');
        $copy = StreamQueue::decodePayload((string) $members[0], 'json');
        $score = (int) $redis->execute('ZSCORE', $key.':delayed', (string) $members[0]);

        expect($queue->reservedSize(TORQUE_RELEASE_QUEUE))->toBe(0)
            ->and($queue->pendingSize(TORQUE_RELEASE_QUEUE))->toBe(0)
            ->and((int) $redis->execute('XLEN', $key))->toBe(0)
            ->and($members)->toHaveCount(1)
            ->and($copy['attempts'])->toBe(1)
            ->and($copy['uuid'])->toBe($job->uuid())
            ->and($score)->toBeGreaterThanOrEqual($before + 30)
            ->and($score)->toBeLessThanOrEqual($after + 30);
    } finally {
        torque_release_cleanup($prefix);
    }
})->with('torque release key naming');

it('keeps the original entry when the delayed re-add fails', function (bool $cluster) {
    $prefix = 'torque-release-'.bin2hex(random_bytes(4)).':';
    $queue = torque_release_queue($prefix, $cluster);
    $redis = $queue->getRedisClient();
    $key = $queue->getStreamKey(TORQUE_RELEASE_QUEUE);

    try {
        $job = torque_release_reserved_job($queue);

        $redis->execute('SET', $key.':delayed', 'not-a-sorted-set');

        expect(fn () => $job->release(30))->toThrow(RedisException::class, 'WRONGTYPE');

        torque_release_expect_entry_kept($queue, $job);
    } finally {
        torque_release_cleanup($prefix);
    }
})->with('torque release key naming');

it('keeps the original entry when the stream re-add fails', function (bool $cluster) {
    $prefix = 'torque-release-'.bin2hex(random_bytes(4)).':';
    $queue = torque_release_queue($prefix, $cluster);
    $redis = $queue->getRedisClient();
    $key = $queue->getStreamKey(TORQUE_RELEASE_QUEUE);

    try {
        $job = torque_release_reserved_job($queue);

        $redis->execute('XSETID', $key, TORQUE_RELEASE_MAX_STREAM_ID);

        expect(fn () => $job->release())->toThrow(RedisException::class, 'exhausted');

        torque_release_expect_entry_kept($queue, $job);
    } finally {
        torque_release_cleanup($prefix);
    }
})->with('torque release key naming');
