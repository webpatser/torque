<?php

declare(strict_types=1);

use Webpatser\Torque\Support\NodeConfig;

/*
 * Per-node settings: exact name before pattern, first pattern in declaration
 * order, an allowlist merged over the flat config, and worker groups.
 */

function nodeConfig(array $nodes, array $flat = []): array
{
    return array_replace([
        'workers' => 4,
        'slots_per_worker' => 50,
        'max_jobs_per_worker' => 10000,
        'max_worker_lifetime' => 3600,
        'memory_limit' => 512,
        'autoscale' => [
            'enabled' => false,
            'min_workers' => 2,
            'max_workers' => 8,
            'cooldown' => 30,
        ],
        'streams' => [
            'default' => ['priority' => 0],
            'emails' => ['priority' => 1],
            'imports' => ['priority' => 2],
        ],
        'nodes' => $nodes,
    ], $flat);
}

describe('match', function () {
    it('prefers the exact name over an earlier pattern', function () {
        $nodes = ['web-*' => [], 'web-1' => []];

        expect(NodeConfig::match('web-1', $nodes))->toBe('web-1');
    });

    it('takes the first matching pattern in declaration order', function () {
        $nodes = ['web-*' => [], '*' => [], 'web-1*' => []];

        expect(NodeConfig::match('web-12', $nodes))->toBe('web-*')
            ->and(NodeConfig::match('batch-1', $nodes))->toBe('*');
    });

    it('returns null without a match', function () {
        expect(NodeConfig::match('batch-1', ['web-*' => []]))->toBeNull()
            ->and(NodeConfig::match('batch-1', []))->toBeNull();
    });
});

describe('resolve', function () {
    it('returns the flat config with the node set when no block matches', function () {
        $config = nodeConfig(['web-*' => ['workers' => 2]]);

        expect(NodeConfig::resolve($config, 'batch-1'))->toBe([...$config, 'node' => 'batch-1']);
    });

    it('resolves a config without a nodes key', function () {
        expect(NodeConfig::resolve(['workers' => 3], 'web-1'))->toBe(['workers' => 3, 'node' => 'web-1']);
    });

    it('merges every allowed key over the flat config', function () {
        $resolved = NodeConfig::resolve(nodeConfig(['batch-1' => [
            'queues' => ['imports', 'default'],
            'workers' => 8,
            'slots_per_worker' => 20,
            'max_jobs_per_worker' => 500,
            'max_worker_lifetime' => 600,
            'memory_limit' => 1024,
        ]]), 'batch-1');

        expect($resolved['queues'])->toBe(['imports', 'default'])
            ->and($resolved['workers'])->toBe(8)
            ->and($resolved['slots_per_worker'])->toBe(20)
            ->and($resolved['max_jobs_per_worker'])->toBe(500)
            ->and($resolved['max_worker_lifetime'])->toBe(600)
            ->and($resolved['memory_limit'])->toBe(1024);
    });

    it('deep merges autoscale', function () {
        $resolved = NodeConfig::resolve(nodeConfig(['batch-1' => [
            'autoscale' => ['enabled' => true, 'max_workers' => 16],
        ]]), 'batch-1');

        expect($resolved['autoscale'])->toBe([
            'enabled' => true,
            'min_workers' => 2,
            'max_workers' => 16,
            'cooldown' => 30,
        ]);
    });

    it('refuses an unknown key', function () {
        expect(fn () => NodeConfig::resolve(nodeConfig(['web-1' => ['concurrency' => 10]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'torque.nodes.web-1.concurrency is not a per-node setting');
    });

    it('refuses a global-only key', function (string $key) {
        expect(fn () => NodeConfig::resolve(nodeConfig(['web-1' => [$key => []]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, "torque.nodes.web-1.{$key} is global");
    })->with(['streams', 'redis', 'waits', 'dead_letter', 'consumer_group']);

    it('refuses queues that are not stream keys', function () {
        expect(fn () => NodeConfig::resolve(nodeConfig(['web-1' => ['queues' => ['default', 'missing']]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'lists [missing], which is not a key of torque.streams');
    });

    it('refuses empty or non-list queues', function (mixed $queues) {
        expect(fn () => NodeConfig::resolve(nodeConfig(['web-1' => ['queues' => $queues]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'torque.nodes.web-1.queues');
    })->with([
        'empty' => [[]],
        'string' => ['default'],
    ]);

    it('refuses a block that is not an array', function () {
        expect(fn () => NodeConfig::resolve(nodeConfig(['web-1' => 'workers=2']), 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'torque.nodes.web-1 must be an array');
    });

    it('refuses nodes that is not an array', function () {
        expect(fn () => NodeConfig::resolve(['nodes' => 'web-1'], 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'torque.nodes must be an array');
    });

    it('ignores blocks of other nodes', function () {
        $resolved = NodeConfig::resolve(nodeConfig([
            'web-1' => ['workers' => 2],
            'batch-1' => ['redis' => []],
        ]), 'web-1');

        expect($resolved['workers'])->toBe(2);
    });
});

describe('groups', function () {
    it('returns one implicit default group without groups', function () {
        $resolved = NodeConfig::resolve(nodeConfig(['web-1' => ['queues' => ['emails'], 'workers' => 2]]), 'web-1');

        expect(NodeConfig::groups($resolved))->toBe([[
            'name' => 'default',
            'queues' => ['emails'],
            'workers' => 2,
            'slots_per_worker' => 50,
            'autoscale' => ['enabled' => false, 'min_workers' => 2, 'max_workers' => 8, 'cooldown' => 30],
        ]]);
    });

    it('takes the stream names for the implicit group without queues', function () {
        expect(NodeConfig::groups(nodeConfig([]))[0]['queues'])->toBe(['default', 'emails', 'imports'])
            ->and(NodeConfig::groups([])[0])->toBe([
                'name' => 'default',
                'queues' => ['default'],
                'workers' => 4,
                'slots_per_worker' => 50,
                'autoscale' => [],
            ]);
    });

    it('treats empty groups as the implicit default group', function () {
        $resolved = NodeConfig::resolve(nodeConfig(['*' => ['groups' => []]]), 'web-1');

        expect(NodeConfig::groups($resolved))->toHaveCount(1)
            ->and(NodeConfig::groups($resolved)[0]['name'])->toBe('default');
    });

    it('lists groups in declaration order with the node keys as defaults', function () {
        $resolved = NodeConfig::resolve(nodeConfig(['batch-*' => [
            'workers' => 3,
            'slots_per_worker' => 20,
            'groups' => [
                'mail' => ['queues' => ['emails', 'default'], 'slots_per_worker' => 100],
                'imports' => ['queues' => ['imports'], 'workers' => 2],
            ],
        ]]), 'batch-1');

        $groups = NodeConfig::groups($resolved);

        expect(array_column($groups, 'name'))->toBe(['mail', 'imports'])
            ->and($groups[0]['queues'])->toBe(['emails', 'default'])
            ->and($groups[0]['workers'])->toBe(3)
            ->and($groups[0]['slots_per_worker'])->toBe(100)
            ->and($groups[1]['workers'])->toBe(2)
            ->and($groups[1]['slots_per_worker'])->toBe(20);
    });

    it('deep merges group autoscale over the node autoscale', function () {
        $resolved = NodeConfig::resolve(nodeConfig(['batch-*' => [
            'autoscale' => ['enabled' => true],
            'groups' => [
                'imports' => ['queues' => ['imports'], 'autoscale' => ['min_workers' => 2, 'max_workers' => 12]],
                'mail' => ['queues' => ['emails'], 'autoscale' => ['enabled' => false]],
            ],
        ]]), 'batch-1');

        $groups = NodeConfig::groups($resolved);

        expect($groups[0]['autoscale'])->toBe(['enabled' => true, 'min_workers' => 2, 'max_workers' => 12, 'cooldown' => 30])
            ->and($groups[1]['autoscale'])->toBe(['enabled' => false, 'min_workers' => 2, 'max_workers' => 8, 'cooldown' => 30]);
    });

    it('refuses an invalid group name', function (string $name) {
        expect(fn () => NodeConfig::resolve(nodeConfig(['*' => ['groups' => [$name => ['queues' => ['default']]]]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, "invalid group name [{$name}]");
    })->with([
        'uppercase' => ['Mail'],
        'space' => ['bulk mail'],
        'dot' => ['bulk.mail'],
        '33 characters' => [str_repeat('a', 33)],
        'trailing newline' => ["mail\n"],
    ]);

    it('refuses groups that are not keyed by unique names', function () {
        $groups = [['queues' => ['default']], ['queues' => ['emails']]];

        expect(fn () => NodeConfig::resolve(nodeConfig(['*' => ['groups' => $groups]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'must be an array keyed by unique group names');
    });

    it('requires queues on every group', function () {
        expect(fn () => NodeConfig::resolve(nodeConfig(['*' => ['groups' => ['mail' => ['workers' => 2]]]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'torque.nodes.*.groups.mail.queues is required');
    });

    it('refuses empty group queues', function () {
        expect(fn () => NodeConfig::resolve(nodeConfig(['*' => ['groups' => ['mail' => ['queues' => []]]]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'torque.nodes.*.groups.mail.queues must be a non-empty list');
    });

    it('refuses group queues that are not stream keys', function () {
        expect(fn () => NodeConfig::resolve(nodeConfig(['*' => ['groups' => ['mail' => ['queues' => ['sms']]]]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, 'lists [sms], which is not a key of torque.streams');
    });

    it('refuses a key outside the group allowlist', function (string $key) {
        expect(fn () => NodeConfig::resolve(nodeConfig(['*' => ['groups' => ['mail' => ['queues' => ['emails'], $key => 1]]]]), 'web-1'))
            ->toThrow(InvalidArgumentException::class, "torque.nodes.*.groups.mail.{$key} is not a group setting");
    })->with(['max_jobs_per_worker', 'max_worker_lifetime', 'memory_limit', 'groups', 'concurrency']);

    it('validates groups passed to groups() directly', function () {
        expect(fn () => NodeConfig::groups(nodeConfig([], ['groups' => ['mail' => ['queues' => ['sms']]]])))
            ->toThrow(InvalidArgumentException::class, 'torque.groups.mail.queues');
    });
});
