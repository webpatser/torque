<?php

declare(strict_types=1);
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Control\LeaderLease;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Metrics\WorkerSnapshot;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\NodeName;

it('is registered as an artisan command', function () {
    $commands = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all());

    expect($commands->has('torque:status'))->toBeTrue();
});

it('runs without error even with no metrics in Redis', function () {
    // The status command connects to Redis to read metrics.
    // If Redis is unavailable, it will throw, that's expected in CI.
    try {
        $this->artisan('torque:status')
            ->assertSuccessful();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('shows master status as stopped when no PID file exists', function () {
    $pidFile = storage_path('torque.pid');

    if (file_exists($pidFile)) {
        unlink($pidFile);
    }

    try {
        $this->artisan('torque:status')
            ->assertSuccessful()
            ->expectsOutputToContain('STOPPED');
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('renders the worker pid and latency from the published hash', function () {
    $publisher = new MetricsPublisher(
        redisUri: (string) config('torque.redis.uri'),
        prefix: (string) config('torque.redis.prefix'),
    );

    try {
        $publisher->publishWorkerMetrics('web-01-5123-a1b2c3d4', new WorkerSnapshot(
            jobsProcessed: 42,
            jobsFailed: 3,
            activeSlots: 7,
            totalSlots: 50,
            averageLatencyMs: 12.5678,
            slotUsageRatio: 0.14,
            memoryBytes: 1_048_576,
            timestamp: time(),
        ));

        Artisan::call('torque:status');
        $output = Artisan::output();

        expect($output)->toContain('5123')
            ->and($output)->toContain('12.57 ms')
            ->and($output)->toContain('7/50')
            ->and($output)->not->toContain('| ?')
            // Live queue depth and dead-letter counts render as numbers, not
            // placeholders, even without a master-published aggregate.
            ->and($output)->toContain('Waiting');

        $publisher->removeWorkerMetrics('web-01-5123-a1b2c3d4');
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

it('says nothing about pausing while the queue is running', function () {
    $redis = torqueRedis();
    $redis->execute('DEL', (string) config('torque.redis.prefix').'paused');

    Artisan::call('torque:status');

    expect(Artisan::output())->not->toContain('Queue PAUSED');
});

it('names the master a drain pause belongs to', function () {
    $redis = torqueRedis();
    $key = (string) config('torque.redis.prefix').'paused';

    $redis->execute('SET', $key, 'drain:424242', 'EX', '60');

    Artisan::call('torque:status');
    $output = Artisan::output();

    $redis->execute('DEL', $key);

    expect($output)->toContain('Queue PAUSED')
        ->toContain('drain from master PID 424242');
});

it('shows the respawns a master owes after failed forks', function () {
    $redis = torqueRedis();
    $prefix = (string) config('torque.redis.prefix');
    $host = 'owed-test-host';
    $key = $prefix.'metrics:live:'.$host;

    $redis->execute('HSET', $key, 'workers', '0', MasterProcess::PENDING_RESPAWNS_FIELD, '3');
    $redis->execute('ZADD', $prefix.'metrics:live', (string) time(), $host);

    try {
        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        $redis->execute('DEL', $key);
        $redis->execute('ZREM', $prefix.'metrics:live', $host);
    }

    expect($output)->toContain('Respawns owed')
        ->toContain('3 on '.$host);
});

it('says nothing about respawns while none are owed', function () {
    torqueRedis();

    Artisan::call('torque:status');

    expect(Artisan::output())->not->toContain('Respawns owed');
});

/**
 * Point the command at a private key prefix so the multi-node tests own every
 * key they touch.
 */
function statusTestPrefix(): string
{
    $prefix = 'torque-test-status-'.bin2hex(random_bytes(4)).':';
    config()->set('torque.redis.prefix', $prefix);
    NodeName::resetForTesting();

    return $prefix;
}

function statusTestCleanup(string $prefix): void
{
    $redis = torqueRedis();

    foreach (['masters', 'pauses', 'leader'] as $suffix) {
        $redis->execute('DEL', $prefix.$suffix);
    }

    foreach ((array) $redis->execute('KEYS', $prefix.'*') as $key) {
        $redis->execute('DEL', (string) $key);
    }

    config()->set('torque.redis.prefix', 'torque-test:');
}

/**
 * @return array<string, mixed>
 */
function statusTestRecord(string $node, int $pid, bool $supervised = true): array
{
    return [
        'node' => $node,
        'pid' => $pid,
        'started_at' => time(),
        'version' => '2.5.0',
        'supervised' => $supervised,
        'queues' => ['default'],
        'workers' => 2,
        'slots' => 10,
        'state' => 'running',
    ];
}

function statusTestPublishTwoMasters(RedisClient $redis, string $prefix): void
{
    $registry = new MasterRegistry($redis, $prefix);
    $registry->publish('web-1-4711-a1b2c3d4', statusTestRecord('web-1', 4711, true));
    $registry->publish('batch-1-4812-b2c3d4e5', statusTestRecord('batch-1', 4812, false));
}

afterEach(function () {
    NodeName::resetForTesting();
});

it('lists every master with its node, state and supervision', function () {
    $prefix = statusTestPrefix();
    $redis = torqueRedis();

    try {
        statusTestPublishTwoMasters($redis, $prefix);

        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('Node')->toContain('Supervised')->toContain('Leader')
        ->toContain('web-1')->toContain('4711')
        ->toContain('batch-1')->toContain('4812')
        ->toContain('running')->toContain('2.5.0');

    expect($output)->toMatch('/^\|\s*web-1\s*\|\s*4711\s*\|.*\|\s*yes\s*\|/m')
        ->and($output)->toMatch('/^\|\s*batch-1\s*\|\s*4812\s*\|.*\|\s*no\s*\|/m');
});

/**
 * @param  array<string, array{queues: list<string>, workers: int, target: int, min: int, max: int, slots: int}>  $groups
 */
function statusTestPublishWithGroups(RedisClient $redis, string $prefix, string $id, string $node, int $pid, array $groups): void
{
    new MasterRegistry($redis, $prefix)->publish($id, statusTestRecord($node, $pid) + ['groups' => $groups]);
}

it('prints a groups sub-table for a master with several groups', function () {
    $prefix = statusTestPrefix();
    $redis = torqueRedis();

    try {
        statusTestPublishWithGroups($redis, $prefix, 'web-1-4711-a1b2c3d4', 'web-1', 4711, [
            'default' => ['queues' => ['default'], 'workers' => 2, 'target' => 2, 'min' => 1, 'max' => 4, 'slots' => 10],
            'mail' => ['queues' => ['emails', 'sms'], 'workers' => 3, 'target' => 3, 'min' => 2, 'max' => 6, 'slots' => 15],
        ]);

        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('Groups')->toContain('Queues')
        ->toMatch('/^\|\s*default\s*\|\s*default\s*\|\s*2\s*\|\s*2\s*\|\s*1\s*\|\s*4\s*\|\s*10\s*\|$/m')
        ->toMatch('/^\|\s*mail\s*\|\s*emails, sms\s*\|\s*3\s*\|\s*3\s*\|\s*2\s*\|\s*6\s*\|\s*15\s*\|$/m');
});

it('prints no groups sub-table for a master with only the default group', function () {
    $prefix = statusTestPrefix();
    $redis = torqueRedis();

    try {
        statusTestPublishWithGroups($redis, $prefix, 'web-1-4711-a1b2c3d4', 'web-1', 4711, [
            'default' => ['queues' => ['default'], 'workers' => 2, 'target' => 2, 'min' => 1, 'max' => 4, 'slots' => 10],
        ]);

        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('4711')->not->toContain('Groups')->not->toContain('Target');
});

it('prints no groups sub-table when groups is empty', function () {
    $prefix = statusTestPrefix();
    $redis = torqueRedis();

    try {
        statusTestPublishWithGroups($redis, $prefix, 'web-1-4711-a1b2c3d4', 'web-1', 4711, []);

        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('4711')->not->toContain('Groups')->not->toContain('Target');
});

it('filters the groups sub-tables with --node', function () {
    $prefix = statusTestPrefix();
    $redis = torqueRedis();

    try {
        statusTestPublishWithGroups($redis, $prefix, 'web-1-4711-a1b2c3d4', 'web-1', 4711, [
            'mail' => ['queues' => ['emails'], 'workers' => 3, 'target' => 3, 'min' => 2, 'max' => 6, 'slots' => 15],
        ]);
        statusTestPublishWithGroups($redis, $prefix, 'batch-1-4812-b2c3d4e5', 'batch-1', 4812, [
            'bulk' => ['queues' => ['imports'], 'workers' => 1, 'target' => 1, 'min' => 1, 'max' => 2, 'slots' => 5],
        ]);

        Artisan::call('torque:status', ['--node' => 'web-1']);
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('emails')->toContain('mail')
        ->not->toContain('imports')->not->toContain('bulk')->not->toContain('batch-1');
});

it('marks the master holding the leader lease', function () {
    $prefix = statusTestPrefix();
    $redis = torqueRedis();

    try {
        statusTestPublishTwoMasters($redis, $prefix);
        expect(new LeaderLease($redis, $prefix)->acquire('batch-1-4812-b2c3d4e5'))->toBeTrue();

        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toMatch('/^\|\s*batch-1\s*\|.*\|\s*\*\s*\|$/m')
        ->and($output)->not->toMatch('/^\|\s*web-1\s*\|.*\*.*\|$/m');
});

it('filters the masters table to one node with --node', function () {
    $prefix = statusTestPrefix();
    $redis = torqueRedis();

    try {
        statusTestPublishTwoMasters($redis, $prefix);

        Artisan::call('torque:status', ['--node' => 'web-1']);
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('4711')->not->toContain('4812')->not->toContain('batch-1');
});

it('filters the worker output to one node with --node', function () {
    $prefix = statusTestPrefix();
    $publisher = new MetricsPublisher(redisUri: (string) config('torque.redis.uri'), prefix: $prefix);

    try {
        foreach (['web-1-5123-a1b2c3d4' => 7, 'batch-1-6234-b2c3d4e5' => 9] as $id => $active) {
            $publisher->publishWorkerMetrics($id, new WorkerSnapshot(
                jobsProcessed: 1,
                jobsFailed: 0,
                activeSlots: $active,
                totalSlots: 50,
                averageLatencyMs: 1.0,
                slotUsageRatio: 0.1,
                memoryBytes: 1_048_576,
                timestamp: time(),
            ));
        }

        Artisan::call('torque:status', ['--node' => 'web-1']);
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('5123')->not->toContain('6234');
});

it('shows paused nodes and paused queues', function () {
    $prefix = statusTestPrefix();
    $redis = torqueRedis();

    try {
        $scopes = new PauseScopes($redis, $prefix);
        $scopes->pauseNode('batch-1');
        $scopes->pauseQueue('emails');

        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('Nodes PAUSED')->toContain('batch-1')
        ->toContain('Queues PAUSED')->toContain('emails');
});

it('says nothing about scopes while none are paused', function () {
    $prefix = statusTestPrefix();
    torqueRedis();

    try {
        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->not->toContain('Nodes PAUSED')->not->toContain('Queues PAUSED');
});

it('keeps the old output when no master has published a record', function () {
    $prefix = statusTestPrefix();
    torqueRedis();

    try {
        Artisan::call('torque:status');
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($output)->toContain('Master Status')->toContain('Waiting')
        ->not->toContain('Supervised')->not->toContain('Leader');
});

it('refuses an invalid --node value', function () {
    $prefix = statusTestPrefix();

    try {
        $exitCode = Artisan::call('torque:status', ['--node' => 'bad node!']);
        $output = Artisan::output();
    } finally {
        statusTestCleanup($prefix);
    }

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Invalid Torque node name')
        ->and($output)->not->toContain('Master Status');
});

it('distinguishes a manual pause from a drain', function () {
    $redis = torqueRedis();
    $key = (string) config('torque.redis.prefix').'paused';

    $redis->execute('SET', $key, (string) time());

    Artisan::call('torque:status');
    $output = Artisan::output();

    $redis->execute('DEL', $key);

    expect($output)->toContain('Queue PAUSED')
        ->toContain('manual')
        ->not->toContain('drain from master');
});
