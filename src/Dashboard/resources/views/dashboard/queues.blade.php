@php
    use Webpatser\Torque\Dashboard\Support\Format;
@endphp
<x-torque::shell title="Queues &amp; Streams" crumb="Redis Streams · consumer groups" active="queues"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval">

    <div class="grid mb16" style="grid-template-columns: repeat({{ $hasToday ? 4 : 3 }},1fr);">
        <x-torque::stat label="Streams" :value="count($queues)"/>
        <x-torque::stat label="Total pending" :value="Format::int($totals['pending'])"/>
        <x-torque::stat label="Delayed" :value="Format::int($totals['delayed'])"/>
        @if ($hasToday)
            <x-torque::stat label="Processed today" :value="Format::int($totals['today'])"/>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <h3>Redis Streams</h3>
            <span class="sub">XREADGROUP · consumer group "torque"</span>
        </div>
        <table class="tbl">
            <thead>
                <tr>
                    <th>Stream</th>
                    <th class="r">Pending</th>
                    <th class="r">Delayed</th>
                    <th class="r">Reserved</th>
                    @if ($hasThroughput)<th class="r">Throughput</th>@endif
                    @if ($hasWait)<th class="r">Wait</th>@endif
                    <th style="width: 150px;">Depth · 200s</th>
                    <th style="width: 90px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($queues as $q)
                    <tr class="clickable" @click="Livewire.navigate('{{ route('torque.feed') }}')">
                        <td>
                            <div class="row gap8">
                                <span class="mono" style="font-size: 12.5px; color: var(--text-faint);">torque:{</span>
                                <span class="mono" style="font-size: 12.5px; font-weight: 600;">{{ $q['name'] }}</span>
                                <span class="mono" style="font-size: 12.5px; color: var(--text-faint);">}</span>
                                @if ($q['name'] === 'high')
                                    <span class="badge s-running tiny"><span class="bdot"></span>priority</span>
                                @endif
                            </div>
                        </td>
                        <td class="r mono"><span style="font-weight: 600; color: {{ $q['pending'] > 300 ? 'var(--warn)' : 'var(--text)' }};">{{ Format::int($q['pending']) }}</span></td>
                        <td class="r mono muted">{{ $q['delayed'] ?: '–' }}</td>
                        <td class="r mono muted">{{ $q['reserved'] ?: '–' }}</td>
                        @if ($hasThroughput)<td class="r mono" style="color: var(--accent);">{{ $q['throughput'] !== null ? $q['throughput'].'/s' : '–' }}</td>@endif
                        @if ($hasWait)<td class="r mono muted">{{ $q['wait'] !== null ? Format::num($q['wait'], 2).'s' : '–' }}</td>@endif
                        <td><x-torque::viz.mini-bars :data="$q['history']" :w="140" :h="30" :color="$q['pending'] > 300 ? 'var(--warn)' : 'var(--accent)'"/></td>
                        <td class="r"><x-torque::icon name="chevR" :size="15"/></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty mono">no streams configured</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-torque::shell>
