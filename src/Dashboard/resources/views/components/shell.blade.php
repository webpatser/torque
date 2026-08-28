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
            <a href="{{ route('torque.jobs') }}" wire:navigate @class(['nav-item', 'active' => $active === 'jobs'])>
                <span class="ni-icon"><x-torque::icon name="layers" :size="18"/></span>
                <span class="ni-text">Jobs</span>
            </a>
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

            {{-- Poll interval selector.

                 The panel is wire:ignore'd and driven entirely by Alpine. This page
                 polls, and Livewire's morph only skips attribute patching when
                 `_x_isShown` differs between the live node and the fresh server node.
                 A hidden x-show element is `false` while the server node is
                 `undefined`, so without wire:ignore every poll tick would reset the
                 style attribute and re-open the panel. For the same reason the look
                 lives in .popover / .popover-item classes: Alpine must be the only
                 thing writing to `style`, and the active state reads the reactive
                 $wire.pollInterval instead of being rendered server-side. --}}
            <div class="popover-anchor" x-data="{ open: false }">
                <button type="button" class="btn sm poll-trigger" @click="open = ! open">
                    @if ($pollInterval === 0)
                        <x-torque::icon name="pause" :size="13"/>
                    @else
                        <span class="livedot sm"></span>
                    @endif
                    <span class="mono poll-label">{{ $pollInterval === 0 ? 'paused' : 'every '.$curPoll['l'] }}</span>
                    <x-torque::icon name="chevD" :size="13"/>
                </button>
                <div wire:ignore class="popover" x-show="open" x-cloak @click.outside="open = false" x-transition.opacity>
                    <div class="eyebrow">Refresh</div>
                    @foreach ($pollOpts as $o)
                        <button type="button" class="popover-item mono"
                            :class="{ active: $wire.pollInterval === {{ $o['v'] }} }"
                            @click="$wire.setPollInterval({{ $o['v'] }}); open = false">
                            <x-torque::icon :name="$o['v'] === 0 ? 'pause' : 'refresh'" :size="12"/>
                            {{ $o['l'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Theme toggle. Same morph problem as the popover above: the hidden
                 icon would be un-hidden on every poll tick, showing both at once.
                 wire:ignore on a display:contents wrapper leaves both spans alone. --}}
            <button class="icon-btn" type="button" title="Toggle theme" @click="$store.torque.toggleTheme()">
                <span wire:ignore class="icon-swap">
                    <span x-show="$store.torque.theme === 'dark'"><x-torque::icon name="sun" :size="17"/></span>
                    <span x-show="$store.torque.theme !== 'dark'" x-cloak><x-torque::icon name="moon" :size="17"/></span>
                </span>
            </button>
        </header>
        <div class="content">
            <div class="content-inner page" @if ($pollInterval > 0) wire:poll.{{ $pollInterval }}ms @endif>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
