<?php

declare(strict_types=1);

use Webpatser\Torque\Control\PauseScopes;

/*
 * Node and queue pauses live as fields of one `{prefix}pauses` HASH; a
 * draining master writes its own `{prefix}drain:{masterId}`. The Redis half
 * runs against the real test Redis under a random prefix; the decision helper
 * is pure and runs as a table.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-pause-scopes-'.bin2hex(random_bytes(4)).':';
    $this->scopes = new PauseScopes($this->redis, $this->prefix);
});

afterEach(function () {
    $this->redis->execute('DEL', PauseScopes::key($this->prefix), PauseScopes::drainKey($this->prefix, 'web-1-123-abcdef01'));
});

it('names the pauses hash and the per-master drain key under the prefix', function () {
    expect(PauseScopes::key('torque:'))->toBe('torque:pauses')
        ->and(PauseScopes::drainKey('torque:', 'web-1-123-abcdef01'))->toBe('torque:drain:web-1-123-abcdef01');
});

it('writes node and queue pauses as epoch fields without a TTL', function () {
    $this->scopes->pauseNode('web-1', 1_700_000_000);
    $this->scopes->pauseQueue('emails', 1_700_000_005);

    $key = PauseScopes::key($this->prefix);

    expect((string) $this->redis->execute('HGET', $key, 'node:web-1'))->toBe('1700000000')
        ->and((string) $this->redis->execute('HGET', $key, 'queue:emails'))->toBe('1700000005')
        ->and((int) $this->redis->execute('TTL', $key))->toBe(-1);
});

it('lists every scope by name', function () {
    $this->scopes->pauseNode('web-2', 20);
    $this->scopes->pauseNode('web-1', 10);
    $this->scopes->pauseQueue('imports', 30);
    $this->redis->execute('HSET', PauseScopes::key($this->prefix), 'junk', '1', 'node:', '1');

    expect($this->scopes->all())->toBe([
        'nodes' => ['web-1' => 10, 'web-2' => 20],
        'queues' => ['imports' => 30],
    ]);
});

it('lists nothing when no scope is paused', function () {
    expect($this->scopes->all())->toBe(['nodes' => [], 'queues' => []]);
});

it('resumes a single node or queue and reports whether it was paused', function () {
    $this->scopes->pauseNode('web-1');
    $this->scopes->pauseQueue('emails');
    $this->scopes->pauseQueue('imports');

    expect($this->scopes->resumeNode('web-1'))->toBeTrue()
        ->and($this->scopes->resumeNode('web-1'))->toBeFalse()
        ->and($this->scopes->resumeQueue('emails'))->toBeTrue()
        ->and($this->scopes->resumeQueue('missing'))->toBeFalse()
        ->and(array_keys($this->scopes->all()['queues']))->toBe(['imports'])
        ->and($this->scopes->all()['nodes'])->toBe([]);
});

it('clears every scope and leaves the global switch alone', function () {
    $this->redis->execute('SET', $this->prefix.'paused', '123');
    $this->scopes->pauseNode('web-1');
    $this->scopes->pauseQueue('emails');

    try {
        expect($this->scopes->clearAll())->toBe(2)
            ->and((int) $this->redis->execute('EXISTS', PauseScopes::key($this->prefix)))->toBe(0)
            ->and((string) $this->redis->execute('GET', $this->prefix.'paused'))->toBe('123')
            ->and($this->scopes->clearAll())->toBe(0);
    } finally {
        $this->redis->execute('DEL', $this->prefix.'paused');
    }
});

it('gives a worker its node and queue pauses in one read', function () {
    $this->scopes->pauseNode('web-2');
    $this->scopes->pauseQueue('emails');
    $this->scopes->pauseQueue('reports');

    expect($this->scopes->workerView('web-1', ['default', 'emails', 'emails']))->toBe([
        'nodePaused' => false,
        'pausedQueues' => ['emails'],
    ])->and($this->scopes->workerView('web-2', ['default']))->toBe([
        'nodePaused' => true,
        'pausedQueues' => [],
    ]);
});

it('gives an empty view when nothing is paused', function () {
    expect($this->scopes->workerView('web-1', ['default', 'emails']))->toBe([
        'nodePaused' => false,
        'pausedQueues' => [],
    ])->and($this->scopes->workerView('web-1', []))->toBe([
        'nodePaused' => false,
        'pausedQueues' => [],
    ]);
});

it('reads a master drain key', function () {
    $masterId = 'web-1-123-abcdef01';

    expect($this->scopes->drainValue($masterId))->toBeNull();

    $this->redis->execute('SET', PauseScopes::drainKey($this->prefix, $masterId), '1700000000', 'EX', '130');

    expect($this->scopes->drainValue($masterId))->toBe('1700000000');
});

it('decides the worker pause from every source', function (
    ?string $globalValue,
    int $globalTtl,
    bool $nodePaused,
    ?string $drainValue,
    bool $expected,
) {
    $decision = PauseScopes::decide($globalValue, $globalTtl, $nodePaused, ['emails'], $drainValue, 4242);

    expect($decision['pauseFully'])->toBe($expected)
        ->and($decision['pausedQueues'])->toBe(['emails']);
})->with([
    'nothing paused' => [null, -2, false, null, false],
    'global pause' => ['1700000000', -1, false, null, true],
    'node pause' => [null, -2, true, null, true],
    'own drain key' => [null, -2, false, '1700000000', true],
    'legacy drain of our parent' => ['drain:4242', 120, false, null, true],
    'legacy drain of another master' => ['drain:999', 120, false, null, false],
    'legacy drain of another master with our own drain' => ['drain:999', 120, false, '1700000000', true],
    'TTL-less drain value is a deliberate pause' => ['drain:999', -1, false, null, true],
    'TTL-d plain value is ignored' => ['1700000000', 120, false, null, false],
    'value read but key gone' => ['drain:4242', -2, false, null, false],
    'global pause with a drain stays paused' => ['1700000000', -1, false, '1700000000', true],
    'global pause with a node pause' => ['1700000000', -1, true, null, true],
]);

it('passes queue pauses through without pausing fully', function () {
    expect(PauseScopes::decide(null, -2, false, ['emails', 'imports', 'emails'], null, 4242))->toBe([
        'pauseFully' => false,
        'pausedQueues' => ['emails', 'imports'],
    ])->and(PauseScopes::decide('1700000000', -1, false, [], null, 4242))->toBe([
        'pauseFully' => true,
        'pausedQueues' => [],
    ]);
});
