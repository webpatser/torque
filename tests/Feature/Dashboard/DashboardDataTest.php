<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Data\DeadLetterData;
use Webpatser\Torque\Dashboard\Data\JobsData;
use Webpatser\Torque\Dashboard\Data\OverviewData;
use Webpatser\Torque\Dashboard\Data\QueuesData;
use Webpatser\Torque\Dashboard\Data\WorkersData;
use Webpatser\Torque\Dashboard\Livewire\Dead;
use Webpatser\Torque\Dashboard\Livewire\Feed;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Metrics\WorkerSnapshot;
use Webpatser\Torque\Stream\JobStreamRecorder;

/**
 * The dashboard read-models (Dashboard\Data\*) shape the live Redis state into
 * the arrays the Livewire screens render. They resolve their collaborators from
 * the container, all pointed at the test Redis database with the `torque-test:`
 * prefix (see TestCase::defineEnvironment). We seed the same keyspace with the
 * real recorder/handler/publisher classes, then assert the contract.
 */
function torqueTestRedisUri(): string
{
    return env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
}

function torqueTestRedis(): RedisClient
{
    return \Fledge\Async\Redis\createRedisClient(torqueTestRedisUri());
}

it('builds the overview shape with correct types', function () {
    $publisher = new MetricsPublisher(redisUri: torqueTestRedisUri(), prefix: 'torque-test:');
    $handler = new DeadLetterHandler(redisUri: torqueTestRedisUri(), prefix: 'torque-test:');

    try {
        $publisher->publishWorkerMetrics('web-01-5123', new WorkerSnapshot(
            jobsProcessed: 100,
            jobsFailed: 4,
            activeSlots: 8,
            totalSlots: 50,
            averageLatencyMs: 410.0,
            slotUsageRatio: 0.16,
            memoryBytes: 96_468_992,
            timestamp: time(),
        ));

        $handler->handle('default', '{"uuid":"ov-dead"}', '1680000000000-0', new RuntimeException('boom'));

        $payload = app(OverviewData::class)->get();

        expect($payload)->toHaveKeys(['totals', 'metrics', 'live', 'deadCount'])
            ->and($payload['totals'])->toHaveKeys(['slots', 'busy', 'pending', 'delayed', 'rpm', 'util'])
            ->and($payload['metrics'])->toHaveKeys(['throughput', 'concurrent', 'latencyMs', 'memoryMb', 'failRate', 'jobsTotal', 'workers'])
            ->and($payload['totals']['slots'])->toBeInt()
            ->and($payload['totals']['pending'])->toBeInt()
            ->and($payload['totals']['delayed'])->toBeInt()
            ->and($payload['metrics']['throughput'])->toBeNumeric()
            ->and($payload['live'])->toBeArray()
            ->and($payload['deadCount'])->toBeInt()
            ->and($payload['deadCount'])->toBeGreaterThanOrEqual(1);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        $redis = torqueTestRedis();
        $redis->execute('DEL', 'torque-test:worker:web-01-5123');
        $redis->execute('DEL', 'torque-test:metrics');
        $redis->execute('DEL', 'torque-test:dead-letter');
    }
});

it('builds workers with the documented keys and null sub-widgets', function () {
    $publisher = new MetricsPublisher(redisUri: torqueTestRedisUri(), prefix: 'torque-test:');

    try {
        $publisher->publishWorkerMetrics('web-01-5123', new WorkerSnapshot(
            jobsProcessed: 18_234,
            jobsFailed: 12,
            activeSlots: 38,
            totalSlots: 50,
            averageLatencyMs: 410.0,
            slotUsageRatio: 0.76,
            memoryBytes: 96_468_992,
            timestamp: time(),
        ));

        $workers = app(WorkersData::class)->get()['workers'];

        $worker = collect($workers)->firstWhere('id', 'web-01-5123');

        expect($worker)->not->toBeNull()
            ->and($worker)->toHaveKeys(['id', 'host', 'pid', 'slots', 'busy', 'stalled', 'memMb', 'memPeakMb', 'processed', 'failed', 'rpm', 'latencyMs', 'uptime', 'status', 'pools', 'history'])
            ->and($worker['host'])->toBe('web-01')
            ->and($worker['pid'])->toBe(5123)
            ->and($worker['slots'])->toBe(50)
            ->and($worker['busy'])->toBe(38)
            ->and($worker['processed'])->toBe(18_234)
            ->and($worker['failed'])->toBe(12)
            ->and($worker['stalled'])->toBe(0)
            ->and($worker['status'])->toBe('active')
            ->and($worker['pools'])->toBeNull()
            ->and($worker['rpm'])->toBeNull()
            ->and($worker['uptime'])->toBeNull()
            ->and($worker['memPeakMb'])->toBeNull()
            ->and($worker['history'])->toBe([]);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        torqueTestRedis()->execute('DEL', 'torque-test:worker:web-01-5123');
    }
});

it('builds one queue entry per configured stream with null throughput', function () {
    try {
        $queues = app(QueuesData::class)->get()['queues'];

        $names = array_column($queues, 'name');

        expect($names)->toBe(array_map('strval', array_keys((array) config('torque.streams'))))
            ->and($names)->toContain('default');

        $default = collect($queues)->firstWhere('name', 'default');

        expect($default)->toHaveKeys(['name', 'pending', 'delayed', 'reserved', 'processedToday', 'throughput', 'wait', 'history', 'paused'])
            ->and($default['pending'])->toBeInt()
            ->and($default['delayed'])->toBeInt()
            ->and($default['reserved'])->toBeInt()
            ->and($default['throughput'])->toBeNull()
            ->and($default['wait'])->toBeNull()
            ->and($default['processedToday'])->toBeNull()
            ->and($default['paused'])->toBeFalse();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('maps job summaries for the jobs list and filters by status', function () {
    $recorder = new JobStreamRecorder(redisUri: torqueTestRedisUri(), prefix: 'torque-test:');

    $running = 'data-jobs-running-'.bin2hex(random_bytes(4));
    $done = 'data-jobs-done-'.bin2hex(random_bytes(4));

    try {
        $recorder->record($running, 'queued', ['queue' => 'default', 'displayName' => 'App\\Jobs\\ProcessDocument']);
        $recorder->record($running, 'started', ['worker' => 'web-01-5123']);

        $recorder->record($done, 'queued', ['queue' => 'default', 'displayName' => 'App\\Jobs\\SendInvoice']);
        $recorder->record($done, 'completed', [], terminal: true);

        $jobs = app(JobsData::class)->list('all');

        $job = collect($jobs)->firstWhere('id', $running);

        expect($job)->not->toBeNull()
            ->and($job)->toHaveKeys(['id', 'ns', 'cls', 'name', 'queue', 'status', 'attempt', 'maxTries', 'worker', 'progress', 'runtime', 'ts'])
            ->and($job['ns'])->toBe('App\\Jobs\\')
            ->and($job['cls'])->toBe('ProcessDocument')
            ->and($job['queue'])->toBe('default')
            ->and($job['status'])->toBe('running')
            ->and($job['worker'])->toBe('web-01-5123')
            ->and($job['ts'])->toBeInt()
            ->and($job['ts'])->toBeGreaterThan(0);

        $activeIds = array_column(app(JobsData::class)->list('active'), 'id');

        expect($activeIds)->toContain($running)->not->toContain($done);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        $redis = torqueTestRedis();
        $redis->execute('DEL', 'torque-test:job:'.$running);
        $redis->execute('DEL', 'torque-test:job:'.$done);
    }
});

it('returns a single job with its event stream', function () {
    $recorder = new JobStreamRecorder(redisUri: torqueTestRedisUri(), prefix: 'torque-test:');

    $uuid = 'data-show-'.bin2hex(random_bytes(4));

    try {
        $recorder->record($uuid, 'queued', ['queue' => 'default', 'displayName' => 'App\\Jobs\\ScrapeKvK']);
        $recorder->record($uuid, 'started', ['worker' => 'web-01-5123']);
        $recorder->record($uuid, 'completed', [], terminal: true);

        $payload = app(JobsData::class)->show($uuid);

        expect($payload['job']['id'])->toBe($uuid)
            ->and($payload['job']['cls'])->toBe('ScrapeKvK')
            ->and($payload['job']['status'])->toBe('completed')
            ->and($payload['job']['connection'])->toBe('torque')
            ->and($payload['events'])->toHaveCount(3)
            ->and($payload['events'][0]['type'])->toBe('queued')
            ->and($payload['events'][0]['ts'])->toBeInt()
            ->and($payload['events'][0]['ts'])->toBeGreaterThan(0);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        torqueTestRedis()->execute('DEL', 'torque-test:job:'.$uuid);
    }
});

it('returns a null job for an unknown uuid', function () {
    try {
        $payload = app(JobsData::class)->show('does-not-exist-'.bin2hex(random_bytes(4)));

        expect($payload['job'])->toBeNull()
            ->and($payload['events'])->toBe([])
            ->and($payload['payload'])->toBeNull();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('lists dead-letter entries with the documented shape', function () {
    $handler = new DeadLetterHandler(redisUri: torqueTestRedisUri(), prefix: 'torque-test:');

    try {
        $handler->handle(
            queue: 'scrpr',
            payload: '{"uuid":"dl-1","displayName":"App\\\\Jobs\\\\ScrapeKvK"}',
            messageId: '1680000000000-0',
            exception: new RuntimeException('cURL error 28'),
        );

        $payload = app(DeadLetterData::class)->list();

        expect($payload['count'])->toBeInt()->toBeGreaterThanOrEqual(1);

        $entry = $payload['jobs'][0];

        expect($entry)->toHaveKeys(['id', 'ns', 'cls', 'name', 'queue', 'exception', 'message', 'trace', 'attempts', 'failedAt', 'worker'])
            ->and($entry['queue'])->toBe('scrpr')
            ->and($entry['cls'])->toBe('ScrapeKvK')
            ->and($entry['exception'])->toBe('RuntimeException')
            ->and($entry['message'])->toBe('cURL error 28')
            ->and($entry['failedAt'])->toBeInt()
            ->and($entry['attempts'])->toBeNull()
            ->and($entry['worker'])->toBeNull();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        torqueTestRedis()->execute('DEL', 'torque-test:dead-letter');
    }
});

it('retries a dead-letter entry from the Dead component', function () {
    $handler = new DeadLetterHandler(
        redisUri: torqueTestRedisUri(),
        prefix: 'torque-test:',
        allowedQueues: array_keys((array) config('torque.streams')),
    );

    try {
        $handler->handle('default', '{"uuid":"dl-retry"}', '1680000000000-0', new RuntimeException('boom'));

        $id = $handler->list(1)[0]['id'];

        Livewire::test(Dead::class)
            ->call('retryOne', $id)
            ->assertOk();

        $remaining = array_column($handler->list(100), 'id');
        expect($remaining)->not->toContain($id);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        $redis = torqueTestRedis();
        $redis->execute('DEL', 'torque-test:dead-letter');
        $redis->execute('DEL', 'torque-test:default');
    }
});

it('purges a dead-letter entry from the Dead component', function () {
    $handler = new DeadLetterHandler(redisUri: torqueTestRedisUri(), prefix: 'torque-test:');

    try {
        $handler->handle('default', '{"uuid":"dl-purge"}', '1680000000000-0', new RuntimeException('boom'));

        $id = $handler->list(1)[0]['id'];

        Livewire::test(Dead::class)
            ->call('purgeOne', $id)
            ->assertOk();

        $remaining = array_column($handler->list(100), 'id');
        expect($remaining)->not->toContain($id);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        torqueTestRedis()->execute('DEL', 'torque-test:dead-letter');
    }
});

it('toggles the live-feed status filter', function () {
    Livewire::test(Feed::class)
        ->assertSet('filter', 'all')
        ->call('setFilter', 'failed')
        ->assertSet('filter', 'failed');
});

it('updates the poll interval from the chrome', function () {
    Livewire::test(Feed::class)
        ->call('setPollInterval', 5000)
        ->assertSet('pollInterval', 5000)
        ->call('setPollInterval', 0)
        ->assertSet('pollInterval', 0);
});
