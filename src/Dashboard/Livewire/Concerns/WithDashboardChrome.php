<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire\Concerns;

use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * Shared dashboard chrome state for the full-page Livewire screens.
 *
 * Owns the live-refresh interval (driven by the topbar poll selector and
 * consumed by `wire:poll` in the shell) and the sidebar badge counts.
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
     * Seed the poll interval from config on first load.
     */
    public function bootWithDashboardChrome(): void
    {
        if ($this->pollInterval === null) {
            $this->pollInterval = (int) config('torque.dashboard.default_poll_interval', 2000);
        }
    }

    /**
     * Update the live-refresh interval from the topbar selector.
     */
    public function setPollInterval(int $interval): void
    {
        $this->pollInterval = max(0, $interval);
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
