@props([
    'value' => 0,
    'max' => 600,
    'size' => 200,
    'label' => 'JOBS / MIN',
    'redline' => 0.85,
])
@php
    use Webpatser\Torque\Dashboard\Support\Svg;
    use Webpatser\Torque\Dashboard\Support\Format;

    $start = 135;
    $sweep = 270;
    $cx = $size / 2;
    $cy = $size / 2;
    $r = $size / 2 - 16;
    $frac = max(0, min(1, $max > 0 ? $value / $max : 0));
    $valDeg = $start + $sweep * $frac;

    $ticks = [];
    for ($i = 0; $i <= 10; $i++) {
        $deg = $start + ($sweep * $i) / 10;
        $major = $i % 2 === 0;
        [$x1, $y1] = Svg::polar($cx, $cy, $r - 1, $deg);
        [$x2, $y2] = Svg::polar($cx, $cy, $r - ($major ? 11 : 6), $deg);
        $hot = $i / 10 >= $redline;
        $ticks[] = compact('x1', 'y1', 'x2', 'y2', 'major', 'hot');
    }
    [$nx, $ny] = Svg::polar($cx, $cy, $r - 18, $valDeg);
@endphp
<div class="gauge-wrap">
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}">
        <path d="{{ Svg::arcPath($cx, $cy, $r, $start, $start + $sweep) }}" stroke="var(--surface-3)" stroke-width="9" fill="none" stroke-linecap="round"/>
        <path d="{{ Svg::arcPath($cx, $cy, $r, $start + $sweep * $redline, $start + $sweep) }}" stroke="var(--bad)" stroke-width="9" fill="none" stroke-linecap="round" opacity="0.5"/>
        <path d="{{ Svg::arcPath($cx, $cy, $r, $start, $valDeg) }}" stroke="var(--accent)" stroke-width="9" fill="none" stroke-linecap="round" style="filter: drop-shadow(0 0 6px var(--accent-soft));"/>
        @foreach ($ticks as $t)
            <line x1="{{ $t['x1'] }}" y1="{{ $t['y1'] }}" x2="{{ $t['x2'] }}" y2="{{ $t['y2'] }}" stroke="{{ $t['hot'] ? 'var(--bad)' : 'var(--text-faint)' }}" stroke-width="{{ $t['major'] ? 2 : 1 }}" opacity="{{ $t['major'] ? 0.85 : 0.45 }}"/>
        @endforeach
        <line x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $nx }}" y2="{{ $ny }}" stroke="var(--text)" stroke-width="2.5" stroke-linecap="round"/>
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="6" fill="var(--surface)" stroke="var(--text)" stroke-width="2"/>
        <text x="{{ $cx }}" y="{{ $cy + $r * 0.45 }}" text-anchor="middle" style="font-family: var(--font-mono); font-size: 30px; font-weight: 700; fill: var(--text);">{{ Format::int($value) }}</text>
        <text x="{{ $cx }}" y="{{ $cy + $r * 0.45 + 16 }}" text-anchor="middle" style="font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.12em; fill: var(--text-faint);">{{ $label }}</text>
    </svg>
</div>
