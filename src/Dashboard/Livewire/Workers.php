<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\OverviewData;
use Webpatser\Torque\Dashboard\Data\WorkersData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;

/**
 * Workers screen: per-worker slot pressure, pools, throughput and memory.
 *
 * Per-worker slot-usage histories accumulate on the component across polls so
 * each worker's sparkline keeps its live feel.
 */
#[Layout('torque::dashboard.layout')]
final class Workers extends Component
{
    use WithDashboardChrome;

    /** @var array<string, list<float>> */
    public array $history = [];

    public function render()
    {
        $workers = rescue(fn (): array => app(WorkersData::class)->get()['workers'], [], false);
        $totals = rescue(
            fn (): array => app(OverviewData::class)->get()['totals'],
            ['slots' => 0, 'busy' => 0, 'pending' => 0, 'delayed' => 0, 'rpm' => 0, 'util' => 0],
            false,
        );

        // Accumulate per-worker slot-usage history, pruning vanished workers.
        $ids = [];
        foreach ($workers as &$w) {
            $id = $w['id'];
            $ids[$id] = true;
            $frac = $w['slots'] > 0 ? $w['busy'] / $w['slots'] : 0;
            $this->history[$id] = $this->pushHistory($this->history[$id] ?? [], $frac);
            $w['history'] = array_map(fn ($v) => $v * 100, $this->history[$id]);
        }
        unset($w);
        $this->history = array_intersect_key($this->history, $ids);

        $active = count(array_filter($workers, fn ($w) => ($w['status'] ?? 'active') === 'active'));

        return view('torque::dashboard.workers', [
            'workers' => $workers,
            'totals' => $totals,
            'active' => $active,
            'deadCount' => $this->chrome()['deadCount'],
        ]);
    }
}
