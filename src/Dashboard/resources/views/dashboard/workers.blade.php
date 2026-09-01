@php
    use Illuminate\Support\Str;
    use Webpatser\Torque\Dashboard\Support\Format;

    $util = (float) ($totals['util'] ?? 0);
    $live = array_values(array_filter($hosts, fn ($h) => $h['status'] === 'active'));
    $workerCount = array_sum(array_map(fn ($h) => count($h['workers']), $hosts));
@endphp
<x-torque::shell title="Workers" crumb="Revolt event loop processes" active="workers"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval" :range="$range">

    <div class="grid mb16" style="grid-template-columns: repeat(4,1fr);">
        <x-torque::stat label="Hosts" :value="count($live)" :unit="count($hosts) > count($live) ? ' / '.count($hosts) : null"/>
        <x-torque::stat label="Busy slots" :value="$totals['busy']" :unit="' / '.$totals['slots']"/>
        <x-torque::stat label="Cluster RPM" :value="Format::int($totals['rpm'])"/>
        <x-torque::stat label="Slot pressure" :value="round($util * 100)" unit="%"/>
    </div>

    {{-- One card per host, not per worker process. A worker mints a fresh
         `{host}-{pid}-{hex}` name on every start, so per-process cards have no
         history to show over a range; the live processes are listed inside
         their host's card, where the pid is what identifies them. --}}
    <div class="grid" style="gap: 16px;">
        @forelse ($hosts as $h)
            @php $gone = $h['status'] === 'gone'; @endphp
            <div class="card" @style(['opacity: 0.62' => $gone])>
                <div class="card-pad" style="display: grid; grid-template-columns: auto 1fr auto; gap: 22px; align-items: center;">
                    <x-torque::viz.slot-ring :busy="$h['busy']" :slots="$h['slots']" :stalled="$h['stalled']" :size="96" :thick="9"/>
                    <div class="col" style="gap: 12px; min-width: 0;">
                        <div class="row gap12 wrap">
                            <span class="mono" style="font-size: 15px; font-weight: 700;">{{ $h['host'] }}</span>
                            @if ($gone)
                                <span class="badge s-dead tiny">
                                    <span class="bdot"></span><span>gone</span>@if ($h['lastSeen'] !== null) · last seen {{ Format::dur(max(0, time() - $h['lastSeen'])) }} ago @endif
                                </span>
                            @else
                                <span class="badge s-completed tiny"><span class="bdot"></span>active</span>
                            @endif
                            @if ($h['stalled'] > 0)
                                <span class="badge s-retrying tiny"><span class="bdot"></span>{{ $h['stalled'] }} slot stalled</span>
                            @endif
                            <span class="mono faint" style="font-size: 11px;">
                                {{ count($h['workers']) }} {{ Str::plural('worker', count($h['workers'])) }}@if ($h['uptime'] !== null) · up {{ Format::dur($h['uptime']) }}@endif
                            </span>
                        </div>
                        @foreach ($h['workers'] as $w)
                            <div class="row gap12 wrap">
                                <span class="mono faint" style="font-size: 11px; min-width: 74px;">pid {{ $w['pid'] ?? '–' }}</span>
                                <x-torque::viz.slot-strip :busy="$w['busy']" :slots="$w['slots']" :stalled="$w['stalled']"/>
                                <span class="mono faint" style="font-size: 11px;">{{ Format::int($w['memMb']) }}MB</span>
                                @if (! empty($w['pools']))
                                    @foreach (['redis', 'mysql', 'http'] as $pool)
                                        @if (! empty($w['pools'][$pool]))
                                            <x-torque::viz.pool-pill :label="$pool" :used="$w['pools'][$pool][0]" :size="$w['pools'][$pool][1]"/>
                                        @endif
                                    @endforeach
                                @endif
                            </div>
                        @endforeach
                        @if ($h['workers'] === [])
                            <span class="mono faint" style="font-size: 11px;">no live workers on this host</span>
                        @endif
                    </div>
                    <div class="col" style="gap: 14px; text-align: right; border-left: 1px solid var(--border); padding-left: 22px; min-width: 168px;">
                        <div class="row between">
                            <span class="mono faint" style="font-size: 11px;">throughput</span>
                            <span class="mono" style="font-size: 14px; font-weight: 600; color: var(--accent);">{{ $h['rpm'] }} rpm</span>
                        </div>
                        <div class="row between">
                            <span class="mono faint" style="font-size: 11px;">processed</span>
                            <span class="mono" style="font-size: 14px; font-weight: 600;">{{ Format::int($h['processed']) }}</span>
                        </div>
                        <div class="row between">
                            <span class="mono faint" style="font-size: 11px;">memory</span>
                            <span class="mono" style="font-size: 14px; font-weight: 600;">
                                {{ Format::int($h['memMb']) }}@if ($h['memPeakMb'] !== null)<span class="faint" style="font-size: 11px;"> / {{ Format::int($h['memPeakMb']) }}MB peak</span>@endif
                            </span>
                        </div>
                        {{-- The per-host rollup over the selected range, so this
                             survives a reload instead of filling up one poll at
                             a time the way the old client-side array did. --}}
                        <div style="margin-top: 2px;">
                            <x-torque::viz.mini-bars :data="$h['history']" :w="168" :h="30" full/>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="card"><div class="empty"><span class="mono">no workers in {{ $window->short }}</span></div></div>
        @endforelse
    </div>
</x-torque::shell>
