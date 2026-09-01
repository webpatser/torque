<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\QueuesData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;

/**
 * Queues screen: per-stream depth over the configured Redis Streams.
 *
 * Everything the screen draws now comes from the per-stream rollups over the
 * global range, so nothing accumulates in component state and a reload shows
 * the same history the previous tab was showing.
 */
#[Layout('torque::dashboard.layout')]
final class Queues extends Component
{
    use WithDashboardChrome;

    public function render()
    {
        $window = $this->range();
        $queues = rescue(fn (): array => app(QueuesData::class)->get($window->key)['queues'], [], false);

        $totals = [
            'pending' => array_sum(array_column($queues, 'pending')),
            'delayed' => array_sum(array_column($queues, 'delayed')),
            'processed' => array_sum(array_map(fn ($q) => (int) ($q['processed'] ?? 0), $queues)),
            'failed' => array_sum(array_map(fn ($q) => (int) ($q['failed'] ?? 0), $queues)),
        ];

        return view('torque::dashboard.queues', [
            'queues' => $queues,
            'totals' => $totals,
            'window' => $window,
            'hasProcessed' => collect($queues)->contains(fn ($q) => $q['processed'] !== null),
            'hasThroughput' => collect($queues)->contains(fn ($q) => $q['throughput'] !== null),
            'hasWait' => collect($queues)->contains(fn ($q) => $q['wait'] !== null),
            // Unlike the others this is a "> 0" test: a permanently visible
            // column of zeros would be noise on a healthy cluster.
            'hasFailed' => collect($queues)->contains(fn ($q) => (int) ($q['failed'] ?? 0) > 0),
            'deadCount' => $this->chrome()['deadCount'],
            'workerCount' => $this->chrome()['workerCount'],
        ]);
    }
}
