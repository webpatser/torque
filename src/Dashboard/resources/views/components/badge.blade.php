@props([
    'status',
    'label' => null,
    'tiny' => false,
    'dot' => true,
])
@php
    $labels = [
        'queued' => 'queued',
        'running' => 'running',
        'progress' => 'running',
        'completed' => 'completed',
        'retrying' => 'retrying',
        'exception' => 'exception',
        'failed' => 'failed',
        'dead' => 'dead',
        'delayed' => 'delayed',
    ];
@endphp
<span {{ $attributes->class(['badge', 's-'.$status, 'tiny' => $tiny]) }}>
    @if ($dot)<span class="bdot"></span>@endif
    {{ $label ?? ($labels[$status] ?? $status) }}
</span>
