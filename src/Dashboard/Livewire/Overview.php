<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\OverviewData;
use Webpatser\Torque\Dashboard\Data\WorkersData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;

/**
 * Cluster overview screen: throughput gauge, headline stats, live activity.
 *
 * Rolling metric histories accumulate on the component across polls so the
 * sparklines keep their live feel (the React store did this client-side).
 */
#[Layout('torque::dashboard.layout')]
final class Overview extends Component
{
    use WithDashboardChrome;

    /**
     * Throughput chart range: 1h, 24h, 7d or 90d.
     *
     * Server-rendered rather than Alpine state, so the polled re-render cannot
     * lose it and the seg buttons morph cleanly.
     */
    public string $range = '1h';

    /**
     * Switch the throughput chart range from the card-head seg buttons.
     */
    public function setRange(string $range): void
    {
        if (OverviewData::isValidRange($range)) {
            $this->range = $range;
        }
    }

    public function render()
    {
        $data = rescue(fn (): array => app(OverviewData::class)->get($this->range), $this->emptyOverview(), false);

        $totals = $data['totals'];
        $metrics = $data['metrics'];

        // Every sparkline on this screen is served from the persisted rollups,
        // so nothing here accumulates in component state: a reload shows the
        // same hour of history the previous tab was showing.
        return view('torque::dashboard.overview', [
            'totals' => $totals,
            'metrics' => $metrics,
            'history' => $data['history'],
            'series' => $data['series'],
            'minuteHistory' => $data['minuteHistory'],
            'range' => $this->range,
            'live' => array_slice($data['live'], 0, 6),
            'deadCount' => $data['deadCount'],
            'workers' => rescue(fn (): array => app(WorkersData::class)->get()['workers'], [], false),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOverview(): array
    {
        return [
            'totals' => ['slots' => 0, 'busy' => 0, 'pending' => 0, 'delayed' => 0, 'rpm' => 0, 'gaugeMax' => 100, 'util' => 0],
            'metrics' => ['throughput' => 0, 'throughputPerMinute' => 0, 'jobsLastHour' => 0, 'concurrent' => 0, 'latencyMs' => 0, 'memoryMb' => 0, 'failRate' => 0, 'jobsTotal' => 0, 'workers' => 0],
            'history' => [],
            'minuteHistory' => [],
            'series' => ['latency' => [], 'concurrent' => [], 'memory' => [], 'memoryPeak' => [], 'pending' => [], 'delayed' => [], 'failRate' => []],
            'live' => [],
            'deadCount' => 0,
        ];
    }
}
