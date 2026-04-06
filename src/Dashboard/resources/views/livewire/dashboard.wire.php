<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Webpatser\Torque\Metrics\MetricsPublisher;

new class extends Component {
    public int $pollInterval = 2000;

    public string $activeTab = 'overview';

    public function mount(): void
    {
        $this->pollInterval = config('torque.dashboard.default_poll_interval', 2000);
    }

    #[Computed]
    public function metrics(): array
    {
        return app(MetricsPublisher::class)->getAggregatedMetrics();
    }

    #[Computed]
    public function workers(): array
    {
        return app(MetricsPublisher::class)->getAllWorkerMetrics();
    }

    #[Computed]
    public function isRunning(): bool
    {
        $metrics = $this->metrics;

        if ($metrics === []) {
            return false;
        }

        $updatedAt = (int) ($metrics['updated_at'] ?? 0);

        return (time() - $updatedAt) < 30;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    #[On('poll-interval-changed')]
    public function updatePollInterval(int $interval): void
    {
        $this->pollInterval = $interval;
    }
};
?>

<div
    @if($pollInterval > 0) wire:poll.{{ $pollInterval }}ms @endif
    class="min-h-screen bg-zinc-50 dark:bg-zinc-900"
>
    {{-- Header --}}
    <div class="border-b border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Torque</h1>
                    @if($this->isRunning)
                        <flux:badge color="green" size="sm">Running</flux:badge>
                    @else
                        <flux:badge color="red" size="sm">Stopped</flux:badge>
                    @endif
                    @if($this->isRunning)
                        <flux:badge color="zinc" size="sm">
                            {{ count($this->workers) }} {{ str('worker')->plural(count($this->workers)) }}
                        </flux:badge>
                    @endif
                </div>
                <livewire:torque.poll-interval :interval="$pollInterval" />
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-6">
        <div class="border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-x-6" aria-label="Tabs">
                @foreach(['overview' => 'Overview', 'workers' => 'Workers', 'streams' => 'Streams', 'failed' => 'Failed Jobs'] as $key => $label)
                    <button
                        wire:click="setTab('{{ $key }}')"
                        wire:key="tab-{{ $key }}"
                        @class([
                            'whitespace-nowrap border-b-2 py-3 px-1 text-sm font-medium transition-colors',
                            'border-indigo-500 text-indigo-600 dark:text-indigo-400' => $activeTab === $key,
                            'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $activeTab !== $key,
                        ])
                    >
                        {{ $label }}
                        @if($key === 'failed')
                            @php $failedCount = (int) ($this->metrics['jobs_failed'] ?? 0); @endphp
                            @if($failedCount > 0)
                                <flux:badge color="red" size="sm" class="ml-1.5">{{ $failedCount }}</flux:badge>
                            @endif
                        @endif
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Tab content --}}
        <div class="py-6">
            @if($activeTab === 'overview')
                <div class="space-y-6">
                    <livewire:torque.metric-cards :metrics="$this->metrics" />
                    <livewire:torque.workers-table :workers="$this->workers" />
                </div>
            @elseif($activeTab === 'workers')
                <livewire:torque.workers-table :workers="$this->workers" />
            @elseif($activeTab === 'streams')
                <livewire:torque.streams-table />
            @elseif($activeTab === 'failed')
                <livewire:torque.failed-jobs />
            @endif
        </div>
    </div>
</div>
