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
    $cspWarning = rescue(fn () => cache()->get(\Webpatser\Torque\Dashboard\Http\Middleware\DetectCspMismatch::CACHE_KEY), null, false);
@endphp
{{-- The collapsed-nav class lives on <html> (see the chrome script in the
     layout), not here: this node sits inside the Livewire root, so a poll tick
     would morph any client-set class straight back to the server value. --}}
<div class="app">
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
            <button class="icon-btn" type="button" title="Toggle sidebar" data-torque-action="toggle-nav">
                <x-torque::icon name="collapse" :size="17"/>
            </button>
            <div class="tb-title">
                <h1>{{ $title }}</h1>
                @if ($crumb)<span class="crumb">{{ $crumb }}</span>@endif
            </div>
            <div class="spacer"></div>

            {{-- Poll interval selector.

                 Open/closed is a CSS class toggled by the nonce'd chrome script
                 in the layout (no Alpine: its expressions need 'unsafe-eval').
                 This page polls, so the panel is wire:ignore'd: a morph would
                 otherwise strip the `open` class from under the user's cursor.
                 The initial active option is rendered server-side; the script
                 moves the class on click and calls setPollInterval() on the
                 component through Livewire.find().call(), a plain JS call that
                 needs no wire:click expression evaluation (the CSP build of
                 Livewire interprets expressions and is the fragile part). --}}
            <div class="popover-anchor">
                <button type="button" class="btn sm poll-trigger" data-torque-action="toggle-popover" aria-expanded="false">
                    @if ($pollInterval === 0)
                        <x-torque::icon name="pause" :size="13"/>
                    @else
                        <span class="livedot sm"></span>
                    @endif
                    <span class="mono poll-label">{{ $pollInterval === 0 ? 'paused' : 'every '.$curPoll['l'] }}</span>
                    <x-torque::icon name="chevD" :size="13"/>
                </button>
                <div wire:ignore class="popover">
                    <div class="eyebrow">Refresh</div>
                    @foreach ($pollOpts as $o)
                        <button type="button" @class(['popover-item', 'mono', 'active' => $o['v'] === $pollInterval])
                            data-torque-action="set-poll"
                            data-torque-value="{{ $o['v'] }}">
                            <x-torque::icon :name="$o['v'] === 0 ? 'pause' : 'refresh'" :size="12"/>
                            {{ $o['l'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Theme toggle. Both icons are always rendered; CSS picks one off
                 the <html data-theme> attribute, so a poll tick has nothing to
                 un-hide and the pair can never show at once. --}}
            <button class="icon-btn" type="button" title="Toggle theme" data-torque-action="toggle-theme">
                <span class="icon-swap">
                    <span class="icon-sun"><x-torque::icon name="sun" :size="17"/></span>
                    <span class="icon-moon"><x-torque::icon name="moon" :size="17"/></span>
                </span>
            </button>
        </header>
        @if (is_string($cspWarning) && $cspWarning !== '')
            <div class="notice warn" role="alert">
                <x-torque::icon name="warn" :size="14"/>
                <span>{{ $cspWarning }}</span>
            </div>
        @endif
        <div class="content">
            {{-- wire:key forces the morph to replace this element when the interval
                 changes: Livewire initialises wire:poll once and never re-reads the
                 modifier, so without the key the old timer would keep running. --}}
            <div class="content-inner page" wire:key="poll-{{ $pollInterval }}" @if ($pollInterval > 0) wire:poll.{{ $pollInterval }}ms @endif>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
