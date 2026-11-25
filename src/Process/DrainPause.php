<?php

declare(strict_types=1);

namespace Webpatser\Torque\Process;

use Webpatser\Torque\Support\ProcessInspector;

/**
 * The legacy `{prefix}paused` value a draining master of older code writes
 * (`drain:<pid>`), and the rule that decides when such a key outlived the
 * master that wrote it.
 *
 * Masters since 2.5.0 drain through their own `{prefix}drain:{masterId}` key
 * and never write this value, but during a rolling deploy older masters still
 * do, and their workers still honour it. A drain pause is scoped to one
 * master process: once that PID is gone the pause has no owner, but it keeps
 * its TTL (`drain_grace + 60`), and with a long `drain_grace_seconds` that TTL
 * parks a freshly started fleet for hours. A deliberate `torque:pause` writes
 * a TTL-less generic value instead and is never touched here: only an
 * operator resumes that.
 *
 * The PID can only be checked on this machine, so the value is kept while
 * another host is live: the PID may be a draining master over there.
 *
 * Pure and static so the decision is unit-testable without a live master.
 */
final class DrainPause
{
    /** Value prefix written by masters before 2.5.0. */
    private const string VALUE_PREFIX = 'drain:';

    /**
     * The master PID a drain pause belongs to, or null when the value is not
     * a well-formed drain pause (a manual pause, a timestamp value, or
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
     *  - not a `drain:<pid>` value (manual pause, malformed) -> keep;
     *  - `$liveHosts` lists a host that is not in `$ownHosts` -> keep, the
     *    PID may belong to a master on that host;
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
     * @param  list<string>  $liveHosts  Hosts `{prefix}metrics:live` lists.
     * @param  list<string>  $ownHosts  The names this machine is listed under.
     */
    public static function isStale(
        ?string $value,
        int $selfPid,
        callable $isMasterAlive,
        array $liveHosts = [],
        array $ownHosts = [],
    ): bool {
        $pid = self::ownerPid($value);

        if ($pid === null || self::otherHostLive($liveHosts, $ownHosts)) {
            return false;
        }

        if ($pid === $selfPid) {
            return true;
        }

        return ! $isMasterAlive($pid);
    }

    /**
     * Whether any live host is not one of ours.
     *
     * @param  list<string>  $liveHosts
     * @param  list<string>  $ownHosts
     */
    public static function otherHostLive(array $liveHosts, array $ownHosts): bool
    {
        return array_any($liveHosts, static fn (string $host): bool => ! in_array($host, $ownHosts, true));
    }
}
