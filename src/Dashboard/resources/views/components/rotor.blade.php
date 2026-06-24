@props([
    'size' => 30,
    'spin' => true,
])
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 40 40" fill="none">
    <circle cx="20" cy="20" r="17" stroke="var(--border-2)" stroke-width="2" opacity="0.5"/>
    <g @class(['rotor-inner' => $spin])>
        <path d="M20 5 A15 15 0 0 1 33 13" stroke="var(--accent)" stroke-width="3.2" stroke-linecap="round" fill="none"/>
        <path d="M35 20 A15 15 0 0 1 27 33" stroke="var(--accent)" stroke-width="3.2" stroke-linecap="round" fill="none" opacity="0.5"/>
        <circle cx="20" cy="20" r="5.5" fill="var(--accent)"/>
        <circle cx="20" cy="20" r="2" fill="var(--accent-ink)"/>
    </g>
</svg>
