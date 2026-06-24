@props([
    'busy' => 0,
    'slots' => 0,
    'stalled' => 0,
])
<div class="slots">
    @for ($i = 0; $i < $slots; $i++)
        @php
            $cls = 's';
            if ($i < $busy) {
                $cls .= ($stalled && $i >= $busy - $stalled) ? ' stall' : ' on';
            }
        @endphp
        <span class="{{ $cls }}"></span>
    @endfor
</div>
