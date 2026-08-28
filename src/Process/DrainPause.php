<?php

declare(strict_types=1);

namespace Webpatser\Torque\Process;

use Webpatser\Torque\Support\ProcessInspector;

/**
 * The `{prefix}paused` value a draining master writes (`drain:<pid>`), and the
 * rule that decides when such a key outlived the master that wrote it.
 *
 * A drain pause is scoped to one master process: it exists so that master's
 * own fleet stops picking up work while its in-flight jobs finish. Once that
 * PID is gone the pause has no owner, but it keeps its TTL (`drain_grace +
 * 60`), and with a long `drain_grace_seconds` that TTL parks a freshly started
 * fleet for hours. A deliberate `torque:pause` writes a TTL-less generic value
 * instead and is never touched here: only an operator resumes that.
 *
 * Pure and static so the decision is unit-testable without a live master.
 */
final class DrainPause
{
    /** Value prefix written by {@see MasterProcess::beginDrain()}. */
    private const string VALUE_PREFIX = 'drain:';

    /**
     * The master PID a drain pause belongs to, or null when the value is not
     * a well-formed drain pause (a manual pause, a legacy timestamp value, or
     * anything unparseable).
     */
    public static function ownerPid(?string $value): ?int
    {
        if ($value === null || ! str_starts_with($value, self::VALUE_PREFIX)) {
            return null;
        }

        $pid = substr($value, strlen(self::VALUE_PREFIX));

        return ctype_digit($pid) && (int) $pid > 0 ? (int) $pid : null;
    }

    /**
     * Whether the pause key is a drain left behind by a master that is gone.
     *
     * The rule, in order:
     *  - not a `drain:<pid>` value (manual pause, malformed, legacy) -> keep;
     *  - the PID is our own -> stale, because a master only writes its drain
     *    key while draining, never before it has started supervising, so
     *    seeing our own PID means the number was recycled;
     *  - `$isMasterAlive($pid)` is false (dead, or alive but not a Torque
     *    master) -> stale;
     *  - otherwise -> keep, the draining master is still running.
     *
     * @param  callable(int): bool  $isMasterAlive  Liveness probe, typically
     *                                              {@see ProcessInspector::isTorqueMaster()},
     *                                              which is deliberately
     *                                              conservative and reports
     *                                              true when the command line
     *                                              cannot be read.
     */
    public static function isStale(?string $value, int $selfPid, callable $isMasterAlive): bool
    {
        $pid = self::ownerPid($value);

        if ($pid === null) {
            return false;
        }

        if ($pid === $selfPid) {
            return true;
        }

        return ! $isMasterAlive($pid);
    }
}
