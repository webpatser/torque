<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Dashboard\Concerns\AuthorizesTorqueAccess;

new class extends Component {
    use AuthorizesTorqueAccess;

    /** @var array<string, array<string, string>> */
    public array $workers = [];

    #[Computed]
    public function workerCards(): array
    {
        $cards = [];

        foreach ($this->workers as $workerId => $worker) {
            $active = (int) ($worker['active_slots'] ?? 0);
            $total = (int) ($worker['total_slots'] ?? 0);
            $usage = $total > 0 ? ($active / $total) * 100 : 0;
            $heartbeat = (int) ($worker['last_heartbeat'] ?? 0);
            $ago = $heartbeat > 0 ? time() - $heartbeat : null;

            $cards[] = [
                'id' => $workerId,
                'active' => $active,
                'total' => $total,
                'usage' => round($usage, 1),
                'processed' => (int) ($worker['jobs_processed'] ?? 0),
                'failed' => (int) ($worker['jobs_failed'] ?? 0),
                'latency' => round((float) ($worker['avg_latency_ms'] ?? 0), 1),
                'memory_mb' => round(((int) ($worker['memory_bytes'] ?? 0)) / 1_048_576, 1),
                'ago' => $ago,
                'alive' => $ago !== null && $ago < 30,
            ];
        }

        return $cards;
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="lg">Workers</flux:heading>
        <flux:text class="text-sm mt-1">
            {{ count($this->workerCards) }} {{ str('worker')->plural(count($this->workerCards)) }} registered
        </flux:text>
    </div>

    @if(empty($this->workerCards))
        <flux:card>
            <div class="py-12 text-center">
                <flux:text class="text-sm">No active workers</flux:text>
            </div>
        </flux:card>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($this->workerCards as $worker)
                <flux:card wire:key="worker-{{ $worker['id'] }}" class="!p-4">
                    {{-- Header --}}
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2 min-w-0">
                            @if($worker['alive'])
                                <span class="inline-block h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse shrink-0"></span>
                            @else
                                <span class="inline-block h-2.5 w-2.5 rounded-full bg-red-500 shrink-0"></span>
                            @endif
                            <span class="font-mono text-xs text-zinc-700 dark:text-zinc-300 truncate">{{ $worker['id'] }}</span>
                        </div>
                        @if($worker['alive'])
                            <flux:badge color="green" size="sm">Active</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Stale</flux:badge>
                        @endif
                    </div>

                    {{-- Slot gauge --}}
                    <div class="flex items-center justify-center mb-4">
                        <div class="relative w-24 h-24">
                            <svg viewBox="0 0 36 36" class="w-24 h-24 -rotate-90">
                                {{-- Background circle --}}
                                <path
                                    class="text-zinc-200 dark:text-zinc-700"
                                    d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="3"
                                />
                                {{-- Usage arc --}}
                                <path
                                    @class([
                                        'text-green-500' => $worker['usage'] < 60,
                                        'text-amber-500' => $worker['usage'] >= 60 && $worker['usage'] < 85,
                                        'text-red-500' => $worker['usage'] >= 85,
                                    ])
                                    d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-dasharray="{{ $worker['usage'] }}, 100"
                                    stroke-linecap="round"
                                />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-lg font-bold tabular-nums text-zinc-900 dark:text-zinc-100">{{ $worker['active'] }}/{{ $worker['total'] }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">slots</span>
                            </div>
                        </div>
                    </div>

                    {{-- Stats grid --}}
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400 text-xs">Processed</span>
                            <p class="tabular-nums font-medium text-zinc-900 dark:text-zinc-100">{{ number_format($worker['processed']) }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400 text-xs">Failed</span>
                            <p @class([
                                'tabular-nums font-medium',
                                'text-red-600 dark:text-red-400' => $worker['failed'] > 0,
                                'text-zinc-400' => $worker['failed'] === 0,
                            ])>{{ number_format($worker['failed']) }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400 text-xs">Avg Latency</span>
                            <p class="tabular-nums font-medium text-zinc-900 dark:text-zinc-100">{{ $worker['latency'] }} ms</p>
                        </div>
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400 text-xs">Memory</span>
                            <p class="tabular-nums font-medium text-zinc-900 dark:text-zinc-100">{{ $worker['memory_mb'] }} MB</p>
                        </div>
                    </div>

                    {{-- Heartbeat --}}
                    <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                        @if($worker['ago'] !== null)
                            <span @class([
                                'text-xs tabular-nums',
                                'text-zinc-500 dark:text-zinc-400' => $worker['ago'] < 10,
                                'text-amber-600 dark:text-amber-400' => $worker['ago'] >= 10 && $worker['ago'] < 30,
                                'text-red-600 dark:text-red-400' => $worker['ago'] >= 30,
                            ])>
                                Last heartbeat {{ $worker['ago'] }}s ago
                            </span>
                        @else
                            <span class="text-xs text-zinc-400">No heartbeat</span>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
