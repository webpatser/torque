<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;

new class extends Component {
    /** @var array<string, string> */
    public array $metrics = [];

    #[Computed]
    public function cards(): array
    {
        $throughput = (float) ($this->metrics['throughput'] ?? 0);
        $concurrent = (int) ($this->metrics['concurrent'] ?? 0);
        $totalSlots = (int) ($this->metrics['total_slots'] ?? 0);
        $avgLatency = (float) ($this->metrics['avg_latency'] ?? 0);
        $jobsProcessed = (int) ($this->metrics['jobs_processed'] ?? 0);
        $jobsFailed = (int) ($this->metrics['jobs_failed'] ?? 0);
        $memoryMb = (float) ($this->metrics['memory_mb'] ?? 0);

        $slotUsage = $totalSlots > 0 ? $concurrent / $totalSlots : 0;

        return [
            [
                'label' => 'Throughput',
                'value' => number_format($throughput, 1),
                'suffix' => 'jobs/s',
                'color' => $throughput > 0 ? 'green' : 'zinc',
            ],
            [
                'label' => 'Concurrent',
                'value' => $concurrent . '/' . $totalSlots,
                'suffix' => 'slots',
                'color' => match (true) {
                    $slotUsage >= 0.85 => 'red',
                    $slotUsage >= 0.60 => 'amber',
                    default => 'green',
                },
            ],
            [
                'label' => 'Avg Latency',
                'value' => number_format($avgLatency, 1),
                'suffix' => 'ms',
                'color' => match (true) {
                    $avgLatency >= 5000 => 'red',
                    $avgLatency >= 1000 => 'amber',
                    default => 'green',
                },
            ],
            [
                'label' => 'Processed',
                'value' => number_format($jobsProcessed),
                'suffix' => 'total',
                'color' => 'zinc',
            ],
            [
                'label' => 'Failed',
                'value' => number_format($jobsFailed),
                'suffix' => 'total',
                'color' => match (true) {
                    $jobsFailed >= 100 => 'red',
                    $jobsFailed >= 10 => 'amber',
                    $jobsFailed > 0 => 'amber',
                    default => 'green',
                },
            ],
            [
                'label' => 'Memory',
                'value' => number_format($memoryMb, 1),
                'suffix' => 'MB',
                'color' => match (true) {
                    $memoryMb >= 1024 => 'red',
                    $memoryMb >= 512 => 'amber',
                    default => 'green',
                },
            ],
        ];
    }
};
?>

<div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
    @foreach($this->cards as $card)
        <flux:card wire:key="card-{{ $card['label'] }}" class="!p-4">
            <div class="flex flex-col gap-1">
                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                    {{ $card['label'] }}
                </flux:text>
                <div class="flex items-baseline gap-1.5">
                    <flux:heading
                        size="xl"
                        @class([
                            'text-green-600 dark:text-green-400' => $card['color'] === 'green',
                            'text-amber-600 dark:text-amber-400' => $card['color'] === 'amber',
                            'text-red-600 dark:text-red-400' => $card['color'] === 'red',
                            'text-zinc-900 dark:text-zinc-100' => $card['color'] === 'zinc',
                        ])
                    >
                        {{ $card['value'] }}
                    </flux:heading>
                    <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                        {{ $card['suffix'] }}
                    </flux:text>
                </div>
            </div>
        </flux:card>
    @endforeach
</div>
