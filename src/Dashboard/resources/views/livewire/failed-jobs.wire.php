<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Job\DeadLetterHandler;

new class extends Component {
    public int $limit = 50;

    #[Computed]
    public function failedJobs(): array
    {
        return $this->handler()->list($this->limit);
    }

    public function retry(string $id): void
    {
        Gate::authorize('viewTorque');

        try {
            $this->handler()->retry($id);
            unset($this->failedJobs);
        } catch (\RuntimeException) {
            // Entry already retried or purged.
        }
    }

    public function purge(string $id): void
    {
        Gate::authorize('viewTorque');

        $this->handler()->purge($id);
        unset($this->failedJobs);
    }

    public function retryAll(): void
    {
        Gate::authorize('viewTorque');

        $handler = $this->handler();

        foreach ($this->failedJobs as $job) {
            try {
                $handler->retry($job['id']);
            } catch (\RuntimeException) {
                continue;
            }
        }

        unset($this->failedJobs);
    }

    private function handler(): DeadLetterHandler
    {
        return app(DeadLetterHandler::class);
    }
};
?>

<div>
    @if(count($this->failedJobs) > 0)
        <div class="mb-4 flex items-center justify-between">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                Showing {{ count($this->failedJobs) }} failed {{ str('job')->plural(count($this->failedJobs)) }}
            </flux:text>
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
    @endif

    <flux:card class="!p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>ID</flux:table.column>
                <flux:table.column>Queue</flux:table.column>
                <flux:table.column>Exception</flux:table.column>
                <flux:table.column>Failed At</flux:table.column>
                <flux:table.column class="text-right">Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($this->failedJobs as $job)
                    <flux:table.row wire:key="failed-{{ $job['id'] }}">
                        <flux:table.cell>
                            <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                {{ $job['id'] }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge color="zinc" size="sm">{{ $job['original_queue'] }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="max-w-md">
                                <span class="block text-sm font-medium text-red-600 dark:text-red-400">
                                    {{ class_basename($job['exception_class']) }}
                                </span>
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400 truncate">
                                    {{ \Illuminate\Support\Str::limit($job['exception_message'], 100) }}
                                </span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-400">
                                {{ \Carbon\Carbon::parse($job['failed_at'])->diffForHumans() }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                <flux:button
                                    wire:click="retry('{{ $job['id'] }}')"
                                    variant="ghost"
                                    size="xs"
                                >
                                    <span wire:loading.remove wire:target="retry('{{ $job['id'] }}')">Retry</span>
                                    <span wire:loading wire:target="retry('{{ $job['id'] }}')">...</span>
                                </flux:button>
                                <flux:button
                                    wire:click="purge('{{ $job['id'] }}')"
                                    wire:confirm="Permanently delete this failed job?"
                                    variant="ghost"
                                    size="xs"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400"
                                >
                                    Delete
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="py-12 text-center">
                                <div class="text-zinc-400 dark:text-zinc-500 text-sm">No failed jobs</div>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
