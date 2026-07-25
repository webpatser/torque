<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

it('keeps uncapped streams always eligible', function () {
    $queues = ['default', 'backfill'];
    $streams = ['default' => [], 'backfill' => []];

    expect(WorkerProcess::eligibleQueues($queues, $streams, ['default' => 99, 'backfill' => 99]))
        ->toBe(['default', 'backfill']);
});

it('excludes a stream at its max_concurrency cap', function () {
    $queues = ['default', 'backfill'];
    $streams = ['backfill' => ['max_concurrency' => 2]];

    expect(WorkerProcess::eligibleQueues($queues, $streams, ['backfill' => 2]))->toBe(['default'])
        ->and(WorkerProcess::eligibleQueues($queues, $streams, ['backfill' => 1]))->toBe(['default', 'backfill'])
        ->and(WorkerProcess::eligibleQueues($queues, $streams, []))->toBe(['default', 'backfill']);
});

it('returns an empty list when every stream is capped out', function () {
    $queues = ['backfill'];
    $streams = ['backfill' => ['max_concurrency' => 1]];

    expect(WorkerProcess::eligibleQueues($queues, $streams, ['backfill' => 1]))->toBe([]);
});
