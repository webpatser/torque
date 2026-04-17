<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Dashboard\Concerns\AuthorizesTorqueAccess;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Support\PayloadSanitizer;

new class extends Component {
    use AuthorizesTorqueAccess;

    public int $perPage = 50;

    public ?string $cursor = null;

    public ?string $expandedId = null;

    public ?string $error = null;

    public ?string $successMessage = null;

    public bool $confirmingRetryAll = false;

    public bool $confirmingPurgeAll = false;

    #[Computed]
    public function failedJobs(): array
    {
        try {
            $handler = $this->handler();

            if ($this->cursor !== null) {
                return $handler->listBefore($this->cursor, $this->perPage);
            }

            $this->error = null;

            return $handler->list($this->perPage);
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Failed to load dead-letter entries.';

            return [];
        }
    }

    #[Computed]
    public function totalCount(): int
    {
        try {
            return $this->handler()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    #[Computed]
    public function hasNextPage(): bool
    {
        return count($this->failedJobs) === $this->perPage;
    }

    public function nextPage(): void
    {
        $jobs = $this->failedJobs;

        if (!empty($jobs)) {
            $this->cursor = end($jobs)['id'];
        }

        unset($this->failedJobs);
    }

    public function previousPage(): void
    {
        $this->cursor = null;
        unset($this->failedJobs);
    }

    public function toggle(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function retry(string $id): void
    {
        try {
            $this->handler()->retry($id);
            $this->successMessage = 'Job retried successfully.';
        } catch (\RuntimeException $e) {
            report($e);
            $this->error = 'Retry failed.';
        }

        $this->expandedId = null;
        unset($this->failedJobs);
    }

    public function purge(string $id): void
    {
        $this->handler()->purge($id);
        $this->successMessage = 'Job deleted.';
        $this->expandedId = null;
        unset($this->failedJobs);
    }

    public function retryAll(): void
    {
        $handler = $this->handler();
        $retried = 0;
        $failed = 0;

        // Drain the dead-letter stream in batches of 100 until empty.
        while (($batch = $handler->list(100)) !== []) {
            foreach ($batch as $job) {
                try {
                    $handler->retry($job['id']);
                    $retried++;
                } catch (\RuntimeException) {
                    $failed++;
                    // Skip this entry on next iteration by purging: we can't
                    // retry it, and leaving it would cause an infinite loop.
                    $handler->purge($job['id']);
                }
            }
        }

        $this->successMessage = "Retried {$retried} jobs." . ($failed > 0 ? " {$failed} failed." : '');
        $this->confirmingRetryAll = false;
        $this->expandedId = null;
        $this->cursor = null;
        unset($this->failedJobs);
    }

    public function purgeAll(): void
    {
        $handler = $this->handler();
        $count = 0;

        while (($batch = $handler->list(100)) !== []) {
            foreach ($batch as $job) {
                $handler->purge($job['id']);
                $count++;
            }
        }

        $this->successMessage = "Deleted {$count} jobs.";
        $this->confirmingPurgeAll = false;
        $this->expandedId = null;
        $this->cursor = null;
        unset($this->failedJobs);
    }

    public function dismissMessage(): void
    {
        $this->successMessage = null;
        $this->error = null;
    }

    private function handler(): DeadLetterHandler
    {
        return app(DeadLetterHandler::class);
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="lg">Failed Jobs</flux:heading>
            <flux:text class="text-sm mt-1">
                {{ number_format($this->totalCount) }} {{ str('job')->plural($this->totalCount) }} in dead-letter stream
            </flux:text>
        </div>
        @if(count($this->failedJobs) > 0)
            <div class="flex items-center gap-2">
                <flux:button
                    x-on:click="$flux.modal('confirm-purge-all').show()"
                    variant="ghost"
                    size="sm"
                >
                    Delete All
                </flux:button>
                <flux:button
                    x-on:click="$flux.modal('confirm-retry-all').show()"
                    variant="primary"
                    size="sm"
                >
                    Retry All
                </flux:button>
            </div>
        @endif
    </div>

    {{-- Success/error messages --}}
    @if($successMessage)
        <div class="mb-4 rounded-lg bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-900/50 p-3 flex items-center justify-between">
            <p class="text-sm text-green-700 dark:text-green-400">{{ $successMessage }}</p>
            <button wire:click="dismissMessage" class="text-green-500 hover:text-green-700">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
    @endif

    @if($error)
        <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/50 p-3 flex items-center justify-between">
            <p class="text-sm text-red-700 dark:text-red-400">{{ $error }}</p>
            <button wire:click="dismissMessage" class="text-red-500 hover:text-red-700">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
    @endif

    {{-- Confirm retry all modal --}}
    <flux:modal name="confirm-retry-all" class="max-w-sm" :variant="'simple'" x-on:open="$wire.set('confirmingRetryAll', true)" x-on:close="$wire.set('confirmingRetryAll', false)">
        <div class="space-y-4">
            <flux:heading size="lg">Retry All Jobs</flux:heading>
            <p>Are you sure you want to retry all {{ number_format($this->totalCount) }} failed jobs in the dead-letter stream?</p>
            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$flux.modal('confirm-retry-all').close()" variant="ghost" size="sm">Cancel</flux:button>
                <flux:button wire:click="retryAll" variant="primary" size="sm">
                    <span wire:loading.remove wire:target="retryAll">Retry All</span>
                    <span wire:loading wire:target="retryAll">Retrying...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Confirm purge all modal --}}
    <flux:modal name="confirm-purge-all" class="max-w-sm" :variant="'simple'" x-on:open="$wire.set('confirmingPurgeAll', true)" x-on:close="$wire.set('confirmingPurgeAll', false)">
        <div class="space-y-4">
            <flux:heading size="lg">Delete All Jobs</flux:heading>
            <p>Permanently delete all {{ number_format($this->totalCount) }} failed jobs in the dead-letter stream? This cannot be undone.</p>
            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$flux.modal('confirm-purge-all').close()" variant="ghost" size="sm">Cancel</flux:button>
                <flux:button wire:click="purgeAll" variant="danger" size="sm">
                    <span wire:loading.remove wire:target="purgeAll">Delete All</span>
                    <span wire:loading wire:target="purgeAll">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Job list --}}
    <div class="space-y-2">
        @forelse($this->failedJobs as $job)
            @php
                $decoded = json_validate($job['payload']) ? json_decode($job['payload'], true) : [];
                $decoded = is_array($decoded) ? PayloadSanitizer::sanitizePayload($decoded) : [];
                $jobName = $decoded['displayName'] ?? $decoded['job'] ?? 'Unknown';
                $sanitizedMessage = PayloadSanitizer::sanitizeMessage((string) ($job['exception_message'] ?? ''));
                $sanitizedTrace = PayloadSanitizer::sanitizeMessage((string) ($job['exception_trace'] ?? ''), null);
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
                            <span class="truncate">{{ \Illuminate\Support\Str::limit($sanitizedMessage, 80) }}</span>
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
                                    <p class="mt-1 text-sm text-red-700 dark:text-red-400 break-words">{{ $sanitizedMessage }}</p>
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
                                        <pre class="mt-2 rounded-lg bg-zinc-900 dark:bg-zinc-950 p-3 text-xs text-zinc-300 overflow-x-auto max-h-80 overflow-y-auto leading-relaxed">{{ $sanitizedTrace }}</pre>
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
                    <flux:text class="text-sm">No failed jobs in the dead-letter stream</flux:text>
                </div>
            </flux:card>
        @endforelse
    </div>

    {{-- Pagination --}}
    @if($this->hasNextPage || $cursor !== null)
        <div class="mt-4 flex items-center justify-between">
            @if($cursor !== null)
                <flux:button wire:click="previousPage" variant="ghost" size="sm">Previous</flux:button>
            @else
                <div></div>
            @endif

            @if($this->hasNextPage)
                <flux:button wire:click="nextPage" variant="ghost" size="sm">Next</flux:button>
            @endif
        </div>
    @endif
</div>
