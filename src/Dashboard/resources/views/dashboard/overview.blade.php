@php
    use Webpatser\Torque\Dashboard\Support\Format;

    $last = fn (array $a) => $a[array_key_last($a)] ?? 0;
    $util = (float) ($totals['util'] ?? 0);
    $workerCount = $metrics['workers'] ?? count($workers);
@endphp
<x-torque::shell title="Overview" crumb="torque:status · cluster health" active="overview"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval">

    {{-- hero --}}
    <div class="grid" style="grid-template-columns: minmax(0,1.15fr) minmax(0,1fr); align-items: stretch;">
        <div class="card" style="display: grid; grid-template-columns: auto 1fr; gap: 4px;">
            <div class="card-pad" style="display: grid; place-items: center; border-right: 1px solid var(--border);">
                <x-torque::viz.tachometer :value="round($totals['rpm'])" :max="2000" :size="208"/>
            </div>
            <div class="card-pad col" style="justify-content: center; gap: 18px;">
                <div>
                    <div class="eyebrow">Cluster throughput</div>
                    <div class="mono" style="font-size: 13px; color: var(--text-dim); margin-top: 6px; line-height: 1.6;">
                        {{ $workerCount }} workers running <b style="color: var(--text);">{{ $totals['busy'] }}</b> of
                        <b style="color: var(--text);">{{ $totals['slots'] }}</b> coroutine slots:
                        <span style="color: {{ $util > 0.85 ? 'var(--warn)' : 'var(--accent)' }};">{{ round($util * 100) }}% pressure</span>.
                    </div>
                </div>
                <div class="row gap20 wrap">
                    <div class="col" style="gap: 2px;">
                        <span class="mono faint" style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.07em; white-space: nowrap;">concurrent</span>
                        <span class="mono" style="font-size: 21px; font-weight: 700; line-height: 1;">{{ Format::int($last($concurrent)) }}</span>
                        <span class="mono faint" style="font-size: 10px;">fibers</span>
                    </div>
                    <div class="col" style="gap: 2px;">
                        <span class="mono faint" style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.07em; white-space: nowrap;">latency</span>
                        <span class="mono" style="font-size: 21px; font-weight: 700; line-height: 1;">{{ Format::num($last($latency), 2) }}s</span>
                        <span class="mono faint" style="font-size: 10px;">p50</span>
                    </div>
                    <div class="col" style="gap: 2px;">
                        <span class="mono faint" style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.07em; white-space: nowrap;">processed</span>
                        <span class="mono" style="font-size: 21px; font-weight: 700; line-height: 1;">{{ Format::int($metrics['jobsTotal']) }}</span>
                        <span class="mono faint" style="font-size: 10px;">all time</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
            <x-torque::stat label="Pending" :value="Format::int($totals['pending'])">
                <div class="spark"><x-torque::viz.sparkline :data="$pending" :w="116" :h="38"/></div>
            </x-torque::stat>
            <x-torque::stat label="Delayed" :value="Format::int($totals['delayed'])">
                <div class="spark"><x-torque::viz.sparkline :data="$latency" :w="116" :h="38" color="var(--info)"/></div>
            </x-torque::stat>
            <x-torque::stat label="Throughput" :value="Format::int($last($throughput))" unit="/s">
                <div class="spark"><x-torque::viz.sparkline :data="$throughput" :w="116" :h="38"/></div>
            </x-torque::stat>
            <x-torque::stat label="Memory" :value="Format::int($last($memory))" unit="MB">
                <div class="spark"><x-torque::viz.sparkline :data="$memory" :w="116" :h="38" color="var(--info)"/></div>
            </x-torque::stat>
        </div>
    </div>

    {{-- charts --}}
    <div class="grid mt16" style="grid-template-columns: minmax(0,2fr) minmax(0,1fr);">
        <div class="card">
            <div class="card-head">
                <h3>Throughput</h3>
                <span class="sub">jobs / second · last 200s</span>
                <div class="grow"></div>
                <div class="row gap16">
                    <span class="row gap6"><span style="width: 9px; height: 9px; border-radius: 3px; background: var(--accent);"></span><span class="mono faint" style="font-size: 11px;">completed</span></span>
                    <span class="row gap6"><span style="width: 9px; height: 9px; border-radius: 3px; background: var(--bad);"></span><span class="mono faint" style="font-size: 11px;">failed</span></span>
                </div>
            </div>
            <div class="card-pad" style="padding-top: 14px;">
                <div style="height: 132px;">
                    <x-torque::viz.sparkline :data="$throughput" :w="760" :h="132" full color="var(--accent)" :stroke-w="2" :dot="false"/>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-head">
                <h3>Failure rate</h3>
                <span class="sub">% of dispatched</span>
            </div>
            <div class="card-pad">
                <div class="row between" style="align-items: flex-end;">
                    <div class="mono" style="font-size: 34px; font-weight: 700; color: {{ $last($failRate) > 1.5 ? 'var(--warn)' : 'var(--ok)' }};">
                        {{ Format::num($last($failRate), 2) }}<span style="font-size: 16px; color: var(--text-faint);">%</span>
                    </div>
                    <x-torque::viz.sparkline :data="$failRate" :w="150" :h="56" color="var(--warn)"/>
                </div>
                <hr class="hr mt16">
                <div class="row between mt12">
                    <span class="mono faint" style="font-size: 11px;">dead-letter</span>
                    <a href="{{ route('torque.dead') }}" wire:navigate class="badge s-dead tiny" style="cursor: pointer;">
                        <span class="bdot"></span>{{ $deadCount }} jobs
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- workers + recent --}}
    <div class="grid mt16" style="grid-template-columns: minmax(0,1fr) minmax(0,1.3fr);">
        <div class="card">
            <div class="card-head">
                <h3>Workers</h3>
                <span class="sub">slot pressure</span>
                <div class="grow"></div>
                <a href="{{ route('torque.workers') }}" wire:navigate class="btn sm">View all <x-torque::icon name="chevR" :size="13"/></a>
            </div>
            <div class="card-pad" style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">
                @forelse ($workers as $w)
                    <a href="{{ route('torque.workers') }}" wire:navigate class="row gap12" style="cursor: pointer; color: inherit; text-decoration: none;">
                        <x-torque::viz.slot-ring :busy="$w['busy']" :slots="$w['slots']" :stalled="$w['stalled']" :size="70" :thick="7"/>
                        <div class="col" style="gap: 4px; min-width: 0;">
                            <span class="mono" style="font-size: 12.5px; font-weight: 600; white-space: nowrap;">{{ $w['host'] }}</span>
                            @if ($w['rpm'] !== null)
                                <span class="mono" style="font-size: 11.5px; color: var(--accent); white-space: nowrap;">{{ $w['rpm'] }} <span class="faint">rpm</span></span>
                            @endif
                            @if ($w['stalled'] > 0)
                                <span class="mono" style="font-size: 10px; color: var(--warn); white-space: nowrap;">{{ $w['stalled'] }} stalled</span>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="empty" style="grid-column: 1 / -1;"><span class="mono">no workers running</span></div>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <span class="livedot"></span>
                <h3>Live activity</h3>
                <div class="grow"></div>
                <a href="{{ route('torque.feed') }}" wire:navigate class="btn sm">Open feed <x-torque::icon name="chevR" :size="13"/></a>
            </div>
            <table class="tbl">
                <tbody>
                    @forelse ($live as $j)
                        <tr class="clickable" @click="Livewire.navigate('{{ route('torque.inspector.job', ['uuid' => $j['id']]) }}')">
                            <td style="width: 30px;"><x-torque::badge :status="$j['status']" tiny/></td>
                            <td><x-torque::jobname :ns="$j['ns']" :cls="$j['cls']"/></td>
                            <td class="muted mono" style="font-size: 11.5px;">{{ $j['queue'] }}</td>
                            <td class="r" style="width: 120px;">
                                @if ($j['status'] === 'running')
                                    <div class="bar" style="width: 80px; margin-left: auto;"><i style="width: {{ round(($j['progress'] ?? 0) * 100) }}%;"></i></div>
                                @else
                                    <span class="mono faint" style="font-size: 11px;">{{ Format::ago($j['ts']) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td><div class="empty"><span class="mono">no recent activity</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-torque::shell>
