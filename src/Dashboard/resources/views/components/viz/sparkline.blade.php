@props([
    'data' => [],
    'w' => 120,
    'h' => 34,
    'color' => 'var(--accent)',
    'fill' => true,
    'strokeW' => 1.6,
    'dot' => true,
    'full' => false,
])
@php
    use Webpatser\Torque\Dashboard\Support\Svg;

    $series = array_values(array_filter($data, fn ($v) => $v !== null));
@endphp
@if (! empty($series))
    @php
        $p = Svg::sparkline($series, (float) $w, (float) $h);
        $gradId = 'sg'.substr(md5($p['line'].$color), 0, 8);
    @endphp
    <svg
        width="{{ $full ? '100%' : $w }}"
        height="{{ $h }}"
        viewBox="0 0 {{ $w }} {{ $h }}"
        preserveAspectRatio="{{ $full ? 'none' : 'xMidYMid meet' }}"
        @if ($full) style="display: block;" @endif
    >
        @if ($fill)
            <defs>
                <linearGradient id="{{ $gradId }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="{{ $color }}" stop-opacity="0.22"/>
                    <stop offset="100%" stop-color="{{ $color }}" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <path d="{{ $p['area'] }}" fill="url(#{{ $gradId }})"/>
        @endif
        <path d="{{ $p['line'] }}" fill="none" stroke="{{ $color }}" stroke-width="{{ $strokeW }}" stroke-linejoin="round" stroke-linecap="round" @if ($full) vector-effect="non-scaling-stroke" @endif/>
        @if ($dot)
            <circle cx="{{ $p['lastX'] }}" cy="{{ $p['lastY'] }}" r="2.3" fill="{{ $color }}"/>
        @endif
    </svg>
@endif
