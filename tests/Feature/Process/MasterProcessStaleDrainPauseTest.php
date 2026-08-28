<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;

/*
 * `MasterProcess::clearStaleDrainPause()` is the boot half-step that stops a
 * dead reload from parking the fleet: a `drain:<pid>` key belongs to one
 * master, so when that PID is gone the pause has no owner and the next master
 * deletes it instead of honouring the rest of its TTL (grace + 60, which is
 * over two hours where `drain_grace_seconds` is 7200).
 *
 * Driven directly against real Redis rather than through start(), which would
 * fork a fleet. The liveness probe is injected so the decision does not depend
 * on which PIDs happen to exist on the machine running the suite.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-stale-drain-'.bin2hex(random_bytes(4)).':';
    $this->pausedKey = $this->prefix.'paused';
    $this->logged = [];

    $this->master = fn (): MasterProcess => new MasterProcess([
        'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
        'consumer_group' => 'torque-test',
        'streams' => ['default' => []],
        'drain_grace_seconds' => 7200,
    ], function (string $message) {
        $this->logged[] = $message;
    });

    $this->seedPause = function (string $value, ?int $ttl = null): void {
        $ttl === null
            ? $this->redis->execute('SET', $this->pausedKey, $value)
            : $this->redis->execute('SET', $this->pausedKey, $value, 'EX', (string) $ttl);
    };

    $this->dead = fn (int $pid): bool => false;
    $this->alive = fn (int $pid): bool => true;

    // For the boot path, which uses the real process probe: a PID nothing on
    // this machine is using, so the test does not depend on the local PID
    // space.
    $this->missingPid = (function (): int {
        $pid = 424242;

        while (posix_kill($pid, 0)) {
            $pid++;
        }

        return $pid;
    })();
});

afterEach(function () {
    $this->redis->execute('DEL', $this->pausedKey);
});

it('clears a drain pause whose master is no longer running', function () {
    ($this->seedPause)('drain:424242', 7260);

    $cleared = ($this->master)()->clearStaleDrainPause($this->dead);

    expect($cleared)->toBeTrue()
        ->and((int) $this->redis->execute('EXISTS', $this->pausedKey))->toBe(0)
        ->and(implode("\n", $this->logged))
        ->toContain('Cleared stale drain pause left by master PID 424242 (not running)');
});

it('leaves a drain pause alone while its master is still running', function () {
    ($this->seedPause)('drain:424242', 7260);

    $cleared = ($this->master)()->clearStaleDrainPause($this->alive);

    expect($cleared)->toBeFalse()
        ->and((string) $this->redis->execute('GET', $this->pausedKey))->toBe('drain:424242')
        ->and($this->logged)->toBe([]);
});

it('never clears a deliberate torque:pause, even with no live master anywhere', function () {
    // torque:pause writes a TTL-less timestamp; only an operator resumes it.
    ($this->seedPause)((string) time());

    $cleared = ($this->master)()->clearStaleDrainPause($this->dead);

    expect($cleared)->toBeFalse()
        ->and((int) $this->redis->execute('EXISTS', $this->pausedKey))->toBe(1)
        ->and((int) $this->redis->execute('TTL', $this->pausedKey))->toBe(-1);
});

it('does nothing when the queue is not paused', function () {
    $cleared = ($this->master)()->clearStaleDrainPause($this->dead);

    expect($cleared)->toBeFalse()
        ->and($this->logged)->toBe([]);
});

it('keeps a pause that changed value between the read and the delete', function () {
    ($this->seedPause)('drain:424242', 7260);

    // The probe runs after the value was read: a fresh drain (or a manual
    // pause) landing here must survive the compare-and-delete.
    $cleared = ($this->master)()->clearStaleDrainPause(function (int $pid): bool {
        $this->redis->execute('SET', $this->pausedKey, 'drain:'.getmypid(), 'EX', '7260');

        return false;
    });

    expect($cleared)->toBeFalse()
        ->and((string) $this->redis->execute('GET', $this->pausedKey))->toBe('drain:'.getmypid());
});

it('clears the pause on the boot path so the warning is not printed', function () {
    ($this->seedPause)('drain:'.$this->missingPid, 7260);

    $master = ($this->master)();
    (new ReflectionMethod($master, 'warnIfPaused'))->invoke($master);

    expect((int) $this->redis->execute('EXISTS', $this->pausedKey))->toBe(0)
        ->and(implode("\n", $this->logged))
        ->toContain("Cleared stale drain pause left by master PID {$this->missingPid}")
        ->not->toContain('Queue is PAUSED');
});

it('still warns about a pause it must not clear', function () {
    ($this->seedPause)((string) time());

    $master = ($this->master)();
    (new ReflectionMethod($master, 'warnIfPaused'))->invoke($master);

    expect(implode("\n", $this->logged))
        ->toContain('Queue is PAUSED')
        ->toContain('deliberate `torque:pause`')
        ->and((int) $this->redis->execute('EXISTS', $this->pausedKey))->toBe(1);
});

it('survives an unreachable Redis without clearing anything', function () {
    $master = new MasterProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6399', 'prefix' => 'torque-test-stale-drain-down:'],
        'streams' => ['default' => []],
    ], function (string $message) {
        $this->logged[] = $message;
    });

    expect($master->clearStaleDrainPause($this->dead))->toBeFalse()
        ->and($this->logged)->toBe([]);
});
