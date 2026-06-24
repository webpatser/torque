@props([
    'ns' => '',
    'cls' => '',
])
<span {{ $attributes->class('jobname') }}>@if ($ns)<span class="ns">{{ $ns }}</span>@endif{{ $cls }}</span>
