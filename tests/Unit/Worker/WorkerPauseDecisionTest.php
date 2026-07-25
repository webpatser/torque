<?php

declare(strict_types=1);

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
