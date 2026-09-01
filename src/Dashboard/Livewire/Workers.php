<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\OverviewData;
use Webpatser\Torque\Dashboard\Data\WorkersData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;

/**
 * Workers screen: slot pressure, throughput and memory per host.
 *
 * Grouped by host because that is the identity with a history; see
 * {@see WorkersData}. Nothing accumulates in component state any more: the
 * sparkline is the per-host rollup over the global range, so it survives a
 * reload instead of filling up one poll at a time.
 */
#[Layout('torque::dashboard.layout')]
final class Workers extends Component
{
    use WithDashboardChrome;

    public function render()
    {
        $window = $this->range();
        $hosts = rescue(fn (): array => app(WorkersData::class)->get($window->key)['hosts'], [], false);
        $totals = rescue(
            fn (): array => app(OverviewData::class)->get($window->key)['totals'],
            ['slots' => 0, 'busy' => 0, 'pending' => 0, 'delayed' => 0, 'rpm' => 0, 'util' => 0],
            false,
        );

        $chrome = $this->chrome();

        return view('torque::dashboard.workers', [
            'hosts' => $hosts,
            'totals' => $totals,
            'window' => $window,
            'deadCount' => $chrome['deadCount'],
            'workerCount' => $chrome['workerCount'],
        ]);
    }
}
