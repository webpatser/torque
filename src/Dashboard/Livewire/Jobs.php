<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\JobMetricsData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;

/**
 * Jobs screen: throughput, runtime and failure rate per job class.
 *
 * Range and sort are plain Livewire properties rather than Alpine state. The
 * screen polls, and a morph is free to reset anything the browser was holding
 * on its own, so the server has to own both.
 */
#[Layout('torque::dashboard.layout')]
final class Jobs extends Component
{
    use WithDashboardChrome;

    /** Range of the throughput column and the sparklines: 1h, 24h, 7d or 90d. */
    public string $range = '1h';

    /** Sort key: throughput, runtime, failures or name. */
    public string $sort = 'throughput';

    /** Sort direction: desc or asc. */
    public string $direction = 'desc';

    public function setRange(string $range): void
    {
        if (JobMetricsData::isValidRange($range)) {
            $this->range = $range;
        }
    }

    /**
     * Sort by a column, flipping the direction when it is already the sort key.
     */
    public function sortBy(string $sort): void
    {
        if (! JobMetricsData::isValidSort($sort)) {
            return;
        }

        if ($this->sort === $sort) {
            $this->direction = $this->direction === 'desc' ? 'asc' : 'desc';

            return;
        }

        $this->sort = $sort;
        // Names read best A to Z; every other column is interesting at the top.
        $this->direction = $sort === 'name' ? 'asc' : 'desc';
    }

    public function render()
    {
        $data = rescue(
            fn (): array => app(JobMetricsData::class)->get($this->range, $this->sort, $this->direction),
            ['jobs' => [], 'totals' => ['classes' => 0, 'processed' => 0, 'failed' => 0, 'slowest' => 0.0]],
            false,
        );

        $chrome = $this->chrome();

        return view('torque::dashboard.jobs', [
            'jobs' => $data['jobs'],
            'totals' => $data['totals'],
            'range' => $this->range,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'deadCount' => $chrome['deadCount'],
            'workerCount' => $chrome['workerCount'],
        ]);
    }
}
