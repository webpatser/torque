<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Job\DeadLetterHandler;

new class extends Component {
    public int $limit = 50;

    public ?string $expandedId = null;

    #[Computed]
    public function failedJobs(): array
    {
        return $this->handler()->list($this->limit);
    }

    public function toggle(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function retry(string $id): void
    {
        try {
            $this->handler()->retry($id);
        } catch (\RuntimeException) {
            // Already retried or purged.
        }

        $this->expandedId = null;
        unset($this->failedJobs);
    }

    public function purge(string $id): void
    {
        $this->handler()->purge($id);
        $this->expandedId = null;
        unset($this->failedJobs);
    }

    public function retryAll(): void
    {
        $handler = $this->handler();

        foreach ($this->failedJobs as $job) {
            try {
                $handler->retry($job['id']);
            } catch (\RuntimeException) {
                continue;
            }
        }

        $this->expandedId = null;
        unset($this->failedJobs);
    }

    public function purgeAll(): void
    {
        $handler = $this->handler();

        foreach ($this->failedJobs as $job) {
            $handler->purge($job['id']);
        }

        $this->expandedId = null;
        unset($this->failedJobs);
    }

    private function handler(): DeadLetterHandler
    {
        return app(DeadLetterHandler::class);
    }

    private function decodePayload(string $payload): array
    {
        if (!json_validate($payload)) {
            return [];
        }

        return json_decode($payload, true);
    }
};
?>

<div>
    @if(count($this->failedJobs) > 0)
        <div class="mb-4 flex items-center justify-between">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                Showing {{ count($this->failedJobs) }} failed {{ str('job')->plural(count($this->failedJobs)) }}
            </flux:text>
            <div class="flex items-center gap-2">
                <flux:button
                    wire:click="purgeAll"
                    wire:confirm="Permanently delete all failed jobs?"
                    variant="ghost"
                    size="sm"
                >
                    Delete All
                </flux:button>
                <flux:button
                    wire:click="retryAll"
                    wire:confirm="Retry all failed jobs?"
                    variant="primary"
                    size="sm"
                >
                    <span wire:loading.remove wire:target="retryAll">Retry All</span>
                    <span wire:loading wire:target="retryAll">Retrying...</span>
                </flux:button>
            </div>
        </div>
    @endif

    <div class="space-y-2">
        @forelse($this->failedJobs as $job)
            @php
                $decoded = json_validate($job['payload']) ? json_decode($job['payload'], true) : [];
                $jobName = $decoded['displayName'] ?? $decoded['job'] ?? 'Unknown';
                $isExpanded = $expandedId === $job['id'];
            @endphp

            <flux:card wire:key="failed-{{ $job['id'] }}" class="!p-0 overflow-hidden">
                {{-- Summary row --}}
                <button
                    wire:click="toggle('{{ $job['id'] }}')"
                    class="w-full px-4 py-3 flex items-center gap-4 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                >
                    <div class="shrink-0">
                        <svg class="size-4 text-zinc-400 transition-transform {{ $isExpanded ? 'rotate-90' : '' }}" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">
                                {{ class_basename($jobName) }}
                            </span>
                            <flux:badge color="zinc" size="sm">{{ $job['original_queue'] }}</flux:badge>
                        </div>
                        <div class="mt-0.5 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="text-red-600 dark:text-red-400 font-medium">{{ class_basename($job['exception_class']) }}</span>
                            <span class="truncate">{{ \Illuminate\Support\Str::limit($job['exception_message'], 80) }}</span>
                        </div>
                    </div>

                    <div class="shrink-0 text-right">
                        <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                            {{ $job['failed_at'] ? \Carbon\Carbon::parse($job['failed_at'])->diffForHumans() : '' }}
                        </span>
                    </div>
                </button>

                {{-- Expanded detail --}}
                @if($isExpanded)
                    <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/30">
                        <div class="px-4 py-4 space-y-4">
                            {{-- Job info grid --}}
                            <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">Job</span>
                                    <p class="font-mono text-xs text-zinc-900 dark:text-zinc-100 break-all">{{ $jobName }}</p>
                                </div>
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">Queue</span>
                                    <p class="text-zinc-900 dark:text-zinc-100">{{ $job['original_queue'] }}</p>
                                </div>
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">Failed At</span>
                                    <p class="tabular-nums text-zinc-900 dark:text-zinc-100">
                                        {{ $job['failed_at'] ? \Carbon\Carbon::parse($job['failed_at'])->format('Y-m-d H:i:s T') : '-' }}
                                    </p>
                                </div>
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">Message ID</span>
                                    <p class="font-mono text-xs text-zinc-900 dark:text-zinc-100">{{ $job['id'] }}</p>
                                </div>
                                @if(!empty($decoded['attempts']))
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400">Attempts</span>
                                        <p class="text-zinc-900 dark:text-zinc-100">{{ $decoded['attempts'] }}</p>
                                    </div>
                                @endif
                                @if(!empty($decoded['maxTries']))
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400">Max Tries</span>
                                        <p class="text-zinc-900 dark:text-zinc-100">{{ $decoded['maxTries'] }}</p>
                                    </div>
                                @endif
                            </div>

                            {{-- Exception --}}
                            <div>
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">Exception</span>
                                <div class="mt-1 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/50 p-3">
                                    <p class="text-sm font-medium text-red-800 dark:text-red-300">{{ $job['exception_class'] }}</p>
                                    <p class="mt-1 text-sm text-red-700 dark:text-red-400 break-words">{{ $job['exception_message'] }}</p>
                                </div>
                            </div>

                            {{-- Stack trace --}}
                            @if(!empty($job['exception_trace']))
                                <div x-data="{ showTrace: false }">
                                    <button
                                        @click="showTrace = !showTrace"
                                        class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300 flex items-center gap-1"
                                    >
                                        <svg class="size-3 transition-transform" :class="showTrace && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                        </svg>
                                        Stack Trace
                                    </button>
                                    <div x-show="showTrace" x-collapse>
                                        <pre class="mt-2 rounded-lg bg-zinc-900 dark:bg-zinc-950 p-3 text-xs text-zinc-300 overflow-x-auto max-h-80 overflow-y-auto leading-relaxed">{{ $job['exception_trace'] }}</pre>
                                    </div>
                                </div>
                            @endif

                            {{-- Payload --}}
                            <div x-data="{ showPayload: false }">
                                <button
                                    @click="showPayload = !showPayload"
                                    class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300 flex items-center gap-1"
                                >
                                    <svg class="size-3 transition-transform" :class="showPayload && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                    Payload
                                </button>
                                <div x-show="showPayload" x-collapse>
                                    <pre class="mt-2 rounded-lg bg-zinc-900 dark:bg-zinc-950 p-3 text-xs text-zinc-300 overflow-x-auto max-h-80 overflow-y-auto leading-relaxed">{{ json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:button
                                    wire:click="retry('{{ $job['id'] }}')"
                                    variant="primary"
                                    size="sm"
                                >
                                    <span wire:loading.remove wire:target="retry('{{ $job['id'] }}')">Retry Job</span>
                                    <span wire:loading wire:target="retry('{{ $job['id'] }}')">Retrying...</span>
                                </flux:button>
                                <flux:button
                                    wire:click="purge('{{ $job['id'] }}')"
                                    wire:confirm="Permanently delete this failed job?"
                                    variant="ghost"
                                    size="sm"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400"
                                >
                                    Delete
                                </flux:button>
                            </div>
                        </div>
                    </div>
                @endif
            </flux:card>
        @empty
            <flux:card>
                <div class="py-12 text-center">
                    <div class="text-zinc-400 dark:text-zinc-500 text-sm">No failed jobs in the dead-letter stream</div>
                </div>
            </flux:card>
        @endforelse
    </div>
</div>
