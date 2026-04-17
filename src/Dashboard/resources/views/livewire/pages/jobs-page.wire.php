<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Dashboard\Concerns\AuthorizesTorqueAccess;

new class extends Component {
    use AuthorizesTorqueAccess;

    public string $status = 'active';

    public ?string $error = null;

    #[Computed]
    public function jobs(): array
    {
        try {
            $jobs = app(\Webpatser\Torque\Stream\JobStream::class)->recentJobs($this->status);
            $this->error = null;

            return $jobs;
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Failed to fetch jobs.';

            return [];
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->error = null;
        unset($this->jobs);
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="lg">Jobs</flux:heading>
        <flux:text class="text-sm mt-1">
            Recent jobs from event streams (TTL: {{ config('torque.job_streams.ttl', 300) }}s)
        </flux:text>
    </div>

    {{-- Error banner --}}
    @if($error)
        <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/50 p-3">
            <p class="text-sm text-red-700 dark:text-red-400">{{ $error }}</p>
        </div>
    @endif

    {{-- Sub-tabs --}}
    <div class="border-b border-zinc-200 dark:border-zinc-700 mb-6">
        <nav class="-mb-px flex gap-x-6">
            @foreach(['active' => 'Active', 'completed' => 'Completed', 'failed' => 'Failed'] as $key => $label)
                <button
                    wire:click="setStatus('{{ $key }}')"
                    @class([
                        'whitespace-nowrap border-b-2 py-3 px-1 text-sm font-medium transition-colors',
                        'border-indigo-500 text-indigo-600 dark:text-indigo-400' => $status === $key,
                        'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $status !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Jobs table --}}
    <flux:table>
        <flux:table.columns>
            <flux:table.column>Job</flux:table.column>
            <flux:table.column>Queue</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column>Progress</flux:table.column>
            <flux:table.column class="w-10"></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
                @forelse($this->jobs as $job)
                    @php
                        $displayName = $job['first_event']['data']['displayName']
                            ?? $job['first_event']['data']['display_name']
                            ?? $job['uuid'];
                        $shortName = class_basename($displayName);
                        $queue = $job['first_event']['data']['queue'] ?? 'default';
                        $lastType = $job['last_event']['type'];
                        $progress = isset($job['last_event']['data']['progress'])
                            ? (float) $job['last_event']['data']['progress']
                            : null;
                        $progressMessage = $job['last_event']['data']['message'] ?? null;

                        $statusColor = match($lastType) {
                            'completed' => 'green',
                            'failed', 'dead_lettered' => 'red',
                            'started', 'progress' => 'blue',
                            'exception' => 'amber',
                            'queued' => 'zinc',
                            default => 'zinc',
                        };
                        $statusLabel = match($lastType) {
                            'dead_lettered' => 'Dead',
                            default => ucfirst($lastType),
                        };
                    @endphp

                    <flux:table.row wire:key="job-{{ $job['uuid'] }}" class="cursor-pointer" wire:click="$dispatch('navigate-to-job', { uuid: '{{ $job['uuid'] }}' })">
                        <flux:table.cell>
                            <div>
                                <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $shortName }}</span>
                                <span class="block text-xs font-mono text-zinc-400 dark:text-zinc-500 truncate max-w-xs">{{ $job['uuid'] }}</span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge color="zinc" size="sm">{{ $queue }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge :color="$statusColor" size="sm">{{ $statusLabel }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($progress !== null)
                                <div class="flex items-center gap-2 min-w-[120px]">
                                    <div class="flex-1 h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                        <div class="h-full rounded-full bg-blue-500 transition-all" style="width: {{ min($progress * 100, 100) }}%"></div>
                                    </div>
                                    <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($progress * 100, 0) }}%</span>
                                </div>
                            @elseif($lastType === 'started')
                                <span class="flex items-center gap-1.5 text-xs text-blue-500">
                                    <span class="inline-block h-2 w-2 rounded-full bg-blue-500 animate-pulse"></span>
                                    Running
                                </span>
                            @else
                                <span class="text-zinc-400">--</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:icon name="chevron-right" class="size-4 text-zinc-400" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                @if($status === 'active')
                                    No active jobs
                                @elseif($status === 'completed')
                                    No completed jobs in the stream window
                                @else
                                    No failed jobs in the stream window
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
    </flux:table>
</div>
