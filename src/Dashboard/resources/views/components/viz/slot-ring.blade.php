@props([
    'busy' => 0,
    'slots' => 0,
    'stalled' => 0,
    'size' => 92,
    'thick' => 8,
])
@php
    $cx = $size / 2;
    $cy = $size / 2;
    $r = $size / 2 - $thick / 2 - 2;
    $c = 2 * M_PI * $r;
    $frac = $slots > 0 ? $busy / $slots : 0;
    $col = $frac > 0.9 ? 'var(--bad)' : ($frac > 0.7 ? 'var(--warn)' : 'var(--accent)');
    $offset = $c * (1 - $frac);
@endphp
<div style="position: relative; width: {{ $size }}px; height: {{ $size }}px;">
    <svg width="{{ $size }}" height="{{ $size }}" style="transform: rotate(-90deg);">
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" stroke="var(--surface-3)" stroke-width="{{ $thick }}" fill="none"/>
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" stroke="{{ $col }}" stroke-width="{{ $thick }}" fill="none" stroke-linecap="round" stroke-dasharray="{{ $c }}" stroke-dashoffset="{{ $offset }}" style="transition: stroke-dashoffset 0.6s var(--ease), stroke 0.4s;"/>
    </svg>
    <div style="position: absolute; inset: 0; display: grid; place-items: center; line-height: 1;">
        <div style="text-align: center;">
            <div class="mono" style="font-size: {{ $size > 80 ? 19 : 15 }}px; font-weight: 700;">
                {{ $busy }}<span style="color: var(--text-faint); font-size: 0.6em;">/{{ $slots }}</span>
            </div>
            @if ($size > 80)
                <div class="mono" style="font-size: 9px; color: var(--text-faint); margin-top: 2px;">
                    @if ($stalled)<span style="color: var(--warn);">{{ $stalled }} stalled</span>@else slots @endif
                </div>
            @endif
        </div>
    </div>
</div>
