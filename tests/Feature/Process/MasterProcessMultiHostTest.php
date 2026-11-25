<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisClient;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Process\WorkerGroup;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeName;

/*
 * Several hosts share one Redis and one prefix. Every master runs in this one
 * test process, so they all have the same PID: exactly the collision that
 * containers produce across hosts. Identity therefore has to come from the
 * master id and the node, never from the PID alone.
 *
 * Masters are driven through their tick methods against real Redis rather
 * than start(), which would fork a fleet.
 */

/** A PID no child of any test master has. */
const MULTI_HOST_PID = 4242;

/** What the fake fork returns: the PID of a worker spawned by a scale-up. */
const MULTI_HOST_FORKED_PID = 777001;

function multiHostForgetKeys(RedisClient $redis, string $prefix): void
{
    $cursor = '0';

    do {
        $reply = Narrow::list($redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '500'));
        $cursor = Narrow::string($reply[0] ?? '0', '0');

        foreach (Narrow::list($reply[1] ?? []) as $key) {
            $redis->execute('DEL', Narrow::string($key));
        }
    } while ($cursor !== '0');
}

beforeEach(function () {
    NodeName::resetForTesting();

    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-multi-host-'.bin2hex(random_bytes(4)).':';
    $this->logged = [];

    $this->master = fn (string $node, array $config = []): MasterProcess => new MasterProcess(array_replace([
        'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
        'consumer_group' => 'torque-test',
        'streams' => ['default' => []],
        'workers' => 1,
        'reload_pause_seconds' => 300,
        'drain_grace_seconds' => 60,
        'node' => $node,
    ], $config), function (string $message) {
        $this->logged[] = $message;
    });

    $this->worker = function (string $workerId, array $fields): void {
        $pairs = [];

        foreach ($fields as $field => $value) {
            $pairs[] = $field;
            $pairs[] = (string) $value;
        }

        $this->redis->execute('HSET', $this->prefix.'worker:'.$workerId, ...$pairs);
    };

    $this->autoscaling = function (MasterProcess $master, int $minWorkers): void {
        (new ReflectionProperty($master, 'metricsPublisher'))->setValue($master, (new ReflectionMethod($master, 'makeMetricsPublisher'))->invoke($master));
        // The scaler lives on the master's only group, as startFleet() builds it.
        $group = (new ReflectionMethod($master, 'groups'))->invoke($master)['default'];
        (new ReflectionProperty($master, 'groups'))->setValue($master, ['default' => new WorkerGroup(
            $group->name,
            $group->queues,
            $group->target,
            $group->slots,
            new AutoScaler(redisUri: 'unused', minWorkers: $minWorkers, maxWorkers: 8, cooldownSeconds: 0),
        )]);
        (new ReflectionProperty($master, 'fork'))->setValue($master, fn (): int => MULTI_HOST_FORKED_PID);
    };

    $this->dead = fn (int $pid): bool => false;
});

afterEach(function () {
    multiHostForgetKeys($this->redis, $this->prefix);
    NodeName::resetForTesting();
});

it('gives a master an id of its node, its PID and a nonce, fixed for its lifetime', function () {
    $master = ($this->master)('host-a');
    $id = $master->masterId();

    expect(MasterRegistry::isValidMasterId($id))->toBeTrue()
        ->and($id)->toStartWith('host-a-'.getmypid().'-')
        ->and($master->masterId())->toBe($id)
        ->and($master->node())->toBe('host-a')
        ->and(($this->master)('host-a')->masterId())->not->toBe($id);
});

it('falls back to the process node name without a node in the config', function () {
    $master = ($this->master)('host-a', ['node' => null]);

    expect($master->node())->toBe(NodeName::current());
});

it('hands every worker its node and master id', function () {
    $master = ($this->master)('host-a', ['queues' => ['default', 'emails'], 'slots_per_worker' => 20]);
    $arguments = (new ReflectionMethod($master, 'workerArguments'))->invoke($master);

    expect(array_slice($arguments, 1))->toBe([
        'torque:worker',
        '--queues=default,emails',
        '--concurrency=20',
        '--node=host-a',
        '--master-id='.$master->masterId(),
        '--group=default',
    ]);
});

it('keeps a foreign worker with a colliding PID out of its own metrics and the aggregate', function () {
    $master = ($this->master)('host-a');
    (new ReflectionProperty($master, 'workerPids'))->setValue($master, [MULTI_HOST_PID => true]);
    (new ReflectionProperty($master, 'metricsPublisher'))->setValue($master, (new ReflectionMethod($master, 'makeMetricsPublisher'))->invoke($master));

    $own = 'host-a-'.MULTI_HOST_PID.'-aaaaaaaa';
    ($this->worker)($own, ['pid' => MULTI_HOST_PID, 'host' => 'host-a', 'master' => $master->masterId(), 'active_slots' => 1, 'total_slots' => 10, 'jobs_processed' => 5]);
    // Another host's worker, naming its own master.
    ($this->worker)('host-b-'.MULTI_HOST_PID.'-bbbbbbbb', ['pid' => MULTI_HOST_PID, 'host' => 'host-b', 'master' => 'host-b-'.MULTI_HOST_PID.'-12345678', 'active_slots' => 9, 'total_slots' => 10, 'jobs_processed' => 500]);
    // Another host's worker of older code, without a master field.
    ($this->worker)('host-b-'.MULTI_HOST_PID.'-cccccccc', ['pid' => MULTI_HOST_PID, 'host' => 'host-b', 'active_slots' => 9, 'total_slots' => 10, 'jobs_processed' => 500]);
    // A worker on our node that names another master (a takeover fleet).
    ($this->worker)('host-a-'.MULTI_HOST_PID.'-dddddddd', ['pid' => MULTI_HOST_PID, 'host' => 'host-a', 'master' => 'host-a-'.MULTI_HOST_PID.'-87654321', 'active_slots' => 9, 'total_slots' => 10, 'jobs_processed' => 500]);

    $publisher = (new ReflectionProperty($master, 'metricsPublisher'))->getValue($master);
    $ownMetrics = (new ReflectionMethod($master, 'ownWorkerMetrics'))->invoke($master, $publisher->getAllWorkerMetrics());

    expect(array_keys($ownMetrics))->toBe([$own]);

    (new ReflectionMethod($master, 'publishAggregateMetrics'))->invoke($master);

    // Under the master's own node, not the process-wide name.
    $live = $this->prefix.'metrics:live:host-a';

    expect($this->redis->execute('HGET', $live, 'workers'))->toBe('1')
        ->and($this->redis->execute('HGET', $live, 'concurrent'))->toBe('1')
        ->and($this->redis->execute('HGET', $live, 'jobs_processed'))->toBe('5');
});

it('accepts a worker of older code without a master field when it runs on its node', function () {
    $master = ($this->master)('host-a');
    (new ReflectionProperty($master, 'workerPids'))->setValue($master, [MULTI_HOST_PID => true]);
    $isOwn = new ReflectionMethod($master, 'isOwnWorker');

    expect($isOwn->invoke($master, 'host-a-'.MULTI_HOST_PID.'-aaaaaaaa', ['pid' => (string) MULTI_HOST_PID, 'host' => 'host-a']))->toBeTrue()
        ->and($isOwn->invoke($master, 'host-a-'.MULTI_HOST_PID.'-aaaaaaaa', ['pid' => (string) MULTI_HOST_PID]))->toBeTrue()
        ->and($isOwn->invoke($master, 'host-b-'.MULTI_HOST_PID.'-aaaaaaaa', ['pid' => (string) MULTI_HOST_PID]))->toBeFalse()
        // Our id, but not one of our children (a crashed child's lingering hash).
        ->and($isOwn->invoke($master, 'host-a-9999-aaaaaaaa', ['pid' => '9999', 'host' => 'host-a', 'master' => $master->masterId()]))->toBeFalse();
});

it('keeps a foreign worker with a colliding PID out of the autoscale input', function () {
    $master = ($this->master)('host-a');
    ($this->autoscaling)($master, minWorkers: 1);
    (new ReflectionProperty($master, 'workerPids'))->setValue($master, [MULTI_HOST_PID => true]);

    // Only another host's workers are busy; ours has not published yet.
    ($this->worker)('host-b-'.MULTI_HOST_PID.'-bbbbbbbb', ['pid' => MULTI_HOST_PID, 'host' => 'host-b', 'master' => 'host-b-'.MULTI_HOST_PID.'-12345678', 'active_slots' => 50, 'total_slots' => 50]);
    ($this->worker)('host-b-'.MULTI_HOST_PID.'-cccccccc', ['pid' => MULTI_HOST_PID, 'host' => 'host-b', 'active_slots' => 50, 'total_slots' => 50]);

    (new ReflectionMethod($master, 'evaluateAutoscale'))->invoke($master);

    expect($master->workerPids)->toBe([MULTI_HOST_PID => true]);

    // The same load reported by our own worker does scale up.
    ($this->worker)('host-a-'.MULTI_HOST_PID.'-aaaaaaaa', ['pid' => MULTI_HOST_PID, 'host' => 'host-a', 'master' => $master->masterId(), 'active_slots' => 50, 'total_slots' => 50]);

    (new ReflectionMethod($master, 'evaluateAutoscale'))->invoke($master);

    expect($master->workerPids)->toBe([MULTI_HOST_PID => true, MULTI_HOST_FORKED_PID => true]);
});

it('does not grow into a pause of its own node, only of another', function () {
    $master = ($this->master)('host-a');
    ($this->autoscaling)($master, minWorkers: 1);
    $evaluate = new ReflectionMethod($master, 'evaluateAutoscale');

    // Below the floor: the scaler asks for a worker on every pass.
    (new PauseScopes($this->redis, $this->prefix))->pauseNode('host-a');
    $evaluate->invoke($master);

    expect($master->workerPids)->toBe([]);

    (new PauseScopes($this->redis, $this->prefix))->resumeNode('host-a');
    (new PauseScopes($this->redis, $this->prefix))->pauseNode('host-b');
    $evaluate->invoke($master);

    expect($master->workerPids)->toBe([MULTI_HOST_FORKED_PID => true]);
});

it('keeps host A drain key through a start on host B with the same PID', function () {
    $a = ($this->master)('host-a');
    (new ReflectionProperty($a, 'drainRequested'))->setValue($a, true);
    $a->handleDrainTick();

    $drainKey = PauseScopes::drainKey($this->prefix, $a->masterId());

    expect((int) $this->redis->execute('TTL', $drainKey))->toBeGreaterThan(0);

    NodeName::resetForTesting();
    $b = ($this->master)('host-b');
    (new ReflectionMethod($b, 'warnIfPaused'))->invoke($b);

    $scopes = new PauseScopes($this->redis, $this->prefix);

    expect((int) $this->redis->execute('EXISTS', $drainKey))->toBe(1)
        ->and($scopes->drainValue($a->masterId()))->not->toBeNull()
        // B's fleet reads its own key, which A's drain never wrote.
        ->and($scopes->drainValue($b->masterId()))->toBeNull()
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'paused'))->toBe(0)
        ->and(implode("\n", $this->logged))->not->toContain('Cleared stale drain pause');
});

it('never replaces a global pause with a drain, and removes only its own key at the end', function () {
    $this->redis->execute('SET', $this->prefix.'paused', '1700000000');
    $other = PauseScopes::drainKey($this->prefix, 'host-b-'.MULTI_HOST_PID.'-12345678');
    $this->redis->execute('SET', $other, '1700000000', 'EX', '300');

    $master = ($this->master)('host-a');
    (new ReflectionProperty($master, 'drainRequested'))->setValue($master, true);
    $master->handleDrainTick();

    $own = PauseScopes::drainKey($this->prefix, $master->masterId());

    expect($this->redis->execute('GET', $this->prefix.'paused'))->toBe('1700000000')
        ->and((int) $this->redis->execute('TTL', $this->prefix.'paused'))->toBe(-1)
        ->and((int) $this->redis->execute('EXISTS', $own))->toBe(1);

    (new ReflectionMethod($master, 'endDrain'))->invoke($master);

    expect((int) $this->redis->execute('EXISTS', $own))->toBe(0)
        ->and((int) $this->redis->execute('EXISTS', $other))->toBe(1)
        ->and($this->redis->execute('GET', $this->prefix.'paused'))->toBe('1700000000')
        ->and((int) $this->redis->execute('TTL', $this->prefix.'paused'))->toBe(-1);
});

it('keeps a legacy drain while another host is live and clears it when none is', function () {
    $this->redis->execute('SET', $this->prefix.'paused', 'drain:424242', 'EX', '7260');
    $this->redis->execute('ZADD', $this->prefix.'metrics:live', (string) time(), 'host-b');
    $this->redis->execute('ZADD', $this->prefix.'metrics:live', (string) time(), 'host-a');

    $master = ($this->master)('host-a');

    expect($master->clearStaleDrainPause($this->dead))->toBeFalse()
        ->and($this->redis->execute('GET', $this->prefix.'paused'))->toBe('drain:424242');

    // Host B stopped publishing a while ago: it is out of the live window.
    $this->redis->execute('ZADD', $this->prefix.'metrics:live', (string) (time() - 120), 'host-b');

    expect($master->clearStaleDrainPause($this->dead))->toBeTrue()
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'paused'))->toBe(0);
});
