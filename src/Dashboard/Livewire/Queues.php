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
 * Per-stream pending-depth histories accumulate on the component across polls
 * to drive the depth mini-bars.
 */
#[Layout('torque::dashboard.layout')]
final class Queues extends Component
{
    use WithDashboardChrome;

    /** @var array<string, list<int|float>> */
    public array $history = [];

    public function render()
    {
        $queues = rescue(fn (): array => app(QueuesData::class)->get()['queues'], [], false);

        $names = [];
        foreach ($queues as &$q) {
            $name = $q['name'];
            $names[$name] = true;
            $this->history[$name] = $this->pushHistory($this->history[$name] ?? [], (int) $q['pending']);
            $q['history'] = $this->history[$name];
        }
        unset($q);
        $this->history = array_intersect_key($this->history, $names);

        $totals = [
            'pending' => array_sum(array_column($queues, 'pending')),
            'delayed' => array_sum(array_column($queues, 'delayed')),
            'today' => array_sum(array_map(fn ($q) => (int) ($q['processedToday'] ?? 0), $queues)),
        ];

        return view('torque::dashboard.queues', [
            'queues' => $queues,
            'totals' => $totals,
            'hasToday' => collect($queues)->contains(fn ($q) => $q['processedToday'] !== null),
            'hasThroughput' => collect($queues)->contains(fn ($q) => $q['throughput'] !== null),
            'hasWait' => collect($queues)->contains(fn ($q) => $q['wait'] !== null),
            'deadCount' => $this->chrome()['deadCount'],
            'workerCount' => $this->chrome()['workerCount'],
        ]);
    }
}
