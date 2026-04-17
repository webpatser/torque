<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Dashboard\Concerns\AuthorizesTorqueAccess;
use Webpatser\Torque\Support\PayloadSanitizer;

new class extends Component {
    use AuthorizesTorqueAccess;

    public string $uuid;

    public int $pollInterval = 1000;

    public array $events = [];

    public bool $finished = false;

    public ?string $error = null;

    public function mount(string $uuid, int $pollInterval = 1000): void
    {
        $this->uuid = $uuid;
        $this->pollInterval = $pollInterval;
        $this->loadEvents();
    }

    public function poll(): void
    {
        if ($this->finished) {
            return;
        }

        $this->loadEvents();
    }

    #[Computed]
    public function jobName(): string
    {
        foreach ($this->events as $event) {
            if ($event['type'] === 'queued') {
                return $event['data']['displayName'] ?? $event['data']['display_name'] ?? $this->uuid;
            }
        }

        return $this->uuid;
    }

    #[Computed]
    public function jobQueue(): string
    {
        foreach ($this->events as $event) {
            if (isset($event['data']['queue'])) {
                return $event['data']['queue'];
            }
        }

        return 'unknown';
    }

    #[Computed]
    public function jobStatus(): string
    {
        if (empty($this->events)) {
            return 'unknown';
        }

        $lastType = $this->events[count($this->events) - 1]['type'];

        return match ($lastType) {
            'completed' => 'completed',
            'failed', 'dead_lettered' => 'failed',
            'started', 'progress' => 'running',
            'exception' => 'retrying',
            'queued' => 'queued',
            default => 'unknown',
        };
    }

    #[Computed]
    public function duration(): ?string
    {
        if (count($this->events) < 2) {
            return null;
        }

        $first = $this->events[0]['data']['timestamp'] ?? null;
        $last = $this->events[count($this->events) - 1]['data']['timestamp'] ?? null;

        if ($first === null || $last === null) {
            return null;
        }

        // Timestamps are in nanoseconds from hrtime(true).
        $diffNs = (int) $last - (int) $first;
        $diffMs = $diffNs / 1_000_000;

        if ($diffMs < 1000) {
            return number_format($diffMs, 1) . 'ms';
        }

        $diffS = $diffMs / 1000;

        if ($diffS < 60) {
            return number_format($diffS, 1) . 's';
        }

        return number_format($diffS / 60, 1) . 'm';
    }

    #[Computed]
    public function workerInfo(): ?string
    {
        foreach ($this->events as $event) {
            if ($event['type'] === 'started') {
                return $event['data']['worker'] ?? null;
            }
        }

        return null;
    }

    #[Computed]
    public function memoryUsage(): ?string
    {
        foreach (array_reverse($this->events) as $event) {
            if ($event['type'] === 'completed' && isset($event['data']['memory_bytes'])) {
                $mb = (int) $event['data']['memory_bytes'] / 1_048_576;

                return number_format($mb, 1) . ' MB';
            }
        }

        return null;
    }

    #[Computed]
    public function exceptionInfo(): ?array
    {
        foreach (array_reverse($this->events) as $event) {
            if (in_array($event['type'], ['failed', 'exception'], true)) {
                return [
                    'class' => $event['data']['exception_class'] ?? 'Unknown',
                    'message' => PayloadSanitizer::sanitizeMessage((string) ($event['data']['exception_message'] ?? '')),
                ];
            }
        }

        return null;
    }

    #[Computed]
    public function payload(): ?array
    {
        foreach ($this->events as $event) {
            if ($event['type'] === 'queued' && isset($event['data']['payload'])) {
                $decoded = json_decode($event['data']['payload'], true);

                return is_array($decoded) ? PayloadSanitizer::sanitizePayload($decoded) : null;
            }
        }

        return null;
    }

    private function loadEvents(): void
    {
        try {
            $stream = app(\Webpatser\Torque\Stream\JobStream::class);
            $this->events = $stream->events($this->uuid);
            $this->finished = $stream->isFinished($this->uuid);
            $this->error = null;
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Failed to load job events.';
        }
    }
};
?>

<div @if(!$finished && $pollInterval > 0) wire:poll.{{ $pollInterval }}ms="poll" @endif>
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-2 mb-3">
            <button
                x-on:click="$wire.$parent.navigate('jobs')"
                type="button"
                class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors"
            >
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </button>
            <flux:heading size="lg">Job Inspector</flux:heading>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <span class="text-lg font-semibold text-zinc-900 dark:text-white">{{ class_basename($this->jobName) }}</span>
            @php
                $statusBadgeColor = match($this->jobStatus) {
                    'completed' => 'green',
                    'failed' => 'red',
                    'running' => 'blue',
                    'retrying' => 'amber',
                    default => 'zinc',
                };
            @endphp
            <flux:badge :color="$statusBadgeColor" size="sm">{{ ucfirst($this->jobStatus) }}</flux:badge>
            <flux:badge color="zinc" size="sm">{{ $this->jobQueue }}</flux:badge>
            @if($this->duration)
                <span class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->duration }}</span>
            @endif
            @if(!$finished)
                <span class="flex items-center gap-1.5 text-xs text-blue-500">
                    <span class="inline-block h-2 w-2 rounded-full bg-blue-500 animate-pulse"></span>
                    Live
                </span>
            @endif
        </div>
        <p class="mt-1 font-mono text-xs text-zinc-400 dark:text-zinc-500 break-all select-all">{{ $uuid }}</p>
    </div>

    {{-- Error banner --}}
    @if($error)
        <div class="mb-6 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/50 p-3">
            <p class="text-sm text-red-700 dark:text-red-400">{{ $error }}</p>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Timeline (left 2/3) --}}
        <div class="lg:col-span-2">
            <flux:card class="!p-0 overflow-hidden">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:heading size="sm">Timeline</flux:heading>
                </div>

                @if(empty($events))
                    <div class="px-4 py-12 text-center">
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">No events recorded for this job</p>
                        <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-1">The job stream may have expired (TTL: {{ config('torque.job_streams.ttl', 300) }}s)</p>
                    </div>
                @else
                    <div class="relative px-4 py-4">
                        {{-- Timeline line --}}
                        <div class="absolute left-8 top-6 bottom-6 w-px bg-zinc-200 dark:bg-zinc-700"></div>

                        <div class="space-y-0">
                            @foreach($events as $i => $event)
                                @php
                                    $iconConfig = match($event['type']) {
                                        'queued' => ['icon' => 'inbox-arrow-down', 'color' => 'zinc', 'bg' => 'bg-zinc-100 dark:bg-zinc-800'],
                                        'started' => ['icon' => 'play', 'color' => 'cyan', 'bg' => 'bg-cyan-100 dark:bg-cyan-900/30'],
                                        'progress' => ['icon' => 'arrow-path', 'color' => 'blue', 'bg' => 'bg-blue-100 dark:bg-blue-900/30'],
                                        'completed' => ['icon' => 'check-circle', 'color' => 'green', 'bg' => 'bg-green-100 dark:bg-green-900/30'],
                                        'failed', 'dead_lettered' => ['icon' => 'x-circle', 'color' => 'red', 'bg' => 'bg-red-100 dark:bg-red-900/30'],
                                        'exception' => ['icon' => 'exclamation-triangle', 'color' => 'amber', 'bg' => 'bg-amber-100 dark:bg-amber-900/30'],
                                        default => ['icon' => 'ellipsis-horizontal', 'color' => 'zinc', 'bg' => 'bg-zinc-100 dark:bg-zinc-800'],
                                    };

                                    // Calculate relative time from first event.
                                    $firstTs = (int) ($events[0]['data']['timestamp'] ?? 0);
                                    $eventTs = (int) ($event['data']['timestamp'] ?? 0);
                                    $diffMs = ($eventTs - $firstTs) / 1_000_000;
                                @endphp

                                <div class="relative flex gap-4 pb-6 last:pb-0" wire:key="event-{{ $i }}">
                                    {{-- Icon --}}
                                    <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $iconConfig['bg'] }}">
                                        <flux:icon :name="$iconConfig['icon']" class="size-4" @class([
                                            'text-zinc-500' => $iconConfig['color'] === 'zinc',
                                            'text-cyan-500' => $iconConfig['color'] === 'cyan',
                                            'text-blue-500' => $iconConfig['color'] === 'blue',
                                            'text-green-500' => $iconConfig['color'] === 'green',
                                            'text-red-500' => $iconConfig['color'] === 'red',
                                            'text-amber-500' => $iconConfig['color'] === 'amber',
                                        ]) />
                                    </div>

                                    {{-- Content --}}
                                    <div class="flex-1 min-w-0 pt-0.5">
                                        <div class="flex items-center gap-2">
                                            <span @class([
                                                'text-sm font-medium',
                                                'text-zinc-700 dark:text-zinc-300' => !in_array($iconConfig['color'], ['red', 'green']),
                                                'text-green-700 dark:text-green-400' => $iconConfig['color'] === 'green',
                                                'text-red-700 dark:text-red-400' => $iconConfig['color'] === 'red',
                                            ])>
                                                {{ ucfirst($event['type']) }}
                                            </span>
                                            @if($i > 0)
                                                <span class="text-xs tabular-nums text-zinc-400 dark:text-zinc-500">
                                                    +{{ $diffMs < 1000 ? number_format($diffMs, 1) . 'ms' : number_format($diffMs / 1000, 2) . 's' }}
                                                </span>
                                            @endif
                                        </div>

                                        {{-- Event-specific content --}}
                                        @switch($event['type'])
                                            @case('queued')
                                                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                                    {{ $event['data']['displayName'] ?? $event['data']['display_name'] ?? '' }}
                                                    @if(isset($event['data']['queue']))
                                                        <span class="text-zinc-400 dark:text-zinc-500">on</span> {{ $event['data']['queue'] }}
                                                    @endif
                                                </p>
                                                @break

                                            @case('started')
                                                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                                    Worker: <span class="font-mono text-xs">{{ $event['data']['worker'] ?? 'unknown' }}</span>
                                                    @if(isset($event['data']['attempt']))
                                                        &middot; Attempt {{ $event['data']['attempt'] }}
                                                    @endif
                                                </p>
                                                @break

                                            @case('progress')
                                                @php $progress = isset($event['data']['progress']) ? (float) $event['data']['progress'] : null; @endphp
                                                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                                    {{ $event['data']['message'] ?? '' }}
                                                </p>
                                                @if($progress !== null)
                                                    <div class="mt-1.5 flex items-center gap-2 max-w-xs">
                                                        <div class="flex-1 h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                                            <div class="h-full rounded-full bg-blue-500 transition-all" style="width: {{ min($progress * 100, 100) }}%"></div>
                                                        </div>
                                                        <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($progress * 100, 0) }}%</span>
                                                    </div>
                                                @endif
                                                @break

                                            @case('completed')
                                                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                                    @if(isset($event['data']['memory_bytes']))
                                                        Memory: {{ number_format((int) $event['data']['memory_bytes'] / 1_048_576, 1) }} MB
                                                    @endif
                                                </p>
                                                @break

                                            @case('failed')
                                            @case('dead_lettered')
                                                <div class="mt-1 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/50 p-2.5">
                                                    <p class="text-sm font-medium text-red-800 dark:text-red-300">{{ $event['data']['exception_class'] ?? '' }}</p>
                                                    <p class="mt-0.5 text-sm text-red-700 dark:text-red-400 break-words">{{ PayloadSanitizer::sanitizeMessage((string) ($event['data']['exception_message'] ?? '')) }}</p>
                                                </div>
                                                @break

                                            @case('exception')
                                                <div class="mt-1 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/50 p-2.5">
                                                    <p class="text-sm text-amber-800 dark:text-amber-300">
                                                        Attempt {{ $event['data']['attempt'] ?? '?' }}: {{ PayloadSanitizer::sanitizeMessage((string) ($event['data']['exception_message'] ?? '')) }}
                                                    </p>
                                                </div>
                                                @break
                                        @endswitch
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </flux:card>
        </div>

        {{-- Metadata sidebar (right 1/3) --}}
        <div class="space-y-4">
            {{-- Job details --}}
            <flux:card>
                <div class="space-y-3">
                    <flux:heading size="sm">Details</flux:heading>

                    <dl class="space-y-2 text-sm">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Job</dt>
                            <dd class="font-mono text-xs text-zinc-900 dark:text-zinc-100 break-all">{{ $this->jobName }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Queue</dt>
                            <dd class="text-zinc-900 dark:text-zinc-100">{{ $this->jobQueue }}</dd>
                        </div>
                        @if($this->workerInfo)
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Worker</dt>
                                <dd class="font-mono text-xs text-zinc-900 dark:text-zinc-100 break-all">{{ $this->workerInfo }}</dd>
                            </div>
                        @endif
                        @if($this->duration)
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Duration</dt>
                                <dd class="tabular-nums text-zinc-900 dark:text-zinc-100">{{ $this->duration }}</dd>
                            </div>
                        @endif
                        @if($this->memoryUsage)
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Memory</dt>
                                <dd class="tabular-nums text-zinc-900 dark:text-zinc-100">{{ $this->memoryUsage }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Events</dt>
                            <dd class="tabular-nums text-zinc-900 dark:text-zinc-100">{{ count($events) }}</dd>
                        </div>
                    </dl>
                </div>
            </flux:card>

            {{-- Exception (if failed) --}}
            @if($this->exceptionInfo)
                <flux:card>
                    <div class="space-y-2">
                        <flux:heading size="sm" class="!text-red-600 dark:!text-red-400">Exception</flux:heading>
                        <p class="text-sm font-medium text-red-800 dark:text-red-300 break-all">{{ $this->exceptionInfo['class'] }}</p>
                        <p class="text-sm text-red-700 dark:text-red-400 break-words">{{ $this->exceptionInfo['message'] }}</p>
                    </div>
                </flux:card>
            @endif

            {{-- Payload --}}
            @if($this->payload)
                <flux:card x-data="{ showPayload: false }">
                    <div>
                        <button @click="showPayload = !showPayload" class="flex items-center gap-1.5 w-full text-left">
                            <svg class="size-3 text-zinc-400 transition-transform" :class="showPayload && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                            <flux:heading size="sm">Payload</flux:heading>
                        </button>
                        <div x-show="showPayload" x-collapse>
                            <pre class="mt-2 rounded-lg bg-zinc-900 dark:bg-zinc-950 p-3 text-xs text-zinc-300 overflow-x-auto max-h-64 overflow-y-auto leading-relaxed">{{ json_encode($this->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </div>
                </flux:card>
            @endif
        </div>
    </div>
</div>
