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
    public function rows(): array
    {
        $rows = [];

        foreach ($this->workers as $workerId => $worker) {
            $active = (int) ($worker['active_slots'] ?? 0);
            $total = (int) ($worker['total_slots'] ?? 0);
            $usage = $total > 0 ? ($active / $total) * 100 : 0;
            $heartbeat = (int) ($worker['last_heartbeat'] ?? 0);
            $ago = $heartbeat > 0 ? time() - $heartbeat : null;

            $rows[] = [
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

        return $rows;
    }
};
?>

<div>
    <flux:table>
            <flux:table.columns>
                <flux:table.column>Worker</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Slots</flux:table.column>
                <flux:table.column>Processed</flux:table.column>
                <flux:table.column>Failed</flux:table.column>
                <flux:table.column>Avg Latency</flux:table.column>
                <flux:table.column>Memory</flux:table.column>
                <flux:table.column>Heartbeat</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($this->rows as $row)
                    <flux:table.row wire:key="worker-{{ $row['id'] }}">
                        <flux:table.cell>
                            <span class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ $row['id'] }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($row['alive'])
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="red" size="sm">Stale</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <div class="w-20 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                    <div
                                        @class([
                                            'h-full rounded-full transition-all',
                                            'bg-green-500' => $row['usage'] < 60,
                                            'bg-amber-500' => $row['usage'] >= 60 && $row['usage'] < 85,
                                            'bg-red-500' => $row['usage'] >= 85,
                                        ])
                                        style="width: {{ min($row['usage'], 100) }}%"
                                    ></div>
                                </div>
                                <span class="text-sm text-zinc-600 dark:text-zinc-400 tabular-nums">
                                    {{ $row['active'] }}/{{ $row['total'] }}
                                </span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="tabular-nums">{{ number_format($row['processed']) }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($row['failed'] > 0)
                                <span class="tabular-nums text-red-600 dark:text-red-400">{{ number_format($row['failed']) }}</span>
                            @else
                                <span class="tabular-nums text-zinc-400">0</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="tabular-nums">{{ $row['latency'] }} ms</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="tabular-nums">{{ $row['memory_mb'] }} MB</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($row['ago'] !== null)
                                <span @class([
                                    'tabular-nums text-sm',
                                    'text-zinc-500 dark:text-zinc-400' => $row['ago'] < 10,
                                    'text-amber-600 dark:text-amber-400' => $row['ago'] >= 10 && $row['ago'] < 30,
                                    'text-red-600 dark:text-red-400' => $row['ago'] >= 30,
                                ])>
                                    {{ $row['ago'] }}s ago
                                </span>
                            @else
                                <span class="text-zinc-400">--</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8">
                            <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                No active workers
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
        </flux:table.rows>
    </flux:table>
</div>
