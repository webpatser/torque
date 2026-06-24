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

    /** @var list<float|int> */
    public array $throughput = [];

    /** @var list<float|int> */
    public array $latency = [];

    /** @var list<float|int> */
    public array $concurrent = [];

    /** @var list<float|int> */
    public array $memory = [];

    /** @var list<float|int> */
    public array $failRate = [];

    /** @var list<float|int> */
    public array $pending = [];

    public function render()
    {
        $data = rescue(fn (): array => app(OverviewData::class)->get(), $this->emptyOverview(), false);

        $totals = $data['totals'];
        $metrics = $data['metrics'];

        // Accumulate rolling histories for the sparklines.
        $this->throughput = $this->pushHistory($this->throughput, (float) $metrics['throughput']);
        $this->latency = $this->pushHistory($this->latency, (float) $metrics['latencyMs'] / 1000);
        $this->concurrent = $this->pushHistory($this->concurrent, (int) $metrics['concurrent']);
        $this->memory = $this->pushHistory($this->memory, (float) $metrics['memoryMb']);
        $this->failRate = $this->pushHistory($this->failRate, (float) $metrics['failRate']);
        $this->pending = $this->pushHistory($this->pending, (int) $totals['pending']);

        return view('torque::dashboard.overview', [
            'totals' => $totals,
            'metrics' => $metrics,
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
            'totals' => ['slots' => 0, 'busy' => 0, 'pending' => 0, 'delayed' => 0, 'rpm' => 0, 'util' => 0],
            'metrics' => ['throughput' => 0, 'concurrent' => 0, 'latencyMs' => 0, 'memoryMb' => 0, 'failRate' => 0, 'jobsTotal' => 0, 'workers' => 0],
            'live' => [],
            'deadCount' => 0,
        ];
    }
}
