<?php

declare(strict_types=1);

use Webpatser\Torque\Stream\JobStream;
use Webpatser\Torque\Stream\JobStreamRecorder;

function createTestStream(string $prefix = 'torque-active-test:'): JobStream
{
    return new JobStream(
        redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
        prefix: $prefix,
    );
}

function createTestRecorder(string $prefix = 'torque-active-test:'): JobStreamRecorder
{
    return new JobStreamRecorder(
        redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
        prefix: $prefix,
    );
}

function cleanupJobKeys(string $prefix, array $uuids): void
{
    $redis = \Fledge\Async\Redis\createRedisClient(
        env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
    );

    foreach ($uuids as $uuid) {
        $redis->execute('DEL', $prefix . 'job:' . $uuid);
    }
}

it('returns empty array when no jobs exist', function () {
    $prefix = 'torque-active-empty-' . bin2hex(random_bytes(4)) . ':';
    $stream = createTestStream($prefix);

    try {
        expect($stream->activeJobs())->toBe([]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns active jobs that are not terminal', function () {
    $prefix = 'torque-active-nonterminal-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createTestRecorder($prefix);
    $stream = createTestStream($prefix);

    $uuid1 = 'active-' . bin2hex(random_bytes(4));
    $uuid2 = 'completed-' . bin2hex(random_bytes(4));
    $uuid3 = 'started-' . bin2hex(random_bytes(4));

    try {
        // Job 1: still in progress (last event is "progress").
        $recorder->record($uuid1, 'queued', ['queue' => 'default', 'displayName' => 'TestJob']);
        $recorder->record($uuid1, 'started', ['worker' => 'w1']);
        $recorder->record($uuid1, 'progress', ['message' => 'Step 1', 'progress' => '0.5']);

        // Job 2: completed (terminal).
        $recorder->record($uuid2, 'queued', ['queue' => 'default']);
        $recorder->record($uuid2, 'started', ['worker' => 'w1']);
        $recorder->record($uuid2, 'completed', ['memory_bytes' => '1048576'], terminal: true);

        // Job 3: just started.
        $recorder->record($uuid3, 'queued', ['queue' => 'high']);
        $recorder->record($uuid3, 'started', ['worker' => 'w2']);

        $active = $stream->activeJobs();

        // Only jobs 1 and 3 should be active.
        $activeUuids = array_column($active, 'uuid');
        expect($activeUuids)->toContain($uuid1)
            ->toContain($uuid3)
            ->not->toContain($uuid2);

        // Verify the data structure.
        $job1 = collect($active)->firstWhere('uuid', $uuid1);
        expect($job1['type'])->toBe('progress');
        expect($job1['data']['message'])->toBe('Step 1');

        $job3 = collect($active)->firstWhere('uuid', $uuid3);
        expect($job3['type'])->toBe('started');

        cleanupJobKeys($prefix, [$uuid1, $uuid2, $uuid3]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('excludes failed and dead-lettered jobs from active', function () {
    $prefix = 'torque-active-failed-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createTestRecorder($prefix);
    $stream = createTestStream($prefix);

    $uuidFailed = 'fail-' . bin2hex(random_bytes(4));
    $uuidDead = 'dead-' . bin2hex(random_bytes(4));
    $uuidRunning = 'run-' . bin2hex(random_bytes(4));

    try {
        $recorder->record($uuidFailed, 'queued', []);
        $recorder->record($uuidFailed, 'failed', ['exception_class' => 'RuntimeException'], terminal: true);

        $recorder->record($uuidDead, 'queued', []);
        $recorder->record($uuidDead, 'dead_lettered', [], terminal: true);

        $recorder->record($uuidRunning, 'queued', []);
        $recorder->record($uuidRunning, 'started', []);

        $active = $stream->activeJobs();
        $activeUuids = array_column($active, 'uuid');

        expect($activeUuids)->toContain($uuidRunning)
            ->not->toContain($uuidFailed)
            ->not->toContain($uuidDead);

        cleanupJobKeys($prefix, [$uuidFailed, $uuidDead, $uuidRunning]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns queued jobs as active', function () {
    $prefix = 'torque-active-queued-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createTestRecorder($prefix);
    $stream = createTestStream($prefix);

    $uuid = 'queued-' . bin2hex(random_bytes(4));

    try {
        $recorder->record($uuid, 'queued', ['queue' => 'default']);

        $active = $stream->activeJobs();
        $activeUuids = array_column($active, 'uuid');

        expect($activeUuids)->toContain($uuid);

        cleanupJobKeys($prefix, [$uuid]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns exception-state jobs as active since they will retry', function () {
    $prefix = 'torque-active-exception-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createTestRecorder($prefix);
    $stream = createTestStream($prefix);

    $uuid = 'exception-' . bin2hex(random_bytes(4));

    try {
        $recorder->record($uuid, 'queued', []);
        $recorder->record($uuid, 'started', []);
        $recorder->record($uuid, 'exception', ['exception_message' => 'Retryable error', 'attempt' => '1']);

        $active = $stream->activeJobs();
        $activeUuids = array_column($active, 'uuid');

        expect($activeUuids)->toContain($uuid);

        $job = collect($active)->firstWhere('uuid', $uuid);
        expect($job['type'])->toBe('exception');

        cleanupJobKeys($prefix, [$uuid]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
