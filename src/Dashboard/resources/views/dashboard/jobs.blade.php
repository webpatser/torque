@php
    use Webpatser\Torque\Dashboard\Support\Format;

    $rangeLabels = [
        '1h' => 'last 60 minutes',
        '24h' => 'last 24 hours',
        '7d' => 'last 7 days',
        '90d' => 'last 90 days',
    ];

    $columns = [
        'name' => ['label' => 'Job class', 'align' => ''],
        'throughput' => ['label' => 'Per minute', 'align' => 'r'],
        'runtime' => ['label' => 'Avg runtime', 'align' => 'r'],
    ];
@endphp
<x-torque::shell title="Jobs" crumb="per-class throughput and runtime" active="jobs"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval">

    <div class="grid mb16" style="grid-template-columns: repeat(4,1fr);">
        <x-torque::stat label="Job classes" :value="Format::int($totals['classes'])"/>
        <x-torque::stat label="Processed" :value="Format::int($totals['processed'])"/>
        <x-torque::stat label="Failed" :value="Format::int($totals['failed'])"/>
        <x-torque::stat label="Slowest run" :value="Format::num($totals['slowest'] / 1000, 2)" unit="s"/>
    </div>

    <div class="card">
        <div class="card-head">
            <x-torque::icon name="layers" :size="15"/>
            <h3>Jobs</h3>
            <span class="sub">{{ $rangeLabels[$range] ?? $rangeLabels['1h'] }}</span>
            <div class="grow"></div>
            {{-- Range and sort are server state, so both survive the poll. --}}
            <div class="seg">
                @foreach (array_keys($rangeLabels) as $key)
                    <button type="button" wire:click="setRange('{{ $key }}')" @class(['on' => $range === $key])>{{ $key }}</button>
                @endforeach
            </div>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        @foreach ($columns as $key => $column)
                            <th @class(['r' => $column['align'] === 'r'])>
                                <button type="button" class="th-sort" wire:click="sortBy('{{ $key }}')">
                                    {{ $column['label'] }}
                                    @if ($sort === $key)
                                        <x-torque::icon :name="$direction === 'asc' ? 'arrowUp' : 'arrowDown'" :size="11"/>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        <th class="r">Peak</th>
                        <th class="r">Processed</th>
                        <th class="r">
                            <button type="button" class="th-sort" wire:click="sortBy('failures')">
                                Failed
                                @if ($sort === 'failures')
                                    <x-torque::icon :name="$direction === 'asc' ? 'arrowUp' : 'arrowDown'" :size="11"/>
                                @endif
                            </button>
                        </th>
                        <th style="width: 150px;">Per minute · 60 min</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $job)
                        <tr wire:key="job-{{ md5($job['class']) }}">
                            <td class="job">
                                <x-torque::jobname :ns="$job['ns']" :cls="$job['cls']"/>
                            </td>
                            <td class="r mono" style="color: var(--accent);">{{ Format::num($job['throughput'], 2) }}</td>
                            <td class="r mono">{{ Format::num($job['avgRuntimeMs'], 1) }}<span class="faint">ms</span></td>
                            <td class="r mono muted">{{ Format::num($job['maxRuntimeMs'], 1) }}<span class="faint">ms</span></td>
                            <td class="r mono">{{ Format::int($job['processed']) }}</td>
                            <td class="r">
                                @if ($job['failed'] > 0)
                                    <x-torque::badge :status="$job['failRate'] >= 10 ? 'failed' : 'retrying'"
                                        :label="Format::int($job['failed']).' · '.Format::num($job['failRate'], 1).'%'" tiny/>
                                @else
                                    <span class="mono muted">–</span>
                                @endif
                            </td>
                            <td>
                                <x-torque::viz.mini-bars :data="$job['history']" :w="140" :h="30"
                                    :color="$job['failRate'] >= 10 ? 'var(--bad)' : 'var(--accent)'"/>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty mono">no job metrics recorded yet</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-torque::shell>
