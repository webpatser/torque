<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

/**
 * The one reader of the drain settings.
 *
 * 1.x had a single `drain_grace_seconds` that did four jobs. They are separate now:
 *
 *  - grace: how long a worker told to stop lets the jobs it holds finish.
 *  - reload pause: how long a draining master waits for an idle fleet with pickup
 *    paused, before it SIGTERMs that fleet.
 *  - release delay: the minimum delay of a job released after its timeout.
 *  - settle: the budget of interrupted() and the timeout listeners.
 *
 * Pure, and every method takes the `torque` config array, so a worker or master
 * passes the config it was started with and a command passes `config('torque')`.
 */
final class DrainSettings
{
    /** The derived grace never exceeds this. An explicit `drain_grace_seconds` may. */
    public const int MAX_DERIVED_GRACE_SECONDS = 300;

    public const int DEFAULT_RELOAD_PAUSE_SECONDS = 10;

    public const int DEFAULT_RELEASE_DELAY_SECONDS = 10;

    public const int DEFAULT_SETTLE_SECONDS = 5;

    /**
     * Added to a derived drain deadline: one monitor tick, one metrics publish
     * interval, and signal latency between master and workers.
     */
    public const int DEFAULT_SLACK_SECONDS = 15;

    /** What the drain pause outlives a full drain by, so its TTL is a backstop only. */
    public const int PAUSE_TTL_BUFFER_SECONDS = 60;

    /** A stream's `retry_after` when it sets none, as the worker reads it. */
    private const int DEFAULT_RETRY_AFTER_SECONDS = 60;

    /**
     * Seconds a worker told to stop lets its in-flight jobs finish. An explicit
     * `drain_grace_seconds` wins, 0 included; null or empty derives it.
     *
     * @param  array<string, mixed>  $config
     */
    public static function graceSeconds(array $config): int
    {
        $grace = $config['drain_grace_seconds'] ?? null;

        return is_numeric($grace) ? max(0, (int) $grace) : self::derivedGraceSeconds($config);
    }

    /**
     * The grace the streams ask for: the longest `timeout`, or `retry_after` for a
     * stream without one, capped at {@see MAX_DERIVED_GRACE_SECONDS}. 60 at package
     * defaults, and with no streams at all.
     *
     * @param  array<string, mixed>  $config
     */
    public static function derivedGraceSeconds(array $config): int
    {
        $streams = is_array($config['streams'] ?? null) ? $config['streams'] : [];

        $seconds = array_map(
            static fn (mixed $stream): int => self::streamSeconds(is_array($stream) ? $stream : []),
            $streams,
        );

        return min(self::MAX_DERIVED_GRACE_SECONDS, $seconds === [] ? self::DEFAULT_RETRY_AFTER_SECONDS : max($seconds));
    }

    /**
     * Seconds a draining master waits for its fleet to go idle before it SIGTERMs it.
     *
     * @param  array<string, mixed>  $config
     */
    public static function reloadPauseSeconds(array $config): int
    {
        return max(0, Narrow::int($config['reload_pause_seconds'] ?? null, self::DEFAULT_RELOAD_PAUSE_SECONDS));
    }

    /**
     * The minimum delay of a job released after its timeout.
     *
     * @param  array<string, mixed>  $config
     */
    public static function releaseDelaySeconds(array $config): int
    {
        return max(0, Narrow::int(Narrow::map($config['timeouts'] ?? null)['release_delay_seconds'] ?? null, self::DEFAULT_RELEASE_DELAY_SECONDS));
    }

    /**
     * The budget of interrupted() and the timeout listeners: `timeouts.settle_seconds`,
     * at most half the grace. A float, as half a grace of 1 is half a second.
     *
     * @param  array<string, mixed>  $config
     */
    public static function settleSeconds(array $config): float
    {
        $settle = max(0.0, Narrow::float(Narrow::map($config['timeouts'] ?? null)['settle_seconds'] ?? null, (float) self::DEFAULT_SETTLE_SECONDS));

        return min($settle, self::graceSeconds($config) / 2);
    }

    /**
     * The expiry of the `paused` key a drain writes: pause + grace + 60.
     *
     * @param  array<string, mixed>  $config
     */
    public static function drainPauseTtlSeconds(array $config): int
    {
        return self::reloadPauseSeconds($config) + self::graceSeconds($config) + self::PAUSE_TTL_BUFFER_SECONDS;
    }

    /**
     * The slack for {@see drainWorstCaseSeconds()} from the internal `intervals`
     * config. At least 1, so the window never comes to nothing at a grace of 0.
     *
     * @param  array<string, mixed>  $config
     */
    public static function slackSeconds(array $config): int
    {
        return max(1, Narrow::int(Narrow::map($config['intervals'] ?? null)['drain_slack_seconds'] ?? null, self::DEFAULT_SLACK_SECONDS));
    }

    /**
     * Worst case for a full drain: pause + grace + slack.
     *
     * A drain runs on two clocks that know nothing of each other: the master waits
     * up to the reload pause for an idle fleet and then SIGTERMs it, after which
     * every worker gets up to the grace for the job it still holds. A shorter
     * deadline gives up on a fleet that is draining normally, so `torque:reload`,
     * `torque:stop` and the generated `stopwaitsecs` all derive theirs from this.
     *
     * @param  array<string, mixed>  $config
     */
    public static function drainWorstCaseSeconds(array $config): int
    {
        return self::reloadPauseSeconds($config) + self::graceSeconds($config) + self::slackSeconds($config);
    }

    /**
     * A stream's `timeout`, else its `retry_after`. A 0 means "not set" for both.
     *
     * @param  array<array-key, mixed>  $stream
     */
    private static function streamSeconds(array $stream): int
    {
        return max(0, Narrow::int($stream['timeout'] ?? null))
            ?: max(0, Narrow::int($stream['retry_after'] ?? null))
            ?: self::DEFAULT_RETRY_AFTER_SECONDS;
    }
}
