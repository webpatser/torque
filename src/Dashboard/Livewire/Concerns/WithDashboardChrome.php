<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire\Concerns;

use Webpatser\Torque\Dashboard\Support\Range;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * Shared dashboard chrome state for the full-page Livewire screens.
 *
 * Owns the live-refresh interval (driven by the topbar poll selector and
 * consumed by `wire:poll` in the shell), the global time range every screen
 * reads, and the sidebar badge counts.
 */
trait WithDashboardChrome
{
    /**
     * Live-refresh interval in milliseconds; 0 pauses polling.
     *
     * Null until the boot hook seeds it from config, so a user-chosen 0
     * (paused) is never overwritten on subsequent requests.
     */
    public ?int $pollInterval = null;

    /**
     * Session key that remembers the chosen interval across screens and reloads.
     */
    private const string POLL_SESSION_KEY = 'torque.poll_interval';

    /**
     * Global time range key; null until the boot hook seeds it.
     *
     * Server-rendered rather than browser state: every screen polls, and a
     * morph would be free to reset whatever the client was holding.
     */
    public ?string $range = null;

    /**
     * Session key that remembers the chosen range across screens and reloads,
     * the same way the poll interval is remembered.
     */
    private const string RANGE_SESSION_KEY = 'torque.range';

    /**
     * Seed the poll interval on first load: the interval chosen earlier in this
     * session wins, then the configured default.
     */
    public function bootWithDashboardChrome(): void
    {
        if ($this->pollInterval === null) {
            $remembered = session(self::POLL_SESSION_KEY);

            $this->pollInterval = is_int($remembered) && $this->isAllowedPollInterval($remembered)
                ? $remembered
                : (int) config('torque.dashboard.default_poll_interval', 2000);
        }

        if ($this->range === null) {
            $remembered = session(self::RANGE_SESSION_KEY);

            $this->range = is_string($remembered) && Range::isValid($remembered)
                ? $remembered
                : (string) config('torque.dashboard.default_range', Range::DEFAULT);
        }
    }

    /**
     * Update the global time range from the topbar picker and remember it for
     * the rest of the session. An unknown key is ignored rather than trusted
     * into the read model.
     */
    public function setRange(string $range): void
    {
        if (! Range::isValid($range)) {
            return;
        }

        $this->range = $range;
        session([self::RANGE_SESSION_KEY => $range]);
    }

    /**
     * The resolved range for this request, for the render methods.
     */
    protected function range(): Range
    {
        return Range::make($this->range);
    }

    /**
     * Update the live-refresh interval from the topbar selector and remember
     * it for the rest of the session. Values outside the configured list fall
     * back to the default so a hand-crafted request cannot set a 1ms poll.
     */
    public function setPollInterval(int $interval): void
    {
        $interval = max(0, $interval);

        if (! $this->isAllowedPollInterval($interval)) {
            $interval = (int) config('torque.dashboard.default_poll_interval', 2000);
        }

        $this->pollInterval = $interval;
        session([self::POLL_SESSION_KEY => $interval]);
    }

    private function isAllowedPollInterval(int $interval): bool
    {
        $allowed = config('torque.dashboard.poll_intervals', [0, 1000, 2000, 5000, 10000, 30000]);

        return in_array($interval, array_map(intval(...), (array) $allowed), true);
    }

    /**
     * Sidebar badge counts. Degrades to zero/null when Redis is unreachable so
     * the chrome still renders.
     *
     * @return array{deadCount: int, workerCount: int|null}
     */
    protected function chrome(): array
    {
        return [
            'deadCount' => rescue(fn (): int => app(DeadLetterHandler::class)->count(), 0, false),
            'workerCount' => rescue(fn (): int => count(app(MetricsPublisher::class)->getAllWorkerMetrics()), null, false),
        ];
    }

    /**
     * Append a value to a rolling history series, capped to the most recent N.
     *
     * @param  list<float|int>  $series
     * @return list<float|int>
     */
    protected function pushHistory(array $series, float|int $value, int $cap = 40): array
    {
        $series[] = $value;

        return array_slice($series, -$cap);
    }
}
