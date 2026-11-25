<?php

declare(strict_types=1);

use Webpatser\Torque\Control\MasterRegistry;

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-registry-'.bin2hex(random_bytes(4)).':';
    $this->now = 1_800_000_000;
    $this->registry = new MasterRegistry($this->redis, $this->prefix, fn (): int => $this->now);
    $this->ids = [];

    $this->record = fn (array $overrides = []): array => [
        'node' => 'web-1',
        'pid' => 4321,
        'started_at' => 1_799_999_000,
        'version' => '2.5.0',
        'supervised' => true,
        'queues' => ['default', 'emails'],
        'workers' => 4,
        'slots' => 50,
        'state' => 'running',
        ...$overrides,
    ];

    $this->publish = function (string $id, array $overrides = []): void {
        $this->ids[] = $id;
        $this->registry->publish($id, ($this->record)($overrides));
    };
});

afterEach(function () {
    $this->redis->execute('DEL', $this->prefix.'masters');

    foreach (array_unique($this->ids) as $id) {
        $this->redis->execute('DEL', $this->prefix.'master:'.$id);
    }
});

it('publishes a record and reads it back', function () {
    ($this->publish)('web-1-4321-0a1b2c3d');

    expect($this->registry->find('web-1-4321-0a1b2c3d'))->toBe([
        'master_id' => 'web-1-4321-0a1b2c3d',
        'node' => 'web-1',
        'pid' => 4321,
        'started_at' => 1_799_999_000,
        'version' => '2.5.0',
        'supervised' => true,
        'queues' => ['default', 'emails'],
        'workers' => 4,
        'slots' => 50,
        'state' => 'running',
        'groups' => [],
        'heartbeat' => $this->now,
    ]);

    expect($this->redis->execute('TTL', $this->prefix.'master:web-1-4321-0a1b2c3d'))->toBe(MasterRegistry::RECORD_TTL_SECONDS)
        ->and($this->redis->execute('TTL', $this->prefix.'masters'))->toBe(MasterRegistry::INDEX_TTL_SECONDS);
});

it('lists every live master ordered by id', function () {
    ($this->publish)('web-2-11-00000002', ['node' => 'web-2', 'pid' => 11]);
    ($this->publish)('web-1-10-00000001', ['node' => 'web-1', 'pid' => 10]);

    expect(array_column($this->registry->all(), 'master_id'))->toBe(['web-1-10-00000001', 'web-2-11-00000002']);
});

it('prunes index entries older than the stale window on write', function () {
    ($this->publish)('old-1-10-00000001', ['node' => 'old-1', 'pid' => 10]);

    $this->now += MasterRegistry::STALE_AFTER_SECONDS + 1;
    ($this->publish)('new-1-11-00000002', ['node' => 'new-1', 'pid' => 11]);

    expect($this->redis->execute('ZSCORE', $this->prefix.'masters', 'old-1-10-00000001'))->toBeNull()
        ->and(array_column($this->registry->all(), 'master_id'))->toBe(['new-1-11-00000002']);
});

it('keeps an entry exactly at the edge of the stale window', function () {
    ($this->publish)('edge-1-10-00000001', ['node' => 'edge-1', 'pid' => 10]);

    $this->now += MasterRegistry::STALE_AFTER_SECONDS;
    ($this->publish)('new-1-11-00000002', ['node' => 'new-1', 'pid' => 11]);

    expect(array_column($this->registry->all(), 'master_id'))->toBe(['edge-1-10-00000001', 'new-1-11-00000002']);
});

it('skips a listed master whose record expired', function () {
    ($this->publish)('web-1-10-00000001');
    $this->redis->execute('DEL', $this->prefix.'master:web-1-10-00000001');

    expect($this->registry->all())->toBe([])
        ->and($this->registry->find('web-1-10-00000001'))->toBeNull();
});

it('skips index members that are not master ids', function () {
    ($this->publish)('web-1-10-00000001');
    $this->redis->execute('ZADD', $this->prefix.'masters', (string) $this->now, '../evil');
    $this->redis->execute('ZADD', $this->prefix.'masters', (string) $this->now, 'web-1-10-XYZ');

    expect(array_column($this->registry->all(), 'master_id'))->toBe(['web-1-10-00000001']);
});

it('reads a record with an invalid node or pid as missing', function (string $field, string $value) {
    ($this->publish)('web-1-10-00000001');
    $this->redis->execute('HSET', $this->prefix.'master:web-1-10-00000001', $field, $value);

    expect($this->registry->find('web-1-10-00000001'))->toBeNull()
        ->and($this->registry->all())->toBe([]);
})->with([
    'node with a space' => ['node', 'web 1'],
    'node too long' => ['node', str_repeat('a', 49)],
    'pid zero' => ['pid', '0'],
    'pid not numeric' => ['pid', '12x'],
]);

it('narrows the other fields of a tampered record', function () {
    ($this->publish)('web-1-10-00000001');
    $this->redis->execute(
        'HSET', $this->prefix.'master:web-1-10-00000001',
        'workers', '-3', 'slots', 'many', 'state', "running\nfake", 'version', 'x y', 'queues', 'default,bad queue,emails',
    );

    expect($this->registry->find('web-1-10-00000001'))
        ->toMatchArray(['workers' => 0, 'slots' => 0, 'state' => 'unknown', 'version' => 'unknown', 'queues' => ['default', 'emails']]);
});

it('round trips the groups field', function () {
    $groups = [
        'imports' => ['queues' => ['imports'], 'workers' => 2, 'target' => 3, 'min' => 2, 'max' => 12, 'slots' => 20],
        'mail' => ['queues' => ['emails', 'default'], 'workers' => 2, 'target' => 2, 'min' => 2, 'max' => 2, 'slots' => 100],
    ];

    ($this->publish)('web-1-10-00000001', ['groups' => $groups]);

    expect($this->registry->find('web-1-10-00000001')['groups'] ?? null)->toBe($groups);
});

it('drops a groups field left by an earlier publish', function () {
    ($this->publish)('web-1-10-00000001', ['groups' => ['mail' => ['queues' => ['emails'], 'workers' => 1, 'target' => 1, 'min' => 1, 'max' => 1, 'slots' => 1]]]);
    ($this->publish)('web-1-10-00000001');

    expect($this->redis->execute('HEXISTS', $this->prefix.'master:web-1-10-00000001', 'groups'))->toBe(0);
});

it('reads an invalid groups field as an empty list', function (string $raw) {
    ($this->publish)('web-1-10-00000001');
    $this->redis->execute('HSET', $this->prefix.'master:web-1-10-00000001', 'groups', $raw);

    expect($this->registry->find('web-1-10-00000001')['groups'] ?? null)->toBe([]);
})->with([
    'not json' => ['{nope'],
    'a list' => ['[1,2]'],
    'a scalar' => ['"x"'],
    'bad group name' => ['{"Bad Name":{"queues":["a"],"workers":1,"target":1,"min":1,"max":1,"slots":1}}'],
    'queues not a list' => ['{"a":{"queues":"a","workers":1,"target":1,"min":1,"max":1,"slots":1}}'],
    'bad queue name' => ['{"a":{"queues":["a b"],"workers":1,"target":1,"min":1,"max":1,"slots":1}}'],
    'missing number' => ['{"a":{"queues":["a"],"workers":1,"target":1,"min":1,"max":1}}'],
    'negative number' => ['{"a":{"queues":["a"],"workers":-1,"target":1,"min":1,"max":1,"slots":1}}'],
    'string number' => ['{"a":{"queues":["a"],"workers":"1","target":1,"min":1,"max":1,"slots":1}}'],
    'one bad group among good' => ['{"a":{"queues":["a"],"workers":1,"target":1,"min":1,"max":1,"slots":1},"b":[]}'],
    'too deep' => ['{"a":{"queues":[["a"]],"workers":1,"target":1,"min":1,"max":1,"slots":1}}'],
]);

it('reads a missing groups field as an empty list', function () {
    expect(MasterRegistry::decodeGroups(null))->toBe([])
        ->and(MasterRegistry::decodeGroups(''))->toBe([]);
});

it('removes a record and its index entry', function () {
    ($this->publish)('web-1-10-00000001');
    $this->registry->remove('web-1-10-00000001');

    expect($this->redis->execute('EXISTS', $this->prefix.'master:web-1-10-00000001'))->toBe(0)
        ->and($this->redis->execute('ZSCORE', $this->prefix.'masters', 'web-1-10-00000001'))->toBeNull();
});

it('refuses to publish an invalid identity', function (string $id, array $overrides) {
    $this->registry->publish($id, ($this->record)($overrides));
})->with([
    'bad master id' => ['web-1', []],
    'bad node' => ['web-1-10-00000001', ['node' => 'web/1']],
    'bad pid' => ['web-1-10-00000001', ['pid' => 0]],
])->throws(InvalidArgumentException::class);

it('validates master ids', function (string $id, bool $valid) {
    expect(MasterRegistry::isValidMasterId($id))->toBe($valid);
})->with([
    ['web-1-4321-0a1b2c3d', true],
    ['host.example.com-1-ffffffff', true],
    ['web-1-4321-0A1B2C3D', false],
    ['web-1-0-0a1b2c3d', false],
    ['-1-0a1b2c3d', false],
    ["web\n-1-0a1b2c3d", false],
    ["web-1-4321-0a1b2c3d\n", false],
    ['', false],
]);
