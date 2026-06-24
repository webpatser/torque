@props([
    'label',
    'used' => 0,
    'size' => 0,
])
@php
    $frac = $size > 0 ? $used / $size : 0;
    $col = $frac > 0.85 ? 'var(--warn)' : 'var(--accent)';
@endphp
<div style="flex: 1; min-width: 0;">
    <div class="row between" style="margin-bottom: 4px;">
        <span class="mono faint" style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em;">{{ $label }}</span>
        <span class="mono" style="font-size: 11px;">{{ $used }}<span class="faint">/{{ $size }}</span></span>
    </div>
    <div class="bar">
        <i style="width: {{ $frac * 100 }}%; background: {{ $col }};"></i>
    </div>
</div>
