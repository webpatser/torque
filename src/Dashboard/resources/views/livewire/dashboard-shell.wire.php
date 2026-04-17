<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Webpatser\Torque\Dashboard\Concerns\AuthorizesTorqueAccess;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Metrics\MetricsPublisher;

new class extends Component {
    use AuthorizesTorqueAccess;

    public int $pollInterval = 1000;

    public string $page = 'overview';

    public ?string $jobUuid = null;

    private const array INTERVALS = [
        1000 => '1s',
        2000 => '2s',
        5000 => '5s',
        10000 => '10s',
        30000 => '30s',
    ];

    public function mount(?string $view = null): void
    {
        $this->pollInterval = config('torque.dashboard.default_poll_interval', 1000);

        if ($view !== null) {
            $this->parseView($view);
        }
    }

    #[Computed]
    public function workers(): array
    {
        return app(MetricsPublisher::class)->getAllWorkerMetrics();
    }

    #[Computed]
    public function metrics(): array
    {
        return app(MetricsPublisher::class)->aggregateFromWorkers($this->workers);
    }

    #[Computed]
    public function deadLetterCount(): int
    {
        return app(DeadLetterHandler::class)->count();
    }

    #[Computed]
    public function isRunning(): bool
    {
        $updatedAt = (int) ($this->metrics['updated_at'] ?? 0);

        return $updatedAt > 0 && (time() - $updatedAt) < 30;
    }

    public function navigate(string $page, ?string $uuid = null): void
    {
        $this->page = $page;
        $this->jobUuid = $uuid;

        $this->dispatch('torque-url-changed', url: $this->buildUrl($page, $uuid));
    }

    /**
     * Re-parse the current URL after the browser fires popstate so back/forward
     * navigation works. The browser sends the path it wants us to honor.
     */
    #[On('torque-url-popped')]
    public function syncFromUrl(string $path): void
    {
        $base = '/' . trim((string) config('torque.dashboard.path', 'torque'), '/');
        $view = ltrim(str_starts_with($path, $base) ? substr($path, strlen($base)) : $path, '/');

        if ($view === '') {
            $this->page = 'overview';
            $this->jobUuid = null;

            return;
        }

        $this->parseView($view);
    }

    private function buildUrl(string $page, ?string $uuid): string
    {
        $base = '/' . trim((string) config('torque.dashboard.path', 'torque'), '/');

        return match ($page) {
            'overview' => $base,
            'job-inspector' => $uuid !== null
                ? $base . '/jobs/' . rawurlencode($uuid)
                : $base . '/jobs',
            'jobs', 'streams', 'workers', 'failed', 'settings' => $base . '/' . $page,
            default => $base,
        };
    }

    public function setPollInterval(int $interval): void
    {
        $this->pollInterval = $interval;
    }

    #[On('navigate-to-job')]
    public function navigateToJob(string $uuid): void
    {
        $this->page = 'job-inspector';
        $this->jobUuid = $uuid;
    }

    public function with(): array
    {
        return [
            'intervals' => self::INTERVALS,
        ];
    }

    private function parseView(string $view): void
    {
        $segments = explode('/', trim($view, '/'));
        $candidate = $segments[1] ?? null;

        $this->page = match ($segments[0] ?? '') {
            'jobs' => $candidate !== null ? 'job-inspector' : 'jobs',
            'streams' => 'streams',
            'workers' => 'workers',
            'failed' => 'failed',
            'settings' => 'settings',
            default => 'overview',
        };

        // Jobs addressed by UUID must match the RFC 4122 shape before being
        // used as a Redis stream key suffix.
        if ($this->page === 'job-inspector') {
            if ($candidate !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate) === 1) {
                $this->jobUuid = $candidate;
            } else {
                $this->page = 'jobs';
                $this->jobUuid = null;
            }
        }
    }
};
?>

<div class="min-h-screen" @if($pollInterval > 0 && $page !== 'job-inspector') wire:poll.{{ $pollInterval }}ms @endif>
    {{-- Desktop Sidebar --}}
    <flux:sidebar sticky stashable class="border-e border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <div class="flex items-center gap-2 px-1">
            <flux:icon name="bolt" class="size-6 text-indigo-500" />
            <span class="text-lg font-semibold text-zinc-900 dark:text-white">Torque</span>
            @if($this->isRunning)
                <flux:badge color="green" size="sm">Running</flux:badge>
            @else
                <flux:badge color="red" size="sm">Stopped</flux:badge>
            @endif
        </div>

        <flux:navlist variant="outline">
            <flux:navlist.item icon="chart-bar-square" wire:click="navigate('overview')" :current="$page === 'overview'">
                Overview
            </flux:navlist.item>
            <flux:navlist.item icon="queue-list" wire:click="navigate('jobs')" :current="$page === 'jobs' || $page === 'job-inspector'">
                Jobs
            </flux:navlist.item>
            <flux:navlist.item icon="arrow-path" wire:click="navigate('streams')" :current="$page === 'streams'">
                Streams
            </flux:navlist.item>
            <flux:navlist.item icon="cpu-chip" wire:click="navigate('workers')" :current="$page === 'workers'">
                Workers
            </flux:navlist.item>
            <flux:navlist.item icon="exclamation-triangle" wire:click="navigate('failed')" :current="$page === 'failed'"
                badge="{{ $this->deadLetterCount > 0 ? number_format($this->deadLetterCount) : '' }}"
                badge:color="red"
            >
                Failed Jobs
            </flux:navlist.item>
            <flux:navlist.item icon="cog-6-tooth" wire:click="navigate('settings')" :current="$page === 'settings'">
                Settings
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        {{-- Worker count --}}
        @if($this->isRunning)
            <flux:text class="text-xs flex items-center gap-2">
                <span class="inline-block h-2 w-2 rounded-full bg-green-500 animate-pulse"></span>
                {{ count($this->workers) }} {{ str('worker')->plural(count($this->workers)) }}
            </flux:text>
        @endif

        {{-- Refresh interval switch --}}
        <div>
            <div class="flex items-center justify-between mb-1.5">
                <flux:text class="text-xs font-medium uppercase tracking-wide">Refresh</flux:text>
                @if($pollInterval > 0)
                    <button wire:click="setPollInterval(0)" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors">Pause</button>
                @else
                    <button wire:click="setPollInterval(1000)" class="text-xs text-indigo-500 hover:text-indigo-600 transition-colors">Resume</button>
                @endif
            </div>
            <div class="flex rounded-lg border border-zinc-200 dark:border-zinc-600 overflow-hidden">
                @foreach($intervals as $ms => $label)
                    <button
                        wire:click="setPollInterval({{ $ms }})"
                        @class([
                            'flex-1 py-1.5 text-xs font-medium tabular-nums transition-colors',
                            'bg-indigo-500 text-white' => $pollInterval === $ms,
                            'text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-700' => $pollInterval !== $ms,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
            @if($pollInterval === 0)
                <flux:text class="mt-1.5 text-xs flex items-center gap-1.5">
                    <span class="inline-block h-2 w-2 rounded-full bg-zinc-400"></span>
                    Paused
                </flux:text>
            @endif
        </div>
    </flux:sidebar>

    {{-- Mobile Header --}}
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <div class="flex items-center gap-2">
            <flux:icon name="bolt" class="size-5 text-indigo-500" />
            <span class="font-semibold text-zinc-900 dark:text-white">Torque</span>
            @if($this->isRunning)
                <flux:badge color="green" size="sm">Running</flux:badge>
            @else
                <flux:badge color="red" size="sm">Stopped</flux:badge>
            @endif
        </div>

        <flux:spacer />

        {{-- Mobile refresh indicator --}}
        <flux:text class="text-xs tabular-nums flex items-center gap-1.5">
            @if($pollInterval > 0)
                <span class="inline-block h-2 w-2 rounded-full bg-green-500 animate-pulse"></span>
                {{ $intervals[$pollInterval] ?? ($pollInterval / 1000) . 's' }}
            @else
                <span class="inline-block h-2 w-2 rounded-full bg-zinc-400"></span>
                Paused
            @endif
        </flux:text>
    </flux:header>

    {{-- Main Content --}}
    <flux:main container>
        @if($page === 'overview')
            <livewire:torque.overview-page
                :metrics="$this->metrics"
                :workers="$this->workers"
                wire:key="page-overview"
            />
        @elseif($page === 'jobs')
            <livewire:torque.jobs-page wire:key="page-jobs" />
        @elseif($page === 'job-inspector' && $jobUuid)
            <livewire:torque.job-inspector-page
                :uuid="$jobUuid"
                :poll-interval="$pollInterval"
                wire:key="page-job-{{ $jobUuid }}"
            />
        @elseif($page === 'streams')
            <livewire:torque.streams-page wire:key="page-streams" />
        @elseif($page === 'workers')
            <livewire:torque.workers-page
                :workers="$this->workers"
                wire:key="page-workers"
            />
        @elseif($page === 'failed')
            <livewire:torque.failed-jobs-page wire:key="page-failed" />
        @elseif($page === 'settings')
            <livewire:torque.settings-page wire:key="page-settings" />
        @endif
    </flux:main>
</div>
