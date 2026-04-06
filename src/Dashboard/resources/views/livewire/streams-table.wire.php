<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Queue\StreamQueue;

new class extends Component {
    #[Computed]
    public function streams(): array
    {
        $streams = config('torque.streams', []);
        $rows = [];

        foreach ($streams as $name => $config) {
            $streamKey = $config['stream'] ?? ('torque:stream:' . $name);

            try {
                /** @var StreamQueue $queue */
                $queue = app('queue')->connection('torque');

                $size = (int) $queue->getRedisClient()->execute('XLEN', $streamKey);
                $pending = $queue->pendingSize($name);
                $delayed = $queue->delayedSize($name);

                $oldestPending = $queue->creationTimeOfOldestPendingJob($name);
                $oldestAge = $oldestPending !== null ? time() - (int) $oldestPending : null;
            } catch (\Throwable) {
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
    <flux:card class="!p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Queue</flux:table.column>
                <flux:table.column>Priority</flux:table.column>
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
                            <span class="tabular-nums">{{ $stream['priority'] }}</span>
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
                                <span class="tabular-nums text-blue-600 dark:text-blue-400">
                                    {{ number_format($stream['delayed']) }}
                                </span>
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
                        <flux:table.cell colspan="8">
                            <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                No streams configured
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
