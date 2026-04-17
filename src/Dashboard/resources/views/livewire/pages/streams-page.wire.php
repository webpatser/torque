<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Dashboard\Concerns\AuthorizesTorqueAccess;
use Webpatser\Torque\Queue\StreamQueue;

new class extends Component {
    use AuthorizesTorqueAccess;

    /** @var array<string, string> */
    public array $streamErrors = [];

    #[Computed]
    public function streams(): array
    {
        $streams = config('torque.streams', []);
        $rows = [];
        $errors = [];

        /** @var StreamQueue $queue */
        $queue = app('queue')->connection('torque');

        foreach ($streams as $name => $config) {
            $streamKey = $config['stream'] ?? ('torque:stream:' . $name);

            try {
                $size = (int) $queue->getRedisClient()->execute('XLEN', $streamKey);
                $pending = $queue->pendingSize($name);
                $delayed = $queue->delayedSize($name);

                $oldestPending = $queue->creationTimeOfOldestPendingJob($name);
                $oldestAge = $oldestPending !== null ? time() - (int) $oldestPending : null;
            } catch (\Throwable $e) {
                report($e);
                $errors[$name] = 'Stream unavailable.';
                $size = 0;
                $pending = 0;
                $delayed = 0;
                $oldestAge = null;
            }

            $rows[] = [
                'name' => $name,
                'stream' => $streamKey,
                'priority' => (int) ($config['priority'] ?? 0),
                'size' => $size,
                'pending' => $pending,
                'delayed' => $delayed,
                'oldest_age' => $oldestAge,
                'retry_after' => (int) ($config['retry_after'] ?? 60),
                'max_retries' => (int) ($config['max_retries'] ?? 3),
            ];
        }

        $this->streamErrors = $errors;

        return $rows;
    }

    private function formatAge(?int $seconds): string
    {
        if ($seconds === null) {
            return '--';
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return (int) ($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }

        return (int) ($seconds / 3600) . 'h ' . (int) (($seconds % 3600) / 60) . 'm';
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="lg">Streams</flux:heading>
        <flux:text class="text-sm mt-1">Redis Streams backing your queues</flux:text>
    </div>

    {{-- Error banner --}}
    @if(! empty($streamErrors))
        <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/50 p-3 space-y-1">
            @foreach($streamErrors as $name => $message)
                <p class="text-sm text-red-700 dark:text-red-400">
                    <span class="font-medium">{{ $name }}:</span> {{ $message }}
                </p>
            @endforeach
        </div>
    @endif

    {{-- Visual stream cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        @foreach($this->streams as $stream)
            @php
                $maxBar = max($stream['size'], 1);
                $pendingPct = ($stream['pending'] / $maxBar) * 100;
                $delayedPct = ($stream['delayed'] / $maxBar) * 100;
                $activePct = max(0, 100 - $pendingPct - $delayedPct);
                $age = $stream['oldest_age'];
                $retryAfter = $stream['retry_after'];
            @endphp
            <flux:card class="!p-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $stream['name'] }}</span>
                    <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">P{{ $stream['priority'] }}</span>
                </div>

                {{-- Depth bar --}}
                <div class="flex h-3 rounded-full overflow-hidden bg-zinc-200 dark:bg-zinc-700 mb-2">
                    @if($activePct > 0)
                        <div class="bg-green-500 transition-all" style="width: {{ $activePct }}%"></div>
                    @endif
                    @if($pendingPct > 0)
                        <div class="bg-amber-500 transition-all" style="width: {{ $pendingPct }}%"></div>
                    @endif
                    @if($delayedPct > 0)
                        <div class="bg-blue-500 transition-all" style="width: {{ $delayedPct }}%"></div>
                    @endif
                </div>

                <div class="grid grid-cols-3 gap-2 text-center">
                    <div>
                        <span class="block text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($stream['size']) }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Total</span>
                    </div>
                    <div>
                        <span @class([
                            'block text-lg font-semibold tabular-nums',
                            'text-amber-600 dark:text-amber-400' => $stream['pending'] > 0,
                            'text-zinc-400' => $stream['pending'] === 0,
                        ])>{{ number_format($stream['pending']) }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Pending</span>
                    </div>
                    <div>
                        <span @class([
                            'block text-lg font-semibold tabular-nums',
                            'text-blue-600 dark:text-blue-400' => $stream['delayed'] > 0,
                            'text-zinc-400' => $stream['delayed'] === 0,
                        ])>{{ number_format($stream['delayed']) }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Delayed</span>
                    </div>
                </div>

                @if($age !== null)
                    <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Oldest pending</span>
                        <span @class([
                            'text-xs tabular-nums font-medium',
                            'text-zinc-600 dark:text-zinc-400' => $age < $retryAfter,
                            'text-amber-600 dark:text-amber-400' => $age >= $retryAfter && $age < $retryAfter * 2,
                            'text-red-600 dark:text-red-400' => $age >= $retryAfter * 2,
                        ])>
                            {{ $this->formatAge($age) }}
                        </span>
                    </div>
                @endif
            </flux:card>
        @endforeach
    </div>

    {{-- Detail table --}}
    <flux:table>
            <flux:table.columns>
                <flux:table.column>Queue</flux:table.column>
                <flux:table.column>Stream Size</flux:table.column>
                <flux:table.column>Pending</flux:table.column>
                <flux:table.column>Delayed</flux:table.column>
                <flux:table.column>Oldest Pending</flux:table.column>
                <flux:table.column>Retry After</flux:table.column>
                <flux:table.column>Max Retries</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($this->streams as $stream)
                    <flux:table.row wire:key="stream-{{ $stream['name'] }}">
                        <flux:table.cell>
                            <div>
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $stream['name'] }}</span>
                                <span class="block text-xs font-mono text-zinc-400 dark:text-zinc-500">{{ $stream['stream'] }}</span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="tabular-nums">{{ number_format($stream['size']) }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($stream['pending'] > 0)
                                <span @class([
                                    'tabular-nums font-medium',
                                    'text-amber-600 dark:text-amber-400' => $stream['pending'] < 100,
                                    'text-red-600 dark:text-red-400' => $stream['pending'] >= 100,
                                ])>
                                    {{ number_format($stream['pending']) }}
                                </span>
                            @else
                                <span class="tabular-nums text-zinc-400">0</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($stream['delayed'] > 0)
                                <span class="tabular-nums text-blue-600 dark:text-blue-400">{{ number_format($stream['delayed']) }}</span>
                            @else
                                <span class="tabular-nums text-zinc-400">0</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @php $age = $stream['oldest_age']; @endphp
                            @if($age !== null)
                                <span @class([
                                    'tabular-nums',
                                    'text-zinc-600 dark:text-zinc-400' => $age < $stream['retry_after'],
                                    'text-amber-600 dark:text-amber-400' => $age >= $stream['retry_after'] && $age < $stream['retry_after'] * 2,
                                    'text-red-600 dark:text-red-400' => $age >= $stream['retry_after'] * 2,
                                ])>
                                    {{ $this->formatAge($age) }}
                                </span>
                            @else
                                <span class="text-zinc-400">--</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ $stream['retry_after'] }}s</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ $stream['max_retries'] }}</span>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                No streams configured
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
        </flux:table.rows>
    </flux:table>
</div>
