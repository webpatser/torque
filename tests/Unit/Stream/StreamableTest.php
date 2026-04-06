<?php

declare(strict_types=1);

use Webpatser\Torque\Stream\JobStreamRecorder;
use Webpatser\Torque\Stream\Streamable;

// Fake job class using the Streamable trait.
class FakeStreamableJob
{
    use Streamable;

    public $job;
}

// Fake queue job that mimics InteractsWithQueue's $this->job.
class FakeQueueJob
{
    public function __construct(private string $uuid) {}

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function getJobId(): string
    {
        return $this->uuid;
    }
}

it('resolves uuid from the underlying queue job', function () {
    $uuid = 'test-' . bin2hex(random_bytes(8));

    $fakeJob = new FakeStreamableJob();
    $fakeJob->job = new FakeQueueJob($uuid);

    // Use reflection to test the private method.
    $method = new ReflectionMethod($fakeJob, 'resolveStreamUuid');

    expect($method->invoke($fakeJob))->toBe($uuid);
});

it('returns null when no queue job is set', function () {
    $fakeJob = new FakeStreamableJob();

    $method = new ReflectionMethod($fakeJob, 'resolveStreamUuid');

    expect($method->invoke($fakeJob))->toBeNull();
});

it('emits custom events via the recorder', function () {
    $uuid = 'test-' . bin2hex(random_bytes(8));
    $key = 'torque-test:job:' . $uuid;

    $redis = \Amp\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $redis->execute('DEL', $key);

    // Bind the recorder in the container.
    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );
    app()->instance(JobStreamRecorder::class, $recorder);

    $fakeJob = new FakeStreamableJob();
    $fakeJob->job = new FakeQueueJob($uuid);
    $fakeJob->emit('Processing step 3', progress: 0.75);

    $entries = $redis->execute('XRANGE', $key, '-', '+');

    expect($entries)->toHaveCount(1);

    $fields = [];
    for ($i = 0, $c = count($entries[0][1]); $i < $c; $i += 2) {
        $fields[(string) $entries[0][1][$i]] = (string) $entries[0][1][$i + 1];
    }

    expect($fields['type'])->toBe('progress');
    expect($fields['message'])->toBe('Processing step 3');
    expect($fields['progress'])->toBe('0.75');

    $redis->execute('DEL', $key);
});

it('does not throw when recorder is not bound', function () {
    // Ensure the container doesn't have a recorder.
    app()->forgetInstance(JobStreamRecorder::class);

    $fakeJob = new FakeStreamableJob();
    $fakeJob->job = new FakeQueueJob('some-uuid');

    // Should not throw — silently ignored.
    $fakeJob->emit('test message');

    expect(true)->toBeTrue();
});
