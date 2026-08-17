<?php

declare(strict_types=1);

use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Webpatser\Torque\Worker\WorkerProcess;

/*
 * shouldFullyPause() combines Torque's own pause switch with the framework's
 * paused-queue set (queue:pause / queue:pause --all). getPausedQueues()
 * returns the full queue list when the global illuminate:queues:paused flag
 * is set, so "all queues paused" covers the global switch too.
 */

it('pauses when the torque switch is on regardless of framework state', function () {
    expect(WorkerProcess::shouldFullyPause(true, [], ['default']))->toBeTrue()
        ->and(WorkerProcess::shouldFullyPause(true, ['default'], ['default']))->toBeTrue();
});

it('pauses when the framework paused every queue this worker serves', function () {
    expect(WorkerProcess::shouldFullyPause(false, ['default', 'backfill'], ['default', 'backfill']))->toBeTrue();
});

it('does not pause on a strict subset of paused queues', function () {
    expect(WorkerProcess::shouldFullyPause(false, ['backfill'], ['default', 'backfill']))->toBeFalse();
});

it('does not pause when nothing is paused', function () {
    expect(WorkerProcess::shouldFullyPause(false, [], ['default']))->toBeFalse();
});

it('never fully pauses a worker with no queues via the framework set', function () {
    expect(WorkerProcess::shouldFullyPause(false, [], []))->toBeFalse();
});

describe('fetchFrameworkPausedQueues', function () {
    beforeEach(function () {
        $this->pausable = Worker::$pausable;
    });

    afterEach(function () {
        Worker::$pausable = $this->pausable;
    });

    it('short-circuits to an empty set when pausing is disabled', function () {
        Worker::$pausable = false;

        $manager = new class extends QueueManager
        {
            public function __construct() {}

            public function getPausedQueues($connection, $queues)
            {
                throw new RuntimeException('must not be called');
            }
        };

        expect(WorkerProcess::fetchFrameworkPausedQueues($manager, 'torque', ['default']))->toBe([]);
    });

    it('re-indexes the paused subset from the manager', function () {
        $manager = new class extends QueueManager
        {
            public function __construct() {}

            public function getPausedQueues($connection, $queues)
            {
                return [1 => 'backfill'];
            }
        };

        expect(WorkerProcess::fetchFrameworkPausedQueues($manager, 'torque', ['default', 'backfill']))
            ->toBe(['backfill']);
    });

    it('returns null when the cache read throws so callers keep the last known set', function () {
        $manager = new class extends QueueManager
        {
            public function __construct() {}

            public function getPausedQueues($connection, $queues)
            {
                throw new RuntimeException('cache store down');
            }
        };

        expect(WorkerProcess::fetchFrameworkPausedQueues($manager, 'torque', ['default']))->toBeNull();
    });
});
