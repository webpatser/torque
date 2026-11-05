<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\BatchesData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;

/**
 * Read-only list of bus batches with a detail panel for one batch.
 *
 * Paging and selection are plain links to `?before=` and `?batch=`, backed by
 * URL-bound properties, so there are no wire:click arguments and no Alpine
 * expressions: it works under a CSP without 'unsafe-eval' (csp_safe).
 */
#[Layout('torque::dashboard.layout')]
final class Batches extends Component
{
    use WithDashboardChrome;

    private const PER_PAGE = 15;

    /** Cursor for the current page (the last id of the newer page); null is the newest page. */
    #[Url(as: 'before', except: '')]
    public ?string $before = null;

    /** The batch shown in the detail panel. */
    #[Url(as: 'batch', except: '')]
    public ?string $selected = null;

    public function render(): View
    {
        $data = app(BatchesData::class);
        $before = $this->before !== null && $this->before !== '' ? $this->before : null;
        $selected = $this->selected !== null && $this->selected !== '' ? $this->selected : null;

        $page = $data->list($before, self::PER_PAGE);

        return view('torque::dashboard.batches', [
            'page' => $page,
            // The view shows no detail panel without a list, so a failed list is reported once.
            'detail' => $selected !== null && $page['available'] ? $data->find($selected) : null,
            'selectedId' => $selected,
            'before' => $before,
            'deadCount' => $this->chrome()['deadCount'],
            'workerCount' => $this->chrome()['workerCount'],
        ]);
    }
}
