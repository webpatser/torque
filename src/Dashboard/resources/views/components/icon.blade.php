@props([
    'name',
    'size' => 18,
    'stroke' => 1.7,
])
@php
    $paths = [
        'gauge' => '<path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="m13.4 10.6 3.6-3.6"/><path d="M4.5 18a9 9 0 1 1 15 0"/>',
        'workers' => '<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01"/>',
        'queues' => '<path d="M3 7l9-4 9 4-9 4-9-4Z"/><path d="m3 12 9 4 9-4"/><path d="m3 17 9 4 9-4"/>',
        'feed' => '<path d="M3 12h4l2 6 4-14 2 8h6"/>',
        'dead' => '<circle cx="9" cy="10" r="1"/><circle cx="15" cy="10" r="1"/><path d="M12 2a8 8 0 0 0-8 8c0 2.5 1 4 2 5v3a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-3c1-1 2-2.5 2-5a8 8 0 0 0-8-8Z"/><path d="M9 18v2M12 18v2M15 18v2"/>',
        'inspect' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
        'pause' => '<rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/>',
        'play' => '<path d="M7 4.5v15l13-7.5-13-7.5Z"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>',
        'retry' => '<path d="M3 12a9 9 0 1 1 3 6.7"/><path d="M3 20v-5h5"/>',
        'trash' => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevR' => '<path d="m9 6 6 6-6 6"/>',
        'chevL' => '<path d="m15 6-6 6 6 6"/>',
        'chevD' => '<path d="m6 9 6 6 6-6"/>',
        'collapse' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'arrowUp' => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'arrowDown' => '<path d="M12 5v14M5 12l7 7 7-7"/>',
        'bolt' => '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'cpu' => '<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
        'dots' => '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
        'ext' => '<path d="M15 3h6v6M10 14 21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
    ];
@endphp
<svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round">{!! $paths[$name] ?? '' !!}</svg>
