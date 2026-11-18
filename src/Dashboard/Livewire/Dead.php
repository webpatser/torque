<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Webpatser\Torque\Dashboard\Data\DeadLetterData;
use Webpatser\Torque\Dashboard\Livewire\Concerns\WithDashboardChrome;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Support\Narrow;

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

    /** Why the last retry or purge did not (fully) happen, shown above the table. */
    #[Locked]
    public ?string $actionError = null;

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
        $handler = app(DeadLetterHandler::class);
        $this->actionError = $this->attempt(fn () => $handler->retry($id), 'Retry failed.');
        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function purgeOne(string $id): void
    {
        $handler = app(DeadLetterHandler::class);
        $this->actionError = $this->attempt(fn () => $handler->purge($id), 'Purge failed.');
        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function retrySelected(): void
    {
        $handler = app(DeadLetterHandler::class);
        $this->actionError = $this->attemptAll('Retried', fn (string $id) => $handler->retry($id), 'Retry failed.');
        $this->selected = [];
    }

    public function purgeSelected(): void
    {
        $handler = app(DeadLetterHandler::class);
        $this->actionError = $this->attemptAll('Purged', fn (string $id) => $handler->purge($id), 'Purge failed.');
        $this->selected = [];
    }

    /**
     * Run one action; null on success, otherwise why it failed. The handler's
     * own messages are shown as they are, anything else (a Redis error) is not.
     */
    private function attempt(\Closure $action, string $fallback): ?string
    {
        try {
            $action();
        } catch (\Throwable $e) {
            return $e instanceof \RuntimeException ? $e->getMessage() : $fallback;
        }

        return null;
    }

    /**
     * Run an action on every selected id; null when all succeeded, otherwise
     * the split and the first failure.
     *
     * @param  \Closure(string): mixed  $action
     */
    private function attemptAll(string $verb, \Closure $action, string $fallback): ?string
    {
        $failed = 0;
        $first = null;

        foreach ($this->selected as $id) {
            $error = $this->attempt(fn () => $action($id), $fallback);

            if ($error !== null) {
                $failed++;
                $first ??= $error;
            }
        }

        if ($first === null) {
            return null;
        }

        $total = count($this->selected);

        return sprintf('%s %d of %d entries; %d failed: %s', $verb, $total - $failed, $total, $failed, $first);
    }

    public function render(): View
    {
        $window = $this->range();

        // Dead-letter ids are millisecond epochs, so the window is a low id on
        // the XREVRANGE rather than a filter after the fact. It is still capped
        // by the stream's own TTL and MAXLEN, not by the range.
        $data = rescue(
            fn (): array => app(DeadLetterData::class)->list(null, 50, $window->sinceMs()),
            ['count' => 0, 'total' => 0, 'jobs' => []],
            false,
        );

        $needle = trim(mb_strtolower($this->search));
        $list = array_values(array_filter($data['jobs'], function ($j) use ($needle) {
            if ($needle === '') {
                return true;
            }

            return str_contains(mb_strtolower(Narrow::string($j['name'] ?? null)), $needle)
                || str_contains(mb_strtolower(Narrow::string($j['exception'] ?? null)), $needle);
        }));

        $pageCount = max(1, (int) ceil(count($list) / self::PER_PAGE));
        $this->page = min($this->page, $pageCount - 1);
        $shown = array_slice($list, $this->page * self::PER_PAGE, self::PER_PAGE);

        return view('torque::dashboard.dead', [
            'shown' => $shown,
            'total' => count($list),
            'perPage' => self::PER_PAGE,
            'window' => $window,
            // In range for the header, whole stream for the sidebar badge.
            'deadCount' => $data['count'],
            'deadTotal' => $data['total'],
            'workerCount' => $this->chrome()['workerCount'],
        ]);
    }
}
