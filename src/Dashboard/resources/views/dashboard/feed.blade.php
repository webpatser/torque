@php
    use Webpatser\Torque\Dashboard\Support\Format;

    $evClass = ['running' => 'started', 'progress' => 'progress', 'queued' => 'queued', 'completed' => 'completed', 'failed' => 'failed', 'exception' => 'exception', 'retrying' => 'retrying'];
    $segments = [['all', 'all'], ['active', 'active'], ['queued', 'queued'], ['completed', 'done'], ['failed', 'failed']];
@endphp
<x-torque::shell title="Live feed" crumb="torque:tail · real-time activity" active="feed"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval">

    <div class="grid" style="grid-template-columns: minmax(0,1.55fr) minmax(0,1fr); align-items: start;">
        <div class="card">
            <div class="card-head">
                <span class="livedot"></span>
                <h3>Live jobs</h3>
                <div class="grow"></div>
                <div class="seg">
                    @foreach ($segments as [$key, $label])
                        <button type="button" wire:click="setFilter('{{ $key }}')" @class(['on' => $filter === $key])>
                            {{ $label }} @if ($counts[$key] ?? 0)<span style="opacity: 0.6;">{{ $counts[$key] }}</span>@endif
                        </button>
                    @endforeach
                </div>
            </div>
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Job</th>
                        <th>Queue</th>
                        <th>Worker</th>
                        <th style="width: 140px;">Progress</th>
                        <th class="r">Runtime</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $j)
                        <tr class="clickable" @click="Livewire.navigate('{{ route('torque.inspector.job', ['uuid' => $j['id']]) }}')">
                            <td><x-torque::badge :status="$j['status']" tiny/></td>
                            <td>
                                <x-torque::jobname :ns="$j['ns']" :cls="$j['cls']"/>
                                <div class="mono faint" style="font-size: 10px; margin-top: 2px;">
                                    {{ \Illuminate\Support\Str::substr($j['id'], 0, 8) }}@if ($j['attempt'] > 1)<span style="color: var(--warn);"> · attempt {{ $j['attempt'] }}/{{ $j['maxTries'] }}</span>@endif
                                </div>
                            </td>
                            <td class="mono muted" style="font-size: 11.5px;">{{ $j['queue'] }}</td>
                            <td class="mono faint" style="font-size: 11px;">{{ $j['worker'] ?: '–' }}</td>
                            <td>
                                @if (in_array($j['status'], ['running', 'progress'], true))
                                    <div class="row gap8">
                                        <div class="bar" style="flex: 1;"><i style="width: {{ round(($j['progress'] ?? 0) * 100) }}%;"></i></div>
                                        <span class="mono faint" style="font-size: 10px; width: 30px;">{{ round(($j['progress'] ?? 0) * 100) }}%</span>
                                    </div>
                                @elseif ($j['status'] === 'completed')
                                    <div class="bar ok"><i style="width: 100%;"></i></div>
                                @elseif ($j['status'] === 'failed')
                                    <div class="bar bad"><i style="width: 100%;"></i></div>
                                @else
                                    <span class="mono faint" style="font-size: 11px;">–</span>
                                @endif
                            </td>
                            <td class="r mono" style="font-size: 11.5px;">{{ Format::num($j['runtime'] ?? 0, 1) }}s</td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty mono">no jobs match this filter</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- terminal tail --}}
        <div class="card" style="position: sticky; top: 0;">
            <div class="card-head">
                <x-torque::icon name="bolt" :size="15"/>
                <h3>Stream tail</h3>
                <span class="sub">torque:tail --all</span>
                <div class="grow"></div>
                <span class="livedot"></span>
            </div>
            <div class="card-pad">
                <div class="term" style="height: 420px;">
                    @foreach ($tail as $l)
                        <div class="ln">
                            <span class="t">{{ Format::hms($l['t']) }}</span>
                            <span class="ev {{ $evClass[$l['type']] ?? $l['type'] }}">{{ $l['type'] }}</span>
                            <span class="msg">{{ $l['job'] }}@if ($l['msg']) : {{ $l['msg'] }}@endif</span>
                        </div>
                    @endforeach
                    <div class="ln">
                        <span class="t" style="color: var(--accent);">›</span>
                        <span class="msg" style="color: var(--accent);">tailing…<span class="cursor-blink">▋</span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-torque::shell>
