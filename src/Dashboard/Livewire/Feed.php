<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\JobsData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;

/**
 * Live feed screen: recent jobs table (status-filtered) plus a synthesised
 * stream tail. The per-job API has no global event stream, so the tail is a
 * rolling terminal view derived from the live job summaries.
 */
#[Layout('torque::dashboard.layout')]
final class Feed extends Component
{
    use WithDashboardChrome;

    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function render()
    {
        $live = rescue(fn (): array => app(JobsData::class)->list('all'), [], false);

        $counts = [
            'all' => count($live),
            'active' => count(array_filter($live, fn ($j) => in_array($j['status'], ['running', 'retrying'], true))),
            'queued' => count(array_filter($live, fn ($j) => $j['status'] === 'queued')),
            'completed' => count(array_filter($live, fn ($j) => $j['status'] === 'completed')),
            'failed' => count(array_filter($live, fn ($j) => $j['status'] === 'failed')),
        ];

        $jobs = array_values(array_filter($live, function ($j) {
            return match ($this->filter) {
                'all' => true,
                'active' => in_array($j['status'], ['running', 'retrying'], true),
                default => $j['status'] === $this->filter,
            };
        }));

        $tail = collect($live)
            ->sortByDesc('ts')
            ->take(14)
            ->map(fn ($j) => ['t' => $j['ts'], 'type' => $j['status'], 'job' => $j['cls'], 'msg' => $j['worker'] ?: ($j['queue'] ?: '')])
            ->values()
            ->all();

        return view('torque::dashboard.feed', [
            'jobs' => $jobs,
            'counts' => $counts,
            'tail' => $tail,
            'deadCount' => $this->chrome()['deadCount'],
            'workerCount' => $this->chrome()['workerCount'],
        ]);
    }
}
