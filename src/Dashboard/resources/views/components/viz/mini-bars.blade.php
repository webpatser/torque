@props([
    'data' => [],
    'w' => 120,
    'h' => 34,
    'color' => 'var(--accent)',
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
    <svg width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none">
        @foreach ($series as $i => $v)
            @php
                $bh = max(1.5, ($v / $max) * ($h - 2));
                $opacity = 0.35 + 0.65 * ($i / $count);
            @endphp
            <rect x="{{ $i * $bw + 0.5 }}" y="{{ $h - $bh }}" width="{{ max(1, $bw - 1.3) }}" height="{{ $bh }}" rx="1" fill="{{ $color }}" opacity="{{ round($opacity, 3) }}"/>
        @endforeach
    </svg>
@endif
