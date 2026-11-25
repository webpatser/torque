<?php

declare(strict_types=1);

use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Worker\WorkerProcess;

/*
 * shouldPauseFor() discriminates pauses by TTL (Redis semantics: -2 key
 * missing, -1 no expiry, >0 seconds left):
 * - TTL-less key: deliberate torque:pause, pauses every fleet.
 * - TTL'd key: a drain scoped to one master (`drain:<pid>`); only that
 *   master's own workers pause, so a takeover's old fleet drains without
 *   pausing the replacement.
 */

it('does not pause when the key is missing', function () {
    expect(WorkerProcess::shouldPauseFor(null, -2, 1234))->toBeFalse();
});

it('does not pause when the key expired between GET and TTL', function () {
    expect(WorkerProcess::shouldPauseFor('drain:1234', -2, 1234))->toBeFalse();
});

it('pauses every fleet for a deliberate ttl-less pause', function () {
    expect(WorkerProcess::shouldPauseFor((string) time(), -1, 1234))->toBeTrue()
        ->and(WorkerProcess::shouldPauseFor('anything', -1, 9999))->toBeTrue();
});

it('pauses for its own master drain', function () {
    expect(WorkerProcess::shouldPauseFor('drain:1234', 42, 1234))->toBeTrue();
});

it('ignores another master drain', function () {
    expect(WorkerProcess::shouldPauseFor('drain:5678', 42, 1234))->toBeFalse();
});

it('ignores a legacy timestamp drain value written by an old master', function () {
    // Old-code masters wrote a timestamp with a TTL; new workers cannot tell
    // whose drain it is, so they keep working and rely on their own master's
    // SIGTERM-after-grace if the drain was actually theirs.
    expect(WorkerProcess::shouldPauseFor((string) time(), 42, 1234))->toBeFalse();
});

/*
 * readPauseDecision() reads every source against the real test Redis: the
 * global key, the node and queue scopes and the own master's drain key.
 */
describe('readPauseDecision', function () {
    beforeEach(function () {
        $this->redis = torqueRedis();
        $this->prefix = 'torque-test-worker-pause-'.bin2hex(random_bytes(4)).':';
        $this->scopes = new PauseScopes($this->redis, $this->prefix);
        $this->ownMaster = 'web-1-1234-abcdef01';
        $this->foreignMaster = 'web-2-1234-12345678';
        $this->decide = fn (?string $masterId = 'web-1-1234-abcdef01', array $queues = ['default', 'emails']): array => WorkerProcess::readPauseDecision(
            $this->redis, $this->prefix, 'web-1', $queues, $masterId, 1234,
        );
    });

    afterEach(function () {
        $this->redis->execute(
            'DEL',
            $this->prefix.'paused',
            PauseScopes::key($this->prefix),
            PauseScopes::drainKey($this->prefix, $this->ownMaster),
            PauseScopes::drainKey($this->prefix, $this->foreignMaster),
        );
    });

    it('runs when nothing is paused', function () {
        expect(($this->decide)())->toBe(['pauseFully' => false, 'pausedQueues' => []]);
    });

    it('pauses fully for a pause of its own node', function () {
        $this->scopes->pauseNode('web-1');

        expect(($this->decide)()['pauseFully'])->toBeTrue();
    });

    it('ignores a pause of another node', function () {
        $this->scopes->pauseNode('web-2');

        expect(($this->decide)()['pauseFully'])->toBeFalse();
    });

    it('returns paused queues without pausing fully', function () {
        $this->scopes->pauseQueue('emails');
        $this->scopes->pauseQueue('imports');

        expect(($this->decide)())->toBe(['pauseFully' => false, 'pausedQueues' => ['emails']]);
    });

    it('pauses fully for its own master drain key', function () {
        $this->redis->execute('SET', PauseScopes::drainKey($this->prefix, $this->ownMaster), 'drain', 'EX', '60');

        expect(($this->decide)()['pauseFully'])->toBeTrue();
    });

    it('ignores a foreign master drain key', function () {
        $this->redis->execute('SET', PauseScopes::drainKey($this->prefix, $this->foreignMaster), 'drain', 'EX', '60');

        expect(($this->decide)()['pauseFully'])->toBeFalse();
    });

    it('does not read a drain key without a master id', function () {
        $this->redis->execute('SET', PauseScopes::drainKey($this->prefix, $this->ownMaster), 'drain', 'EX', '60');

        expect(($this->decide)(null)['pauseFully'])->toBeFalse();
    });

    it('honours a legacy drain of its parent pid without a master id', function () {
        $this->redis->execute('SET', $this->prefix.'paused', 'drain:1234', 'EX', '60');

        expect(($this->decide)(null)['pauseFully'])->toBeTrue();
    });

    it('ignores a legacy drain of a colliding pid when it has a master id', function () {
        // An old master on another host with the same PID drains through the
        // legacy value; a worker of a new-code master must not follow it.
        $this->redis->execute('SET', $this->prefix.'paused', 'drain:1234', 'EX', '60');

        expect(($this->decide)()['pauseFully'])->toBeFalse();
    });

    it('keeps a global pause with and without a master id', function () {
        $this->redis->execute('SET', $this->prefix.'paused', 'drain:1234');

        expect(($this->decide)()['pauseFully'])->toBeTrue()
            ->and(($this->decide)(null)['pauseFully'])->toBeTrue();
    });

    it('ignores a legacy drain of another parent pid', function () {
        $this->redis->execute('SET', $this->prefix.'paused', 'drain:5678', 'EX', '60');

        expect(($this->decide)()['pauseFully'])->toBeFalse();
    });

    it('keeps a global pause when a foreign drain is active', function () {
        $this->redis->execute('SET', $this->prefix.'paused', (string) time());
        $this->redis->execute('SET', PauseScopes::drainKey($this->prefix, $this->foreignMaster), 'drain', 'EX', '60');

        expect(($this->decide)()['pauseFully'])->toBeTrue()
            ->and(($this->decide)(null)['pauseFully'])->toBeTrue();
    });
});
