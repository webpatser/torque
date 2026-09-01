@props([
    'data' => [],
    'w' => 120,
    'h' => 34,
    'color' => 'var(--accent)',
    'full' => false,
])
@php
    $series = array_values($data);
@endphp
@if (! empty($series))
    @php
        $max = max($series) ?: 1;
        $count = count($series);
        $bw = $w / $count;
    @endphp
    {{-- `full` renders a fluid chart: the viewBox keeps the bar geometry while
         the element itself is 100% of its container, so a card whose grid track
         is narrower than $w scales the bars down instead of letting them spill
         out from under the card (.card carries min-width: 0 and must never
         clip, or it would cut off the popovers). Mirrors sparkline's `full`. --}}
    <svg width="{{ $full ? '100%' : $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}"
        preserveAspectRatio="none" @if ($full) style="display: block;" @endif>
        @foreach ($series as $i => $v)
            @php
                $bh = max(1.5, ($v / $max) * ($h - 2));
                $opacity = 0.35 + 0.65 * ($i / $count);
            @endphp
            <rect x="{{ $i * $bw + 0.5 }}" y="{{ $h - $bh }}" width="{{ max(1, $bw - 1.3) }}" height="{{ $bh }}" rx="1" fill="{{ $color }}" opacity="{{ round($opacity, 3) }}"/>
        @endforeach
    </svg>
@endif
