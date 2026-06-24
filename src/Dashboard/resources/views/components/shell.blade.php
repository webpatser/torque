@props([
    'title' => '',
    'crumb' => '',
    'active' => 'overview',
    'deadCount' => 0,
    'workerCount' => null,
    'pollInterval' => 2000,
])
@php
    $nav = [
        ['id' => 'overview', 'route' => 'torque.overview', 'icon' => 'gauge', 'label' => 'Overview'],
        ['id' => 'workers', 'route' => 'torque.workers', 'icon' => 'workers', 'label' => 'Workers', 'badge' => $workerCount !== null ? (string) $workerCount : null],
        ['id' => 'queues', 'route' => 'torque.queues', 'icon' => 'queues', 'label' => 'Queues'],
        ['id' => 'feed', 'route' => 'torque.feed', 'icon' => 'feed', 'label' => 'Live feed', 'live' => true],
        ['id' => 'dead', 'route' => 'torque.dead', 'icon' => 'dead', 'label' => 'Dead-letter', 'badge' => $deadCount > 0 ? (string) $deadCount : null, 'alert' => $deadCount > 0],
    ];

    $pollOpts = [
        ['v' => 1000, 'l' => '1s'],
        ['v' => 2000, 'l' => '2s'],
        ['v' => 5000, 'l' => '5s'],
        ['v' => 10000, 'l' => '10s'],
        ['v' => 30000, 'l' => '30s'],
        ['v' => 0, 'l' => 'paused'],
    ];
    $curPoll = collect($pollOpts)->firstWhere('v', $pollInterval) ?? $pollOpts[1];
    $version = rescue(fn () => \Composer\InstalledVersions::getPrettyVersion('webpatser/torque'), 'dev', false);
@endphp
<div class="app" :class="{ 'nav-collapsed': $store.torque.nav }">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark"><x-torque::rotor :size="32"/></span>
            <span class="brand-text">
                <span class="brand-name">tor<b>que</b></span>
                <span class="brand-tag">keeps spinning</span>
            </span>
        </div>
        <nav class="nav">
            <div class="nav-label">Monitor</div>
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" wire:navigate @class(['nav-item', 'active' => $active === $item['id']])>
                    <span class="ni-icon">
                        @if ($item['live'] ?? false)
                            <span class="livedot"></span>
                        @else
                            <x-torque::icon :name="$item['icon']" :size="18"/>
                        @endif
                    </span>
                    <span class="ni-text">{{ $item['label'] }}</span>
                    @if (($item['badge'] ?? null) !== null)
                        <span @class(['ni-badge', 'alert' => $item['alert'] ?? false])>{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
            <div class="nav-label">Inspect</div>
            <a href="{{ route('torque.inspector') }}" wire:navigate @class(['nav-item', 'active' => $active === 'inspector'])>
                <span class="ni-icon"><x-torque::icon name="inspect" :size="18"/></span>
                <span class="ni-text">Job inspector</span>
            </a>
        </nav>
        <div class="sidebar-foot">
            <div class="env-chip">
                <span class="dot"></span>
                <span class="et">{{ app()->environment() }} · {{ $version }}</span>
            </div>
        </div>
    </aside>
    <div class="main">
        <header class="topbar">
            <button class="icon-btn" type="button" title="Toggle sidebar" @click="$store.torque.toggleNav()">
                <x-torque::icon name="collapse" :size="17"/>
            </button>
            <div class="tb-title">
                <h1>{{ $title }}</h1>
                @if ($crumb)<span class="crumb">{{ $crumb }}</span>@endif
            </div>
            <div class="spacer"></div>

            {{-- Poll interval selector --}}
            <div style="position: relative;" x-data="{ open: false }">
                <button type="button" class="btn sm" style="min-width: 92px;" @click="open = ! open">
                    @if ($pollInterval === 0)
                        <x-torque::icon name="pause" :size="13"/>
                    @else
                        <span class="livedot" style="width: 7px; height: 7px;"></span>
                    @endif
                    <span class="mono" style="font-size: 12px;">{{ $pollInterval === 0 ? 'paused' : 'every '.$curPoll['l'] }}</span>
                    <x-torque::icon name="chevD" :size="13"/>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false" x-transition.opacity
                    style="position: absolute; top: 40px; right: 0; z-index: 41; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-md); box-shadow: var(--shadow-lg); padding: 5px; min-width: 130px;">
                    <div class="eyebrow" style="padding: 6px 9px 4px;">Refresh</div>
                    @foreach ($pollOpts as $o)
                        <button type="button" wire:click="setPollInterval({{ $o['v'] }})" @click="open = false"
                            class="mono"
                            style="display: flex; width: 100%; align-items: center; gap: 8px; padding: 7px 9px; border: none; border-radius: 6px; cursor: pointer; font-size: 12.5px; text-align: left; {{ $o['v'] === $pollInterval ? 'color: var(--accent); background: var(--accent-soft);' : 'color: var(--text-dim); background: transparent;' }}">
                            <x-torque::icon :name="$o['v'] === 0 ? 'pause' : 'refresh'" :size="12"/>
                            {{ $o['l'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Theme toggle --}}
            <button class="icon-btn" type="button" title="Toggle theme" @click="$store.torque.toggleTheme()">
                <span x-show="$store.torque.theme === 'dark'"><x-torque::icon name="sun" :size="17"/></span>
                <span x-show="$store.torque.theme !== 'dark'" x-cloak><x-torque::icon name="moon" :size="17"/></span>
            </button>
        </header>
        <div class="content">
            <div class="content-inner page" @if ($pollInterval > 0) wire:poll.{{ $pollInterval }}ms @endif>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
