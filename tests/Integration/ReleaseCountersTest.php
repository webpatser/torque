<?php

declare(strict_types=1);

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\AttemptProbeJob;

/*
|--------------------------------------------------------------------------
| Release counters
|--------------------------------------------------------------------------
|
| A job releasing itself raises `attempts` and `torque_releases`, and its first
| release writes `torque_release_deadline`. A retry after a failure raises only
| `attempts`, so triesUsed() counts it. Nothing here enforces the deadline: that
| is the worker's, so the last tests run the stock Illuminate worker past it.
|
| Requires a running Redis instance.
|
*/

const TORQUE_COUNTERS_QUEUE = 'counters';

function counters_queue(string $prefix, string $serializer = 'json'): StreamQueue
{
    $queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: TORQUE_COUNTERS_QUEUE,
        retryAfter: 90,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: 'counters-group',
        serializer: $serializer,
    );
    $queue->setContainer(app());
    $queue->setConnectionName('torque');
    $queue->ensureConsumerGroup($queue->getStreamKey(), 'counters-group');

    return $queue;
}

function counters_job(StreamQueue $queue): StreamJob
{
    (void) $queue->push(new AttemptProbeJob('ok'), '', TORQUE_COUNTERS_QUEUE);

    return counters_next($queue);
}

function counters_next(StreamQueue $queue): StreamJob
{
    $job = $queue->pop(TORQUE_COUNTERS_QUEUE);

    expect($job)->toBeInstanceOf(StreamJob::class);

    return $job;
}

/**
 * The job as a payload on another pass through the real queue.
 *
 * @param  array<string, mixed>  $changes
 */
function counters_job_with(StreamQueue $queue, array $changes): StreamJob
{
    (void) $queue->pushRaw(json_encode([
        'uuid' => 'counters-'.bin2hex(random_bytes(4)),
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => AttemptProbeJob::class, 'command' => serialize(new AttemptProbeJob('ok'))],
        'attempts' => 0,
        ...$changes,
    ], JSON_THROW_ON_ERROR), TORQUE_COUNTERS_QUEUE);

    return counters_next($queue);
}

function counters_cleanup(string $prefix): void
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

beforeEach(function () {
    $this->countersPrefix = 'torque-counters-'.bin2hex(random_bytes(4)).':';
    $this->countersQueue = counters_queue($this->countersPrefix);

    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
});

afterEach(function () {
    Carbon::setTestNow();

    counters_cleanup($this->countersPrefix);
});

it('does not count a self-release as a try', function () {
    $first = counters_job($this->countersQueue);

    expect($first->attempts())->toBe(1)
        ->and($first->triesUsed())->toBe(1)
        ->and($first->releases())->toBe(0)
        ->and($first->releaseDeadline())->toBeNull();

    $first->release();

    $second = counters_next($this->countersQueue);

    expect($second->attempts())->toBe(2)
        ->and($second->releases())->toBe(1)
        ->and($second->triesUsed())->toBe(1);
});

it('counts a retry after a failure as a try and leaves the counters alone', function () {
    $first = counters_job($this->countersQueue);
    $first->retryAfterFailure();

    expect($first->isReleased())->toBeTrue()
        ->and($first->releaseSucceeded())->toBeTrue();

    $second = counters_next($this->countersQueue);
    $decoded = $second->payload();

    expect($second->attempts())->toBe(2)
        ->and($second->triesUsed())->toBe(2)
        ->and($second->releases())->toBe(0)
        ->and($second->releaseDeadline())->toBeNull()
        ->and($decoded)->not->toHaveKey('torque_releases')
        ->and($decoded)->not->toHaveKey('torque_release_deadline');
});

it('adds up a mix of retries and self-releases', function () {
    counters_job($this->countersQueue)->release();

    $second = counters_next($this->countersQueue);
    $deadline = $second->releaseDeadline();
    $second->retryAfterFailure();

    $third = counters_next($this->countersQueue);

    expect($third->attempts())->toBe(3)
        ->and($third->releases())->toBe(1)
        ->and($third->triesUsed())->toBe(2)
        ->and($third->releaseDeadline())->toBe($deadline);
});

it('sets the deadline to the available-at of the first release plus the limit', function () {
    config(['torque.release_limit_seconds' => 3600]);

    counters_job($this->countersQueue)->release();

    expect(counters_next($this->countersQueue)->releaseDeadline())->toBe(1_800_000_000 + 3600);
});

it('counts the delay of the first release towards the deadline', function () {
    config(['torque.release_limit_seconds' => 3600]);

    counters_job($this->countersQueue)->release(30);

    $raw = $this->countersQueue->getRedisClient()->execute('ZRANGE', $this->countersQueue->getStreamKey(TORQUE_COUNTERS_QUEUE).':delayed', '0', '-1');
    $payload = StreamQueue::decodePayload((string) $raw[0], 'json');

    expect($payload['torque_release_deadline'])->toBe(1_800_000_000 + 30 + 3600)
        ->and($payload['torque_releases'])->toBe(1);
});

it('keeps the deadline of the first release on later ones', function () {
    config(['torque.release_limit_seconds' => 3600]);

    counters_job($this->countersQueue)->release();

    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_500));

    counters_next($this->countersQueue)->release(60);

    $raw = $this->countersQueue->getRedisClient()->execute('ZRANGE', $this->countersQueue->getStreamKey(TORQUE_COUNTERS_QUEUE).':delayed', '0', '-1');
    $payload = StreamQueue::decodePayload((string) $raw[0], 'json');

    expect($payload['torque_release_deadline'])->toBe(1_800_000_000 + 3600)
        ->and($payload['torque_releases'])->toBe(2);
});

it('starts the clock on a later release when the limit was off at the first', function () {
    config(['torque.release_limit_seconds' => 0]);

    counters_job($this->countersQueue)->release();

    $second = counters_next($this->countersQueue);

    expect($second->releaseDeadline())->toBeNull();

    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_500));
    config(['torque.release_limit_seconds' => 3600]);

    $second->release();

    $third = counters_next($this->countersQueue);

    expect($third->releaseDeadline())->toBe(1_800_000_500 + 3600)
        ->and($third->releases())->toBe(2);

    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_900));

    $third->release();

    expect(counters_next($this->countersQueue)->releaseDeadline())->toBe(1_800_000_500 + 3600);
});

it('falls back to a limit of a day', function () {
    config(['torque.release_limit_seconds' => null]);

    counters_job($this->countersQueue)->release();

    expect($this->countersQueue->releaseLimitSeconds(TORQUE_COUNTERS_QUEUE))->toBe(86400)
        ->and(counters_next($this->countersQueue)->releaseDeadline())->toBe(1_800_000_000 + 86400);
});

it('prefers the limit of the stream', function () {
    config([
        'torque.release_limit_seconds' => 3600,
        'torque.streams' => [TORQUE_COUNTERS_QUEUE => ['release_limit_seconds' => 60], 'other' => ['workers' => 1]],
    ]);

    expect($this->countersQueue->releaseLimitSeconds(TORQUE_COUNTERS_QUEUE))->toBe(60)
        ->and($this->countersQueue->releaseLimitSeconds('other'))->toBe(3600);

    counters_job($this->countersQueue)->release();

    expect(counters_next($this->countersQueue)->releaseDeadline())->toBe(1_800_000_000 + 60);
});

it('sets no deadline when the limit is off, and counts on', function (array $config) {
    config($config);

    counters_job($this->countersQueue)->release();

    $second = counters_next($this->countersQueue);

    expect($second->payload())->not->toHaveKey('torque_release_deadline')
        ->and($second->releaseDeadline())->toBeNull()
        ->and($second->releases())->toBe(1)
        ->and($second->triesUsed())->toBe(1);
})->with([
    'globally' => [['torque.release_limit_seconds' => 0]],
    'for the stream' => [['torque.release_limit_seconds' => 3600, 'torque.streams' => [TORQUE_COUNTERS_QUEUE => ['release_limit_seconds' => 0]]]],
]);

it('writes the counters in the igbinary format too', function () {
    $prefix = 'torque-counters-'.bin2hex(random_bytes(4)).':';
    config(['torque.serializer' => 'igbinary', 'torque.release_limit_seconds' => 3600]);

    try {
        $queue = counters_queue($prefix, 'igbinary');

        counters_job($queue)->release();

        $second = counters_next($queue);

        expect($second->getRawBody()[0])->not->toBe('{')
            ->and($second->releases())->toBe(1)
            ->and($second->releaseDeadline())->toBe(1_800_000_000 + 3600);
    } finally {
        counters_cleanup($prefix);
    }
})->skip(fn () => ! function_exists('igbinary_serialize'), 'ext-igbinary is not available');

it('ignores counters left by a reset of the attempts, as stock queue:retry does', function () {
    config(['torque.release_limit_seconds' => 3600]);

    $job = counters_job_with($this->countersQueue, ['torque_releases' => 3, 'torque_release_deadline' => 1_700_000_000]);

    expect($job->attempts())->toBe(1)
        ->and($job->releases())->toBe(0)
        ->and($job->triesUsed())->toBe(1)
        ->and($job->releaseDeadline())->toBeNull();

    $job->release();

    $next = counters_next($this->countersQueue);

    expect($next->releases())->toBe(1)
        ->and($next->triesUsed())->toBe(1)
        ->and($next->releaseDeadline())->toBe(1_800_000_000 + 3600);
});

it('drops counters left by a reset of the attempts on a retry after a failure', function () {
    $job = counters_job_with($this->countersQueue, ['torque_releases' => 1, 'torque_release_deadline' => 1_700_000_000]);

    $job->retryAfterFailure();

    // One attempt later attempts and the old count would match and look current.
    $next = counters_next($this->countersQueue);

    expect($next->attempts())->toBe(2)
        ->and($next->releases())->toBe(0)
        ->and($next->triesUsed())->toBe(2)
        ->and($next->payload())->not->toHaveKey('torque_releases')
        ->and($next->payload())->not->toHaveKey('torque_release_deadline');
});

it('resets the counters when a job comes back from the dead letter stream', function () {
    $handler = new DeadLetterHandler(redisUri: torqueRedisUri(), prefix: $this->countersPrefix);

    $handler->handle(
        queue: TORQUE_COUNTERS_QUEUE,
        payload: json_encode(['uuid' => 'dead-counters', 'job' => 'TestJob', 'attempts' => 5, 'torque_releases' => 4, 'torque_release_deadline' => 1_700_000_000]),
        messageId: '1680000000000-0',
        exception: new RuntimeException('Gave up'),
    );

    $entries = $handler->list(count: 10);
    $handler->retry(end($entries)['id']);

    $job = counters_next($this->countersQueue);

    expect($job->attempts())->toBe(1)
        ->and($job->triesUsed())->toBe(1)
        ->and($job->payload())->not->toHaveKey('torque_releases')
        ->and($job->payload())->not->toHaveKey('torque_release_deadline');
});

it('leaves a stock worker running a job past its deadline', function () {
    config(['torque.release_limit_seconds' => 60]);

    counters_job($this->countersQueue)->release();

    $job = counters_next($this->countersQueue);

    Carbon::setTestNow(Carbon::createFromTimestamp($job->releaseDeadline() + 3600));

    app('queue.worker')->process('torque', $job, new WorkerOptions(maxTries: 2));

    expect($job->isDeleted())->toBeTrue()
        ->and($job->hasFailed())->toBeFalse();
});

it('still counts self-releases as attempts on a stock worker', function () {
    counters_job($this->countersQueue)->release();
    counters_next($this->countersQueue)->release();

    $job = counters_next($this->countersQueue);

    expect($job->attempts())->toBe(3)
        ->and($job->triesUsed())->toBe(1);

    expect(fn () => app('queue.worker')->process('torque', $job, new WorkerOptions(maxTries: 2)))
        ->toThrow(MaxAttemptsExceededException::class);

    expect($job->hasFailed())->toBeTrue();
});
