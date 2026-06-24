@php
    use Webpatser\Torque\Dashboard\Support\Format;

    $util = (float) ($totals['util'] ?? 0);
@endphp
<x-torque::shell title="Workers" crumb="Revolt event loop processes" active="workers"
    :dead-count="$deadCount" :worker-count="count($workers)" :poll-interval="$pollInterval">

    <div class="grid mb16" style="grid-template-columns: repeat(4,1fr);">
        <x-torque::stat label="Active workers" :value="$active" :unit="' / '.count($workers)"/>
        <x-torque::stat label="Busy slots" :value="$totals['busy']" :unit="' / '.$totals['slots']"/>
        <x-torque::stat label="Cluster RPM" :value="Format::int($totals['rpm'])"/>
        <x-torque::stat label="Slot pressure" :value="round($util * 100)" unit="%"/>
    </div>

    <div class="grid" style="gap: 16px;">
        @forelse ($workers as $w)
            @php $hasPools = ! empty($w['pools']); @endphp
            <div class="card">
                <div class="card-pad" style="display: grid; grid-template-columns: auto 1fr auto; gap: 22px; align-items: center;">
                    <x-torque::viz.slot-ring :busy="$w['busy']" :slots="$w['slots']" :stalled="$w['stalled']" :size="96" :thick="9"/>
                    <div class="col" style="gap: 12px; min-width: 0;">
                        <div class="row gap12 wrap">
                            <span class="mono" style="font-size: 15px; font-weight: 700;">{{ $w['host'] }}</span>
                            <span class="badge s-completed tiny"><span class="bdot"></span>active</span>
                            @if ($w['stalled'] > 0)
                                <span class="badge s-retrying tiny"><span class="bdot"></span>{{ $w['stalled'] }} slot stalled</span>
                            @endif
                            <span class="mono faint" style="font-size: 11px;">
                                {{ $w['id'] }}@if ($w['pid'] !== null) · pid {{ $w['pid'] }}@endif@if ($w['uptime'] !== null) · up {{ Format::dur($w['uptime']) }}@endif
                            </span>
                        </div>
                        <x-torque::viz.slot-strip :busy="$w['busy']" :slots="$w['slots']" :stalled="$w['stalled']"/>
                        @if ($hasPools)
                            <div class="row gap16 wrap" style="margin-top: 2px;">
                                @foreach (['redis', 'mysql', 'http'] as $pool)
                                    @if (! empty($w['pools'][$pool]))
                                        <x-torque::viz.pool-pill :label="$pool" :used="$w['pools'][$pool][0]" :size="$w['pools'][$pool][1]"/>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="col" style="gap: 14px; text-align: right; border-left: 1px solid var(--border); padding-left: 22px; min-width: 168px;">
                        @if ($w['rpm'] !== null)
                            <div class="row between">
                                <span class="mono faint" style="font-size: 11px;">throughput</span>
                                <span class="mono" style="font-size: 14px; font-weight: 600; color: var(--accent);">{{ $w['rpm'] }} rpm</span>
                            </div>
                        @endif
                        <div class="row between">
                            <span class="mono faint" style="font-size: 11px;">processed</span>
                            <span class="mono" style="font-size: 14px; font-weight: 600;">{{ Format::int($w['processed']) }}</span>
                        </div>
                        <div class="row between">
                            <span class="mono faint" style="font-size: 11px;">memory</span>
                            <span class="mono" style="font-size: 14px; font-weight: 600;">
                                {{ Format::int($w['memMb']) }}@if ($w['memPeakMb'] !== null)<span class="faint" style="font-size: 11px;"> / {{ Format::int($w['memPeakMb']) }}MB</span>@endif
                            </span>
                        </div>
                        <div style="margin-top: 2px;">
                            <x-torque::viz.sparkline :data="$w['history']" :w="168" :h="30" :stroke-w="1.6"/>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="card"><div class="empty"><span class="mono">no workers running</span></div></div>
        @endforelse
    </div>
</x-torque::shell>
