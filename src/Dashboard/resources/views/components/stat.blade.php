@props([
    'label',
    'value',
    'unit' => null,
    'icon' => null,
])
<div class="card stat">
    <div class="lbl">
        @if ($icon)<x-torque::icon :name="$icon" :size="13"/>@endif
        {{ $label }}
    </div>
    <div class="val tnum">{{ $value }}@if ($unit)<span class="u">{{ $unit }}</span>@endif</div>
    @if (! $slot->isEmpty())
        {{ $slot }}
    @endif
</div>
