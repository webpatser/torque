@props([
    'events' => [],
    'live' => false,
    'newCount' => 0,
])
@php
    use Webpatser\Torque\Dashboard\Support\Format;

    // Real event types: queued | started | progress | completed | failed |
    // exception | dead_lettered (dead_lettered renders like failed).
    $evMeta = [
        'queued' => ['node' => 'idle', 'icon' => 'clock', 'label' => 'queued'],
        'started' => ['node' => 'run', 'icon' => 'play', 'label' => 'started'],
        'progress' => ['node' => 'run', 'icon' => 'bolt', 'label' => 'progress'],
        'completed' => ['node' => 'ok', 'icon' => 'play', 'label' => 'completed'],
        'exception' => ['node' => 'warn', 'icon' => 'x', 'label' => 'exception'],
        'retrying' => ['node' => 'warn', 'icon' => 'retry', 'label' => 'retrying'],
        'failed' => ['node' => 'bad', 'icon' => 'dead', 'label' => 'failed'],
        'dead_lettered' => ['node' => 'bad', 'icon' => 'dead', 'label' => 'dead-letter'],
    ];
    $typeColor = ['run' => 'accent', 'ok' => 'ok', 'warn' => 'warn', 'bad' => 'bad'];

    $events = array_values($events);
    $total = count($events);
@endphp
<div class="timeline">
    <div class="tl-line"></div>
    @foreach ($events as $i => $ev)
        @php
            $type = $ev['type'] ?? 'progress';
            $meta = $evMeta[$type] ?? $evMeta['progress'];
            $d = $ev['data'] ?? [];
            $ts = (int) ($ev['ts'] ?? 0);
            $isFail = in_array($type, ['failed', 'dead_lettered'], true);
            $isLive = $live && $i === $total - 1;
            $isNew = $i >= $total - $newCount;
            $progress = isset($d['progress']) ? (float) $d['progress'] : null;
            $mem = Format::memMb($d['memory_bytes'] ?? null);
            $nodeColor = $typeColor[$meta['node']] ?? 'text-dim';
        @endphp
        <div @class(['tl-item', 'tl-enter' => $isNew])>
            <div class="tl-node {{ $meta['node'] }}">
                @if ($type === 'exception' || $isFail)
                    <x-torque::icon :name="$isFail ? 'dead' : 'x'" :size="12" :stroke="2.2"/>
                @elseif ($type === 'completed')
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                @else
                    <x-torque::icon :name="$meta['icon']" :size="11" :stroke="2.2"/>
                @endif
            </div>
            <div @class(['tl-card', 'live' => $isLive])>
                <div class="tl-row">
                    <span class="tl-type" style="color: var(--{{ $nodeColor }});">{{ $meta['label'] }}</span>
                    @if ($isLive)<span class="livedot" style="width: 6px; height: 6px;"></span>@endif
                    <span class="tl-time">{{ Format::clock($ts) }}</span>
                </div>
                @if (! empty($d['message']))
                    <div class="tl-msg">{{ $d['message'] }}</div>
                @endif
                @if ($type === 'progress' && $progress !== null)
                    <div class="bar" style="margin-top: 9px;"><i style="width: {{ round($progress * 100) }}%;"></i></div>
                @endif
                @if (! empty($d['exception_class']))
                    <div class="tl-msg" style="color: var(--warn); margin-top: 6px;">
                        {{ $d['exception_class'] }}@if (! empty($d['exception_message'])): {{ $d['exception_message'] }}@endif
                    </div>
                @endif
                <div class="tl-meta">
                    @if (! empty($d['worker']))<span class="tag">worker <b>{{ $d['worker'] }}</b></span>@endif
                    @if (isset($d['attempt']))<span class="tag">attempt <b>{{ $d['attempt'] }}</b></span>@endif
                    @if (! empty($d['queue']))<span class="tag">queue <b>{{ $d['queue'] }}</b></span>@endif
                    @if ($mem)<span class="tag">mem <b>{{ $mem }}</b></span>@endif
                </div>
            </div>
        </div>
    @endforeach
</div>
