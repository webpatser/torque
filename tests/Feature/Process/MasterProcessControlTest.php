<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisClient;
use Webpatser\Torque\Control\ControlChannel;
use Webpatser\Torque\Control\LeaderLease;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Redis\UpgradeRunner;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeName;

/*
 * The cluster side of a master: its registry record, the remote commands it
 * reads from its control list and the leader lease that decides which master
 * runs the once-per-cluster duties. Driven through the public tick methods
 * against real Redis rather than start(), which would fork a fleet. Every
 * master here runs in this one process, so they share a PID and differ by
 * node and nonce, as masters on different hosts do.
 */

function controlForgetKeys(RedisClient $redis, string $prefix): void
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

function controlProperty(MasterProcess $master, string $name): mixed
{
    return (new ReflectionProperty($master, $name))->getValue($master);
}

function controlSetProperty(MasterProcess $master, string $name, mixed $value): void
{
    (new ReflectionProperty($master, $name))->setValue($master, $value);
}

beforeEach(function () {
    NodeName::resetForTesting();
    putenv(MasterProcess::SUPERVISED_ENV);

    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-control-'.bin2hex(random_bytes(4)).':';
    $this->logged = [];

    $this->master = fn (string $node, array $config = []): MasterProcess => new MasterProcess(array_replace([
        'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
        'consumer_group' => 'torque-test',
        'streams' => ['default' => []],
        'workers' => 1,
        'node' => $node,
    ], $config), function (string $message) {
        $this->logged[] = $message;
    });

    $this->registry = new MasterRegistry($this->redis, $this->prefix);
    $this->channel = new ControlChannel($this->redis, $this->prefix);
    $this->log = fn (): string => implode("\n", $this->logged);
});

afterEach(function () {
    controlForgetKeys($this->redis, $this->prefix);
    putenv(MasterProcess::SUPERVISED_ENV);
    NodeName::resetForTesting();
});

it('publishes its record at start and follows its state', function () {
    putenv(MasterProcess::SUPERVISED_ENV.'=1');

    $master = ($this->master)('host-a', ['queues' => ['default', 'emails'], 'slots_per_worker' => 20]);
    $master->handleRegistryTick();

    $record = $this->registry->find($master->masterId());

    expect($record)->not->toBeNull()
        ->and($record['node'])->toBe('host-a')
        ->and($record['pid'])->toBe(getmypid())
        ->and($record['started_at'])->toBeGreaterThan(0)
        ->and($record['version'])->toBe(UpgradeRunner::installedVersion())
        ->and($record['supervised'])->toBeTrue()
        ->and($record['queues'])->toBe(['default', 'emails'])
        ->and($record['workers'])->toBe(0)
        ->and($record['slots'])->toBe(20)
        ->and($record['state'])->toBe(MasterProcess::STATE_STARTING)
        ->and($record['heartbeat'])->not->toBeNull();

    // A state change publishes at once, inside the heartbeat interval.
    controlSetProperty($master, 'monitoring', true);
    $master->handleRegistryTick();
    expect($this->registry->find($master->masterId())['state'])->toBe(MasterProcess::STATE_RUNNING);

    controlSetProperty($master, 'drainRequested', true);
    $master->handleRegistryTick();
    expect($this->registry->find($master->masterId())['state'])->toBe(MasterProcess::STATE_DRAINING);

    controlSetProperty($master, 'shouldStop', true);
    $master->handleRegistryTick();
    expect($this->registry->find($master->masterId())['state'])->toBe(MasterProcess::STATE_STOPPING);
});

it('removes its record and releases the lease when it leaves', function () {
    $master = ($this->master)('host-a');
    $master->handleRegistryTick();
    $master->maintainLeadership();

    expect($this->registry->find($master->masterId()))->not->toBeNull()
        ->and((new LeaderLease($this->redis, $this->prefix))->leader())->toBe($master->masterId());

    (new ReflectionMethod($master, 'leaveCluster'))->invoke($master);

    expect($this->registry->find($master->masterId()))->toBeNull()
        ->and($this->redis->execute('ZSCORE', $this->prefix.'masters', $master->masterId()))->toBeNull()
        ->and((new LeaderLease($this->redis, $this->prefix))->leader())->toBeNull()
        ->and($master->isLeader)->toBeFalse();
});

it('is unsupervised unless the environment says so', function () {
    $master = ($this->master)('host-a');
    $master->handleRegistryTick();

    expect($this->registry->find($master->masterId())['supervised'])->toBeFalse();
});

it('reads only an explicit boolean as supervised', function (?string $value, bool $supervised) {
    expect(MasterProcess::supervisedFromEnvironment($value))->toBe($supervised);
})->with([
    'unset' => [null, false],
    'empty' => ['', false],
    'one' => ['1', true],
    'true' => ['true', true],
    'yes with spaces' => [' yes ', true],
    'zero' => ['0', false],
    'false' => ['false', false],
    'garbage' => ['supervisord', false],
]);

it('stops on a stop command and acks it', function () {
    $master = ($this->master)('host-a');
    $command = $this->channel->send($master->masterId(), ControlChannel::ACTION_STOP, 'ops@deploy-1');

    $master->handleControlTick();

    $ack = $this->channel->readAck($command['id']);

    expect(controlProperty($master, 'shouldStop'))->toBeTrue()
        ->and(controlProperty($master, 'drainRequested'))->toBeFalse()
        ->and($ack)->not->toBeNull()
        ->and($ack['accepted'])->toBeTrue()
        ->and($ack['action'])->toBe('stop')
        ->and($ack['master'])->toBe($master->masterId())
        ->and((int) $this->redis->execute('LLEN', $this->channel->listKey($master->masterId())))->toBe(0)
        ->and(($this->log)())->toContain("Control: stop command {$command['id']} from ops@deploy-1 accepted.");
});

it('requests a drain on a drain command and acks it', function () {
    $master = ($this->master)('host-a');
    $command = $this->channel->send($master->masterId(), ControlChannel::ACTION_DRAIN, 'ops');

    $master->handleControlTick();

    $ack = $this->channel->readAck($command['id']);

    expect(controlProperty($master, 'drainRequested'))->toBeTrue()
        ->and(controlProperty($master, 'shouldStop'))->toBeFalse()
        ->and($ack['accepted'])->toBeTrue()
        ->and($ack['action'])->toBe('drain')
        ->and(($this->log)())->toContain("Control: drain command {$command['id']} from ops accepted.");
});

it('rejects an expired command and acks it as not accepted', function () {
    $master = ($this->master)('host-a');
    $issued = time() - 120;
    $old = new ControlChannel($this->redis, $this->prefix, 60, static fn (): int => $issued);
    $command = $old->send($master->masterId(), ControlChannel::ACTION_STOP, 'ops');

    $master->handleControlTick();

    $ack = $this->channel->readAck($command['id']);

    expect(controlProperty($master, 'shouldStop'))->toBeFalse()
        ->and($ack['accepted'])->toBeFalse()
        ->and($ack['action'])->toBe('stop')
        ->and((int) $this->redis->execute('LLEN', $this->channel->listKey($master->masterId())))->toBe(0)
        ->and(($this->log)())->toContain("Control: rejected command {$command['id']} stop from ops (expired); dropped.");
});

it('rejects an unknown action and acks it as not accepted', function () {
    $master = ($this->master)('host-a');
    $id = bin2hex(random_bytes(16));

    $this->redis->execute('RPUSH', $this->channel->listKey($master->masterId()), json_encode([
        'id' => $id,
        'action' => 'reboot',
        'issued_at' => time(),
        'expires_at' => time() + 60,
        'issuer' => 'ops',
    ]));

    $master->handleControlTick();

    $ack = $this->channel->readAck($id);

    expect(controlProperty($master, 'shouldStop'))->toBeFalse()
        ->and(controlProperty($master, 'drainRequested'))->toBeFalse()
        ->and($ack['accepted'])->toBeFalse()
        ->and($ack['action'])->toBe('unknown')
        ->and(($this->log)())->toContain("Control: rejected command {$id} (unknown_action); dropped.");
});

it('drops a malformed command without an id after logging it', function () {
    $master = ($this->master)('host-a');
    $this->redis->execute('RPUSH', $this->channel->listKey($master->masterId()), 'not json');

    $master->handleControlTick();

    expect(controlProperty($master, 'shouldStop'))->toBeFalse()
        ->and((int) $this->redis->execute('LLEN', $this->channel->listKey($master->masterId())))->toBe(0)
        ->and(($this->log)())->toContain('Control: rejected command (no valid id) (malformed); dropped.');
});

it('consumes nothing while remote control is disabled', function (mixed $enabled) {
    $master = ($this->master)('host-a', ['control' => ['enabled' => $enabled]]);
    $this->channel->send($master->masterId(), ControlChannel::ACTION_STOP, 'ops');

    $master->handleControlTick();

    expect(controlProperty($master, 'shouldStop'))->toBeFalse()
        ->and((int) $this->redis->execute('LLEN', $this->channel->listKey($master->masterId())))->toBe(1)
        ->and($this->logged)->toBe([]);
})->with([
    'false' => [false],
    'zero string' => ['0'],
    'off' => ['off'],
    'unrecognisable' => ['disabled'],
]);

it('leaves a command for another master in its list', function () {
    $master = ($this->master)('host-a');
    $other = 'host-b-'.getmypid().'-12345678';
    $this->channel->send($other, ControlChannel::ACTION_STOP, 'ops');

    $master->handleControlTick();

    expect(controlProperty($master, 'shouldStop'))->toBeFalse()
        ->and((int) $this->redis->execute('LLEN', $this->channel->listKey($other)))->toBe(1);
});

it('runs housekeeping and the long-wait check on the leader only', function () {
    $config = [
        'dead_letter' => ['ttl' => 60, 'prune_interval' => 300, 'max_entries' => 0],
        'waits' => ['default' => 60],
    ];

    $a = ($this->master)('host-a', $config);
    $b = ($this->master)('host-b', $config);

    expect($a->maintainLeadership())->toBeTrue()
        ->and($b->maintainLeadership())->toBeFalse()
        ->and($a->isLeader)->toBeTrue()
        ->and($b->isLeader)->toBeFalse();

    $this->redis->execute('XADD', $this->prefix.'dead-letter', ((time() - 3600) * 1000).'-0', 'payload', 'stale');

    $b->handleClusterDuties();

    expect((int) $this->redis->execute('XLEN', $this->prefix.'dead-letter'))->toBe(1)
        ->and(controlProperty($b, 'housekeepingDueAt'))->toBeNull()
        ->and(controlProperty($b, 'longWaitDueAt'))->toBeNull();

    $a->handleClusterDuties();

    expect((int) $this->redis->execute('XLEN', $this->prefix.'dead-letter'))->toBe(0)
        ->and(controlProperty($a, 'housekeepingDueAt'))->not->toBeNull()
        ->and(controlProperty($a, 'longWaitDueAt'))->not->toBeNull();
});

it('hands the lease to a second master once the first gives it up', function () {
    $a = ($this->master)('host-a');
    $b = ($this->master)('host-b');

    expect($a->maintainLeadership())->toBeTrue()
        ->and($b->maintainLeadership())->toBeFalse()
        // Renewal keeps it.
        ->and($a->maintainLeadership())->toBeTrue();

    // A drain gives the lease up on the next tick.
    controlSetProperty($a, 'drainRequested', true);

    expect($a->maintainLeadership())->toBeFalse()
        ->and($b->maintainLeadership())->toBeTrue()
        ->and((new LeaderLease($this->redis, $this->prefix))->leader())->toBe($b->masterId())
        ->and(($this->log)())->toContain('Leader lease released: this master is draining.');

    // And a master that leaves hands it on as well.
    (new ReflectionMethod($b, 'leaveCluster'))->invoke($b);
    $c = ($this->master)('host-c');

    expect($c->maintainLeadership())->toBeTrue();
});

it('keeps ticking through a Redis outage and reads as not leader', function () {
    $master = ($this->master)('host-a', ['redis' => ['uri' => 'redis://127.0.0.1:6399', 'prefix' => $this->prefix]]);
    controlSetProperty($master, 'isLeader', true);

    expect($master->maintainLeadership())->toBeFalse()
        ->and($master->maintainLeadership())->toBeFalse();

    $master->handleControlTick();
    $master->handleRegistryTick();
    $master->handleClusterDuties();

    $warnings = array_values(array_filter($this->logged, fn (string $line): bool => str_starts_with($line, 'WARN ')));

    // Once per duty and outage, not once per tick.
    expect($warnings)->toHaveCount(3)
        ->and($warnings[0])->toContain('Leader lease failed')
        ->and($warnings[1])->toContain('Control channel failed')
        ->and($warnings[2])->toContain('Master registry failed');
});
