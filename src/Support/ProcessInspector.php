<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

/**
 * Portable process introspection: /proc on Linux, `ps` fallback elsewhere
 * (macOS has no /proc). Every reader returns null when the answer cannot
 * be determined, so callers decide their own conservative default.
 */
final class ProcessInspector
{
    /**
     * The command line of the given process, or null when unreadable.
     */
    public static function commandLine(int $pid): ?string
    {
        $cmdlinePath = "/proc/{$pid}/cmdline";

        if (is_readable($cmdlinePath)) {
            $cmdline = @file_get_contents($cmdlinePath);

            return $cmdline === false ? null : str_replace("\0", ' ', $cmdline);
        }

        $output = [];
        exec('ps -o command= -p '.escapeshellarg((string) $pid).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        return trim(implode(' ', $output));
    }

    /**
     * The parent PID of the given process, or null when unreadable.
     */
    public static function parentPid(int $pid): ?int
    {
        $statPath = "/proc/{$pid}/stat";

        if (is_readable($statPath)) {
            $stat = @file_get_contents($statPath);

            // Field 4 is ppid; the comm field (2) is parenthesised and may
            // contain spaces, so split after the closing parenthesis.
            if ($stat !== false && preg_match('/\)\s+\S+\s+(\d+)/', $stat, $m) === 1) {
                return (int) $m[1];
            }

            return null;
        }

        $output = [];
        exec('ps -o ppid= -p '.escapeshellarg((string) $pid).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $ppid = trim($output[0]);

        return ctype_digit($ppid) ? (int) $ppid : null;
    }

    /**
     * Whether the process at the given PID looks like a live Torque master.
     */
    public static function isTorqueMaster(int $pid): bool
    {
        if (! posix_kill($pid, 0)) {
            return false;
        }

        $cmdline = self::commandLine($pid);

        // Unreadable command line: inconclusive, treat as a master so a
        // liveness-only caller stays conservative (never kill/signal a
        // process we cannot identify; never boot over one either).
        return $cmdline === null || str_contains($cmdline, 'torque:start');
    }
}
