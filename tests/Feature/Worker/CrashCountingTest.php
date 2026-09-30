<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Worker\WorkerProcess;

/**
 * A StreamJob that never touches Redis: delete and release only flip the
 * base Job flags, and fire() runs the given callback instead of resolving
 * a handler from the payload.
 *
 * @param  array<string, mixed>  $payload
 */
function torque_crash_test_job(array $payload, ?Closure $onFire = null): StreamJob
{
    $streamQueue = new StreamQueue(
        redisUri: 'redis://127.0.0.1:6379',
        default: 'default',
        retryAfter: 90,
        blockFor: 0,
        prefix: 'torque-test:',
        consumerGroup: 'torque-test',
    );

    $job = new class(container: app(), streamQueue: $streamQueue, rawBody: json_encode($payload + ['displayName' => 'CrashTest', 'job' => 'CrashTest@handle', 'data' => []], JSON_THROW_ON_ERROR), messageId: '1-0', connectionName: 'torque', queue: 'default') extends StreamJob
    {
        public ?Closure $onFire = null;

        public function fire(): void
        {
            if ($this->onFire !== null) {
                ($this->onFire)();
            }
        }

        public function delete(): void
        {
            $this->deleted = true;
        }

        public function release($delay = 0): void
        {
            $this->released = true;
        }

        protected function failed($e): void
        {
            // No handler class to call back into.
        }
    };

    $job->onFire = $onFire;

    return $job;
}

function torque_crash_counting_payload(int $maxExceptions = 2, int $attempts = 0, bool $count = true): array
{
    return [
        'uuid' => 'crash-test-uuid',
        'maxExceptions' => $maxExceptions,
        'countCrashesAsExceptions' => $count,
        'attempts' => $attempts,
    ];
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    app()->forgetInstance('cache.store');
    Cache::flush();
});

it('reports the handler run time in milliseconds on JobProcessed', function () {
    Event::fake([JobProcessed::class]);

    $job = torque_crash_test_job(['uuid' => 'duration-uuid'], fn () => usleep(20_000));

    (new ReflectionMethod(WorkerProcess::class, 'processMessage'))
        ->invoke(new WorkerProcess([]), $job, app(EventDispatcher::class), 'torque');

    Event::assertDispatched(JobProcessed::class, fn (JobProcessed $event) => $event->job === $job
        && is_float($event->duration)
        && $event->duration >= 20.0
        && $event->duration < 5_000.0);
});

it('sets a processing marker on pickup and removes it when the attempt ends', function () {
    $worker = new WorkerProcess([]);
    $job = torque_crash_test_job(torque_crash_counting_payload());

    $worker->guardAgainstRepeatedCrashes($job);

    expect(Cache::get('job-processing:crash-test-uuid'))->toBe(1);

    $worker->forgetProcessingMarker($job);

    expect(Cache::has('job-processing:crash-test-uuid'))->toBeFalse();

    // A clean finish leaves nothing behind, so the next pickup is not a crash.
    $worker->guardAgainstRepeatedCrashes($job);

    expect(Cache::has('job-exceptions:crash-test-uuid'))->toBeFalse();
});

it('counts a stale marker as an exception and fails once maxExceptions is reached', function () {
    $worker = new WorkerProcess([]);
    $job = torque_crash_test_job(torque_crash_counting_payload(maxExceptions: 2));

    // First pickup, then the worker dies: the marker is never removed.
    $worker->guardAgainstRepeatedCrashes($job);

    // Redelivery from the PEL: one crash, still below the limit.
    $worker->guardAgainstRepeatedCrashes($job);

    expect(Cache::get('job-exceptions:crash-test-uuid'))->toBe(1);

    // Second crash reaches maxExceptions.
    expect(fn () => $worker->guardAgainstRepeatedCrashes($job))
        ->toThrow(MaxAttemptsExceededException::class);

    expect(Cache::has('job-exceptions:crash-test-uuid'))->toBeFalse();
});

it('fails the job through processMessage before the handler runs', function () {
    $worker = new WorkerProcess([]);
    $fired = false;
    $job = torque_crash_test_job(torque_crash_counting_payload(maxExceptions: 1), function () use (&$fired) {
        $fired = true;
    });

    $worker->guardAgainstRepeatedCrashes($job);

    expect(fn () => (new ReflectionMethod(WorkerProcess::class, 'processMessage'))
        ->invoke($worker, $job, app(EventDispatcher::class), 'torque'))
        ->toThrow(MaxAttemptsExceededException::class);

    expect($fired)->toBeFalse();
});

it('does not count a marker left by a different attempt of the same job', function () {
    $worker = new WorkerProcess([]);
    $first = torque_crash_test_job(torque_crash_counting_payload(maxExceptions: 1, attempts: 0));
    $released = torque_crash_test_job(torque_crash_counting_payload(maxExceptions: 1, attempts: 1));

    // Attempt 1 released itself and attempt 2 was picked up by another
    // slot before attempt 1's cleanup ran.
    $worker->guardAgainstRepeatedCrashes($first);
    $worker->guardAgainstRepeatedCrashes($released);

    expect(Cache::get('job-processing:crash-test-uuid'))->toBe(2)
        ->and(Cache::has('job-exceptions:crash-test-uuid'))->toBeFalse();

    // Attempt 1's late cleanup must leave attempt 2's marker alone.
    $worker->forgetProcessingMarker($first);

    expect(Cache::get('job-processing:crash-test-uuid'))->toBe(2);
});

it('ignores jobs that did not opt in or have no maxExceptions', function (array $payload) {
    $worker = new WorkerProcess([]);
    $job = torque_crash_test_job($payload);

    $worker->guardAgainstRepeatedCrashes($job);
    $worker->guardAgainstRepeatedCrashes($job);
    $worker->guardAgainstRepeatedCrashes($job);

    expect(Cache::has('job-processing:crash-test-uuid'))->toBeFalse()
        ->and(Cache::has('job-exceptions:crash-test-uuid'))->toBeFalse();
})->with([
    'flag off' => [torque_crash_counting_payload(maxExceptions: 1, count: false)],
    'no maxExceptions' => [['uuid' => 'crash-test-uuid', 'countCrashesAsExceptions' => true, 'maxExceptions' => null]],
]);

it('fails a crash-exhausted job permanently even with retries left', function () {
    $redis = torqueRedis();
    $redis->execute('DEL', 'torque-test:dead-letter');

    $job = torque_crash_test_job(torque_crash_counting_payload(maxExceptions: 1));
    $exception = MaxAttemptsExceededException::forJob($job);

    (new ReflectionMethod(WorkerProcess::class, 'handleFailure'))->invoke(
        new WorkerProcess([]),
        $job,
        ['stream' => 'torque-test:default', 'id' => '1-0', 'payload' => $job->getRawBody()],
        $exception,
        app(EventDispatcher::class),
        'torque',
        ['default' => ['max_retries' => 5]],
        new DeadLetterHandler(redisUri: torqueRedisUri(), prefix: 'torque-test:'),
    );

    expect($job->hasFailed())->toBeTrue()
        ->and($job->isReleased())->toBeFalse();

    $redis->execute('DEL', 'torque-test:dead-letter');
});
