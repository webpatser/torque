<?php

declare(strict_types=1);

use Livewire\Component;

new class extends Component {
    public int $interval = 2000;

    private const array OPTIONS = [
        0 => 'Paused',
        1000 => '1s',
        2000 => '2s',
        5000 => '5s',
        10000 => '10s',
        30000 => '30s',
        60000 => '1m',
    ];

    public function setInterval(int $interval): void
    {
        $this->interval = $interval;
        $this->dispatch('poll-interval-changed', interval: $interval);
    }

    public function with(): array
    {
        return [
            'options' => self::OPTIONS,
        ];
    }
};
?>

<div
    x-data="{
        open: false,
        interval: @entangle('interval'),
        options: {{ Js::from($options) }},
        init() {
            const saved = localStorage.getItem('torque:poll-interval');
            if (saved !== null) {
                const ms = parseInt(saved, 10);
                if (Object.keys(this.options).map(Number).includes(ms)) {
                    this.interval = ms;
                    $wire.setInterval(ms);
                }
            }

            $watch('interval', (val) => {
                localStorage.setItem('torque:poll-interval', String(val));
            });
        },
        get label() {
            return this.options[this.interval] ?? '2s';
        }
    }"
    class="relative"
>
    <flux:button
        variant="ghost"
        size="sm"
        @click="open = !open"
        class="tabular-nums"
    >
        <span
            x-show="interval > 0"
            class="mr-1.5 inline-block h-2 w-2 rounded-full bg-green-500 animate-pulse"
        ></span>
        <span
            x-show="interval === 0"
            class="mr-1.5 inline-block h-2 w-2 rounded-full bg-zinc-400"
        ></span>
        <span x-text="label"></span>
        <svg class="ml-1 h-4 w-4 text-zinc-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
        </svg>
    </flux:button>

    <div
        x-show="open"
        x-transition.opacity.duration.150ms
        @click.outside="open = false"
        class="absolute right-0 z-50 mt-1 w-36 rounded-lg border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
    >
        <div class="px-3 py-1.5 text-xs font-medium text-zinc-400 dark:text-zinc-500 uppercase tracking-wide">
            Refresh
        </div>
        <template x-for="[ms, label] in Object.entries(options)" :key="ms">
            <button
                @click="interval = Number(ms); $wire.setInterval(Number(ms)); open = false"
                class="flex w-full items-center justify-between px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/50 transition-colors"
                :class="{ 'text-indigo-600 dark:text-indigo-400 font-medium': interval === Number(ms) }"
            >
                <span x-text="label"></span>
                <svg
                    x-show="interval === Number(ms)"
                    class="h-4 w-4 text-indigo-500"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                >
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                </svg>
            </button>
        </template>
    </div>
</div>
