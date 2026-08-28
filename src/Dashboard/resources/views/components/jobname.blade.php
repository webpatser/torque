@props([
    'ns' => '',
    'cls' => '',
])
{{-- The table cell truncates long names, so keep the fully-qualified name in a
     tooltip. A caller-supplied title still wins over this default. --}}
<span {{ $attributes->class('jobname')->merge(['title' => $ns.$cls]) }}>@if ($ns)<span class="ns">{{ $ns }}</span>@endif{{ $cls }}</span>
