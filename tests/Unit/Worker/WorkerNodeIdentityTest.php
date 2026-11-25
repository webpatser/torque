<?php

declare(strict_types=1);

use Webpatser\Torque\Console\TorqueWorkerCommand;
use Webpatser\Torque\Support\NodeName;
use Webpatser\Torque\Worker\WorkerProcess;

/*
 * A worker names itself after its node and remembers the master that spawned
 * it. A fresh torque:worker reads the flat torque config, so the node block's
 * worker-level limits are applied by the worker itself.
 */

function nodeLimitsConfig(): array
{
    return [
        'streams' => ['default' => [], 'imports' => []],
        'queues' => ['default'],
        'workers' => 4,
        'slots_per_worker' => 50,
        'max_jobs_per_worker' => 10_000,
        'max_worker_lifetime' => 3600,
        'memory_limit' => 512,
        'nodes' => [
            'batch-*' => [
                'queues' => ['imports'],
                'workers' => 8,
                'slots_per_worker' => 20,
                'max_jobs_per_worker' => 500,
                'max_worker_lifetime' => 600,
                'memory_limit' => 1024,
            ],
            'web-1' => ['max_jobs_per_worker' => 50],
        ],
    ];
}

it('applies the node block worker limits and leaves the rest to the options', function () {
    $config = TorqueWorkerCommand::applyNodeSettings(nodeLimitsConfig(), 'batch-7');

    expect($config['max_jobs_per_worker'])->toBe(500)
        ->and($config['max_worker_lifetime'])->toBe(600)
        ->and($config['memory_limit'])->toBe(1024)
        ->and($config['node'])->toBe('batch-7')
        ->and($config['queues'])->toBe(['default'])
        ->and($config['workers'])->toBe(4)
        ->and($config['slots_per_worker'])->toBe(50);
});

it('keeps the flat limits the node block does not set', function () {
    $config = TorqueWorkerCommand::applyNodeSettings(nodeLimitsConfig(), 'web-1');

    expect($config['max_jobs_per_worker'])->toBe(50)
        ->and($config['max_worker_lifetime'])->toBe(3600)
        ->and($config['memory_limit'])->toBe(512);
});

it('keeps the flat config for a node without a block', function () {
    $flat = nodeLimitsConfig();
    $config = TorqueWorkerCommand::applyNodeSettings($flat, 'other-host');

    expect($config)->toBe([...$flat, 'node' => 'other-host']);
});

it('refuses an invalid node block', function () {
    $config = nodeLimitsConfig();
    $config['nodes']['bad'] = ['streams' => []];

    TorqueWorkerCommand::applyNodeSettings($config, 'bad');
})->throws(InvalidArgumentException::class);

it('falls back to the default group for a blank or invalid group name', function (?string $option, string $expected) {
    expect(TorqueWorkerCommand::groupName($option))->toBe($expected);
})->with([
    'missing' => [null, 'default'],
    'blank' => ['  ', 'default'],
    'invalid' => ['Not Valid!', 'default'],
    'valid' => ['imports', 'imports'],
]);

it('builds its consumer id from the node name', function () {
    $worker = new WorkerProcess([]);

    expect($worker->consumerId)->toMatch('/^'.preg_quote(NodeName::current(), '/').'-'.getmypid().'-[0-9a-f]{8}$/');
});

it('remembers a valid master id and its group', function () {
    $worker = new WorkerProcess(['master_id' => 'web-1-1234-abcdef01', 'group' => 'imports']);

    expect($worker->masterId)->toBe('web-1-1234-abcdef01')
        ->and($worker->group)->toBe('imports');
});

it('drops an invalid master id and defaults the group', function () {
    $worker = new WorkerProcess(['master_id' => 'not a master id']);

    expect($worker->masterId)->toBeNull()
        ->and($worker->group)->toBe('default');
});
