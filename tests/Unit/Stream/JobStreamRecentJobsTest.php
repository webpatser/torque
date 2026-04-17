<?php

declare(strict_types=1);

use Webpatser\Torque\Stream\JobStream;
use Webpatser\Torque\Stream\JobStreamRecorder;

function createRecentStream(string $prefix): JobStream
{
    return new JobStream(
        redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
        prefix: $prefix,
    );
}

function createRecentRecorder(string $prefix): JobStreamRecorder
{
    return new JobStreamRecorder(
        redisUri: env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
        prefix: $prefix,
    );
}

function cleanupRecentKeys(string $prefix, array $uuids): void
{
    $redis = \Fledge\Async\Redis\createRedisClient(
        env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'),
    );

    foreach ($uuids as $uuid) {
        $redis->execute('DEL', $prefix . 'job:' . $uuid);
    }
}

it('returns all recent jobs when no status filter', function () {
    $prefix = 'torque-recent-all-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createRecentRecorder($prefix);
    $stream = createRecentStream($prefix);

    $uuid1 = 'recent-1-' . bin2hex(random_bytes(4));
    $uuid2 = 'recent-2-' . bin2hex(random_bytes(4));
    $uuid3 = 'recent-3-' . bin2hex(random_bytes(4));

    try {
        // Active job.
        $recorder->record($uuid1, 'queued', ['queue' => 'default']);
        $recorder->record($uuid1, 'started', []);

        // Completed job.
        $recorder->record($uuid2, 'queued', ['queue' => 'default']);
        $recorder->record($uuid2, 'completed', [], terminal: true);

        // Failed job.
        $recorder->record($uuid3, 'queued', ['queue' => 'default']);
        $recorder->record($uuid3, 'failed', ['exception_class' => 'RuntimeException'], terminal: true);

        $jobs = $stream->recentJobs();

        $uuids = array_column($jobs, 'uuid');
        expect($uuids)->toContain($uuid1)
            ->toContain($uuid2)
            ->toContain($uuid3);

        cleanupRecentKeys($prefix, [$uuid1, $uuid2, $uuid3]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('filters by active status', function () {
    $prefix = 'torque-recent-active-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createRecentRecorder($prefix);
    $stream = createRecentStream($prefix);

    $uuidActive = 'active-' . bin2hex(random_bytes(4));
    $uuidDone = 'done-' . bin2hex(random_bytes(4));

    try {
        $recorder->record($uuidActive, 'queued', []);
        $recorder->record($uuidActive, 'started', []);

        $recorder->record($uuidDone, 'queued', []);
        $recorder->record($uuidDone, 'completed', [], terminal: true);

        $jobs = $stream->recentJobs('active');
        $uuids = array_column($jobs, 'uuid');

        expect($uuids)->toContain($uuidActive)
            ->not->toContain($uuidDone);

        cleanupRecentKeys($prefix, [$uuidActive, $uuidDone]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('filters by completed status', function () {
    $prefix = 'torque-recent-completed-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createRecentRecorder($prefix);
    $stream = createRecentStream($prefix);

    $uuidActive = 'active-' . bin2hex(random_bytes(4));
    $uuidDone = 'done-' . bin2hex(random_bytes(4));
    $uuidFailed = 'failed-' . bin2hex(random_bytes(4));

    try {
        $recorder->record($uuidActive, 'queued', []);
        $recorder->record($uuidActive, 'started', []);

        $recorder->record($uuidDone, 'queued', []);
        $recorder->record($uuidDone, 'completed', [], terminal: true);

        $recorder->record($uuidFailed, 'queued', []);
        $recorder->record($uuidFailed, 'failed', [], terminal: true);

        $jobs = $stream->recentJobs('completed');
        $uuids = array_column($jobs, 'uuid');

        expect($uuids)->toContain($uuidDone)
            ->not->toContain($uuidActive)
            ->not->toContain($uuidFailed);

        cleanupRecentKeys($prefix, [$uuidActive, $uuidDone, $uuidFailed]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('filters by failed status including dead-lettered', function () {
    $prefix = 'torque-recent-failed-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createRecentRecorder($prefix);
    $stream = createRecentStream($prefix);

    $uuidFailed = 'fail-' . bin2hex(random_bytes(4));
    $uuidDead = 'dead-' . bin2hex(random_bytes(4));
    $uuidDone = 'done-' . bin2hex(random_bytes(4));

    try {
        $recorder->record($uuidFailed, 'queued', []);
        $recorder->record($uuidFailed, 'failed', [], terminal: true);

        $recorder->record($uuidDead, 'queued', []);
        $recorder->record($uuidDead, 'dead_lettered', [], terminal: true);

        $recorder->record($uuidDone, 'queued', []);
        $recorder->record($uuidDone, 'completed', [], terminal: true);

        $jobs = $stream->recentJobs('failed');
        $uuids = array_column($jobs, 'uuid');

        expect($uuids)->toContain($uuidFailed)
            ->toContain($uuidDead)
            ->not->toContain($uuidDone);

        cleanupRecentKeys($prefix, [$uuidFailed, $uuidDead, $uuidDone]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns first and last event for each job', function () {
    $prefix = 'torque-recent-events-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createRecentRecorder($prefix);
    $stream = createRecentStream($prefix);

    $uuid = 'events-' . bin2hex(random_bytes(4));

    try {
        $recorder->record($uuid, 'queued', ['queue' => 'high', 'displayName' => 'App\\Jobs\\ImportData']);
        $recorder->record($uuid, 'started', ['worker' => 'w1']);
        $recorder->record($uuid, 'progress', ['message' => 'Step 2', 'progress' => '0.75']);

        $jobs = $stream->recentJobs();
        $job = collect($jobs)->firstWhere('uuid', $uuid);

        expect($job)->not->toBeNull();
        expect($job['first_event']['type'])->toBe('queued');
        expect($job['first_event']['data']['displayName'])->toBe('App\\Jobs\\ImportData');
        expect($job['last_event']['type'])->toBe('progress');
        expect($job['last_event']['data']['progress'])->toBe('0.75');

        cleanupRecentKeys($prefix, [$uuid]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

it('returns empty when no jobs match status filter', function () {
    $prefix = 'torque-recent-nomatch-' . bin2hex(random_bytes(4)) . ':';
    $recorder = createRecentRecorder($prefix);
    $stream = createRecentStream($prefix);

    $uuid = 'running-' . bin2hex(random_bytes(4));

    try {
        $recorder->record($uuid, 'queued', []);
        $recorder->record($uuid, 'started', []);

        $jobs = $stream->recentJobs('completed');
        expect($jobs)->toBe([]);

        cleanupRecentKeys($prefix, [$uuid]);
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
