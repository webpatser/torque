<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Dashboard\Concerns\AuthorizesTorqueAccess;
use Webpatser\Torque\Queue\StreamQueue;

new class extends Component {
    use AuthorizesTorqueAccess;

    /** @var array<string, mixed> */
    public array $metrics = [];

    /** @var array<string, array<string, string>> */
    public array $workers = [];

    public ?string $activeJobsError = null;

    #[Computed]
    public function activeJobs(): array
    {
        try {
            $jobs = app(\Webpatser\Torque\Stream\JobStream::class)->activeJobs();
            $this->activeJobsError = null;

            return $jobs;
        } catch (\Throwable $e) {
            report($e);
            // Mutating a public property from within a computed is technically
            // an anti-pattern, but only a terminal error path — Livewire won't
            // loop because the error is idempotent for a given Redis failure.
            $this->activeJobsError = 'Failed to fetch active jobs.';

            return [];
        }
    }

    #[Computed]
    public function streamPressure(): array
    {
        $streams = config('torque.streams', []);
        $rows = [];

        /** @var StreamQueue $queue */
        $queue = app('queue')->connection('torque');

        foreach ($streams as $name => $config) {
            try {
                $streamKey = $config['stream'] ?? ('torque:stream:' . $name);
                $size = (int) $queue->getRedisClient()->execute('XLEN', $streamKey);
                $pending = $queue->pendingSize($name);
                $delayed = $queue->delayedSize($name);

                $rows[] = [
                    'name' => $name,
                    'size' => $size,
                    'pending' => $pending,
                    'delayed' => $delayed,
                    'processing' => max(0, $size - $pending - $delayed),
                ];
            } catch (\Throwable) {
                $rows[] = [
                    'name' => $name,
                    'size' => 0,
                    'pending' => 0,
                    'delayed' => 0,
                    'processing' => 0,
                ];
            }
        }

        return $rows;
    }

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
        $workerCount = (int) ($this->metrics['workers'] ?? 0);

        $slotUsage = $totalSlots > 0 ? $concurrent / $totalSlots : 0;
        $errorRate = ($jobsProcessed + $jobsFailed) > 0
            ? ($jobsFailed / ($jobsProcessed + $jobsFailed)) * 100
            : 0;

        return [
            [
                'label' => 'Throughput',
                'value' => number_format($throughput, 1),
                'suffix' => 'jobs/s',
                'color' => $throughput > 0 ? 'green' : 'zinc',
                'key' => 'throughput',
                'raw' => $throughput,
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
                'key' => 'concurrent',
                'raw' => $slotUsage * 100,
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
                'key' => 'latency',
                'raw' => $avgLatency,
            ],
            [
                'label' => 'Workers',
                'value' => (string) $workerCount,
                'suffix' => 'active',
                'color' => $workerCount > 0 ? 'green' : 'zinc',
                'key' => 'workers',
                'raw' => $workerCount,
            ],
            [
                'label' => 'Processed',
                'value' => number_format($jobsProcessed),
                'suffix' => 'total',
                'color' => 'zinc',
                'key' => 'processed',
                'raw' => $jobsProcessed,
            ],
            [
                'label' => 'Failed',
                'value' => number_format($jobsFailed),
                'suffix' => 'total',
                'color' => match (true) {
                    $jobsFailed >= 100 => 'red',
                    $jobsFailed > 0 => 'amber',
                    default => 'green',
                },
                'key' => 'failed',
                'raw' => $jobsFailed,
            ],
            [
                'label' => 'Error Rate',
                'value' => number_format($errorRate, 1),
                'suffix' => '%',
                'color' => match (true) {
                    $errorRate >= 10 => 'red',
                    $errorRate >= 1 => 'amber',
                    default => 'green',
                },
                'key' => 'error_rate',
                'raw' => $errorRate,
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
                'key' => 'memory',
                'raw' => $memoryMb,
            ],
        ];
    }
};
?>

<div class="space-y-6">
    {{-- Error banner --}}
    @if($activeJobsError)
        <div class="rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/50 p-3">
            <p class="text-sm text-red-700 dark:text-red-400">{{ $activeJobsError }}</p>
        </div>
    @endif

    {{-- Metric cards with sparklines --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-4"
         x-data="{
            history: {},
            maxPoints: 60,
            init() {
                // Initialize history arrays for each card
                @foreach($this->cards as $card)
                    this.history['{{ $card['key'] }}'] = [];
                @endforeach
            },
            pushValue(key, val) {
                if (!this.history[key]) this.history[key] = [];
                this.history[key].push(val);
                if (this.history[key].length > this.maxPoints) {
                    this.history[key].shift();
                }
            },
            sparklinePath(key) {
                const data = this.history[key] || [];
                if (data.length < 2) return '';
                const max = Math.max(...data, 1);
                const min = Math.min(...data, 0);
                const range = max - min || 1;
                const w = 120;
                const h = 24;
                const step = w / (data.length - 1);
                return data.map((v, i) => {
                    const x = i * step;
                    const y = h - ((v - min) / range) * h;
                    return (i === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1);
                }).join(' ');
            }
         }"
         x-effect="
            @foreach($this->cards as $card)
                pushValue('{{ $card['key'] }}', {{ $card['raw'] }});
            @endforeach
         "
    >
        @foreach($this->cards as $card)
            <flux:card wire:key="card-{{ $card['key'] }}" class="!p-4">
                <div class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        {{ $card['label'] }}
                    </span>
                    <div class="flex items-baseline gap-1.5">
                        <span @class([
                                'text-xl font-bold',
                                'text-green-600 dark:text-green-400' => $card['color'] === 'green',
                                'text-amber-600 dark:text-amber-400' => $card['color'] === 'amber',
                                'text-red-600 dark:text-red-400' => $card['color'] === 'red',
                                'text-zinc-900 dark:text-zinc-100' => $card['color'] === 'zinc',
                            ])>
                            {{ $card['value'] }}
                        </span>
                        <span class="text-xs text-zinc-400 dark:text-zinc-500">
                            {{ $card['suffix'] }}
                        </span>
                    </div>
                    {{-- Sparkline --}}
                    <div class="h-6 mt-1">
                        <svg width="120" height="24" class="w-full" preserveAspectRatio="none">
                            <path
                                :d="sparklinePath('{{ $card['key'] }}')"
                                fill="none"
                                @class([
                                    'stroke-green-500' => $card['color'] === 'green',
                                    'stroke-amber-500' => $card['color'] === 'amber',
                                    'stroke-red-500' => $card['color'] === 'red',
                                    'stroke-zinc-400' => $card['color'] === 'zinc',
                                ])
                                stroke-width="1.5"
                                vector-effect="non-scaling-stroke"
                            />
                        </svg>
                    </div>
                </div>
            </flux:card>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Active Jobs feed (2/3 width) --}}
        <div class="lg:col-span-2">
            <flux:card class="!p-0 overflow-hidden">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <flux:heading size="sm">Active Jobs</flux:heading>
                        @if(count($this->activeJobs) > 0)
                            <flux:badge color="green" size="sm">{{ count($this->activeJobs) }} running</flux:badge>
                        @endif
                    </div>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @forelse($this->activeJobs as $job)
                        @php
                            $displayName = $job['data']['displayName'] ?? $job['data']['display_name'] ?? $job['uuid'];
                            $shortName = class_basename($displayName);
                            $queue = $job['data']['queue'] ?? 'default';
                            $progress = isset($job['data']['progress']) ? (float) $job['data']['progress'] : null;
                            $message = $job['data']['message'] ?? null;
                        @endphp
                        <button
                            wire:click="$dispatch('navigate-to-job', { uuid: '{{ $job['uuid'] }}' })"
                            class="w-full px-4 py-3 flex items-center gap-3 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                        >
                            <div class="shrink-0">
                                @if($job['type'] === 'progress')
                                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-blue-500 animate-pulse"></span>
                                @else
                                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse"></span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $shortName }}</span>
                                    <flux:badge color="zinc" size="sm">{{ $queue }}</flux:badge>
                                </div>
                                @if($message)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">{{ $message }}</p>
                                @endif
                                @if($progress !== null)
                                    <div class="mt-1.5 flex items-center gap-2">
                                        <div class="flex-1 h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                            <div class="h-full rounded-full bg-blue-500 transition-all" style="width: {{ min($progress * 100, 100) }}%"></div>
                                        </div>
                                        <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($progress * 100, 0) }}%</span>
                                    </div>
                                @endif
                            </div>
                            <svg class="size-4 text-zinc-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </button>
                    @empty
                        <div class="px-4 py-8 text-center">
                            <p class="text-sm text-zinc-400 dark:text-zinc-500">No jobs currently running</p>
                        </div>
                    @endforelse
                </div>
            </flux:card>
        </div>

        {{-- Queue pressure (1/3 width) --}}
        <div>
            <flux:card class="!p-0 overflow-hidden">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:heading size="sm">Queue Pressure</flux:heading>
                </div>

                <div class="px-4 py-3 space-y-3">
                    @forelse($this->streamPressure as $stream)
                        @php
                            $total = max($stream['size'], 1);
                            $pendingPct = ($stream['pending'] / $total) * 100;
                            $delayedPct = ($stream['delayed'] / $total) * 100;
                            $processingPct = ($stream['processing'] / $total) * 100;
                        @endphp
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $stream['name'] }}</span>
                                <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($stream['size']) }}</span>
                            </div>
                            <div class="flex h-2 rounded-full overflow-hidden bg-zinc-200 dark:bg-zinc-700">
                                @if($stream['pending'] > 0)
                                    <div class="bg-amber-500 transition-all" style="width: {{ $pendingPct }}%" title="Pending: {{ $stream['pending'] }}"></div>
                                @endif
                                @if($stream['processing'] > 0)
                                    <div class="bg-green-500 transition-all" style="width: {{ $processingPct }}%" title="Processing: {{ $stream['processing'] }}"></div>
                                @endif
                                @if($stream['delayed'] > 0)
                                    <div class="bg-blue-500 transition-all" style="width: {{ $delayedPct }}%" title="Delayed: {{ $stream['delayed'] }}"></div>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                @if($stream['pending'] > 0)
                                    <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span> {{ $stream['pending'] }} pending</span>
                                @endif
                                @if($stream['delayed'] > 0)
                                    <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-blue-500"></span> {{ $stream['delayed'] }} delayed</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-4 text-center">
                            <p class="text-sm text-zinc-400 dark:text-zinc-500">No streams configured</p>
                        </div>
                    @endforelse
                </div>
            </flux:card>
        </div>
    </div>

    {{-- Workers table --}}
    <livewire:torque.workers-table :workers="$workers" />
</div>
