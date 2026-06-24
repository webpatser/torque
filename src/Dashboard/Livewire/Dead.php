<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\DeadLetterData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;
use Webpatser\Torque\Job\DeadLetterHandler;

/**
 * Dead-letter screen: search, select, retry and purge permanently failed jobs.
 */
#[Layout('torque::dashboard.layout')]
final class Dead extends Component
{
    use WithDashboardChrome;

    private const PER_PAGE = 8;

    public string $search = '';

    public int $page = 0;

    /** @var list<string> */
    public array $selected = [];

    public function updatedSearch(): void
    {
        $this->page = 0;
    }

    public function toggleSelect(string $id): void
    {
        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$id]));
        } else {
            $this->selected[] = $id;
        }
    }

    public function retryOne(string $id): void
    {
        rescue(fn () => app(DeadLetterHandler::class)->retry($id), null, false);
        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function purgeOne(string $id): void
    {
        rescue(fn () => app(DeadLetterHandler::class)->purge($id), null, false);
        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function retrySelected(): void
    {
        $handler = app(DeadLetterHandler::class);
        foreach ($this->selected as $id) {
            rescue(fn () => $handler->retry($id), null, false);
        }
        $this->selected = [];
    }

    public function purgeSelected(): void
    {
        $handler = app(DeadLetterHandler::class);
        foreach ($this->selected as $id) {
            rescue(fn () => $handler->purge($id), null, false);
        }
        $this->selected = [];
    }

    public function render()
    {
        $data = rescue(fn (): array => app(DeadLetterData::class)->list(null, 50), ['count' => 0, 'jobs' => []], false);

        $needle = trim(mb_strtolower($this->search));
        $list = array_values(array_filter($data['jobs'], function ($j) use ($needle) {
            if ($needle === '') {
                return true;
            }

            return str_contains(mb_strtolower((string) ($j['name'] ?? '')), $needle)
                || str_contains(mb_strtolower((string) ($j['exception'] ?? '')), $needle);
        }));

        $pageCount = max(1, (int) ceil(count($list) / self::PER_PAGE));
        $this->page = min($this->page, $pageCount - 1);
        $shown = array_slice($list, $this->page * self::PER_PAGE, self::PER_PAGE);

        return view('torque::dashboard.dead', [
            'shown' => $shown,
            'total' => count($list),
            'perPage' => self::PER_PAGE,
            'deadCount' => $data['count'],
            'workerCount' => $this->chrome()['workerCount'],
        ]);
    }
}
