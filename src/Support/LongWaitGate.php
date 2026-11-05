<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

/**
 * Decides when a long-wait alert fires, without touching Redis or the clock.
 *
 * A queue over its threshold raises one alert per episode. The next one needs
 * either a recovery (the oldest waiting job back under the threshold, or
 * nothing waiting) or the cooldown passing while it stays over. State lives in
 * the instance, so a master restart may alert once more for an ongoing episode.
 */
final class LongWaitGate
{
    /** Seconds before a queue that stays over its threshold alerts again. */
    public const int COOLDOWN_SECONDS = 900;

    /** @var array<string, float> Queue => time of its last alert, while over. */
    private array $alertedAt = [];

    /**
     * The queues with a positive threshold, as queue => seconds.
     *
     * 0, a negative value or a non-numeric one means off.
     *
     * @param  array<array-key, mixed>  $waits  The `waits` config.
     * @return array<string, int>
     */
    public static function thresholds(array $waits): array
    {
        $thresholds = [];

        foreach ($waits as $queue => $seconds) {
            if (is_numeric($seconds) && (int) $seconds > 0) {
                $thresholds[(string) $queue] = (int) $seconds;
            }
        }

        return $thresholds;
    }

    /**
     * Whether this observation raises an alert.
     *
     * @param  float|null  $age  Age in seconds of the oldest waiting job, null when nothing waits.
     */
    public function shouldAlert(string $queue, ?float $age, int $threshold, float $now): bool
    {
        if ($threshold <= 0 || $age === null || $age <= $threshold) {
            unset($this->alertedAt[$queue]);

            return false;
        }

        $last = $this->alertedAt[$queue] ?? null;

        if ($last !== null && $now - $last < self::COOLDOWN_SECONDS) {
            return false;
        }

        $this->alertedAt[$queue] = $now;

        return true;
    }

    /**
     * Forget the last alert: it never started, so the next check may alert again.
     */
    public function rearm(string $queue): void
    {
        unset($this->alertedAt[$queue]);
    }

    /**
     * Allow the next alert this many seconds after the last one instead of after
     * the cooldown, because that one failed. A queue that recovered stays recovered.
     */
    public function retryAfter(string $queue, int $seconds): void
    {
        if (isset($this->alertedAt[$queue])) {
            $this->alertedAt[$queue] -= self::COOLDOWN_SECONDS - $seconds;
        }
    }
}
