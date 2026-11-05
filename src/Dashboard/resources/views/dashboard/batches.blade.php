@php
    use Webpatser\Torque\Dashboard\Support\Format;

    $base = route('torque.batches');
@endphp
<x-torque::shell title="Batches" crumb="bus:batches" active="batches"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval" :range="null">

    @if ($page['error'])
        <div class="card">
            <div class="card-pad">
                <div class="empty mono">batches could not be read</div>
                <p class="mono faint" style="font-size: 12px; text-align: center; margin-top: 8px;">
                    The error was reported. See the application log for the cause.
                </p>
            </div>
        </div>
    @elseif (! $page['available'])
        <div class="card">
            <div class="card-pad">
                <div class="empty mono">batches are not set up</div>
                <p class="mono faint" style="font-size: 12px; text-align: center; margin-top: 8px;">
                    Laravel could not read the batch table. Create it with
                    <code>php artisan make:queue-batches-table</code> then <code>php artisan migrate</code>
                    (or check <code>queue.batching</code> in config/queue.php).
                </p>
            </div>
        </div>
    @else
        @if ($detail['unavailable'] ?? false)
            <div class="card mb16"><div class="card-pad"><div class="empty mono">batch {{ $selectedId }} could not be read</div></div></div>
        @elseif ($detail)
            <div class="card mb16">
                <div class="card-head">
                    <h3>{{ $detail['name'] !== '' ? $detail['name'] : 'Batch' }}</h3>
                    <div class="grow"></div>
                    <a class="btn sm" href="{{ $base }}{{ $before ? '?before='.urlencode($before) : '' }}">Close</a>
                </div>
                <div class="card-pad">
                    <div class="mono" style="font-size: 12px;">
                        {{ $detail['id'] }} · {{ $detail['progress'] }}% · {{ $detail['pending'] }} pending · {{ $detail['failed'] }} failed · {{ $detail['total'] }} total
                        @if ($detail['cancelled']) · cancelled @endif
                    </div>
                    <div class="mono faint" style="font-size: 11.5px; margin-top: 4px;">
                        created {{ date('Y-m-d H:i:s', $detail['createdAt']) }}
                        · {{ $detail['finishedAt'] ? 'finished '.date('Y-m-d H:i:s', $detail['finishedAt']) : 'not finished' }}
                    </div>
                    <h4 style="margin-top: 14px;">Failed job ids</h4>
                    {{-- This panel re-renders on every poll, so the list is capped. --}}
                    @forelse (array_slice($detail['failedJobIds'], 0, 100) as $failedId)
                        <div class="mono" style="font-size: 12px; color: var(--bad);" wire:key="failed-{{ $failedId }}">{{ $failedId }}</div>
                    @empty
                        <div class="mono faint" style="font-size: 12px;">no failed jobs</div>
                    @endforelse
                    @if (count($detail['failedJobIds']) > 100)
                        <div class="mono faint" style="font-size: 12px; margin-top: 4px;">{{ count($detail['failedJobIds']) - 100 }} more failed job ids not shown</div>
                    @endif
                </div>
            </div>
        @elseif ($selectedId)
            <div class="card mb16"><div class="card-pad"><div class="empty mono">batch {{ $selectedId }} not found</div></div></div>
        @endif

        <div class="card">
            <div class="card-head"><h3>Batches</h3></div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Id</th>
                            <th>Name</th>
                            <th class="r">Progress</th>
                            <th class="r">Pending</th>
                            <th class="r">Failed</th>
                            <th class="r">Total</th>
                            <th class="r">Created</th>
                            <th class="r">Finished</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($page['batches'] as $b)
                            <tr wire:key="batch-{{ $b['id'] }}">
                                <td class="mono"><a href="{{ $base }}?batch={{ urlencode($b['id']) }}{{ $before ? '&before='.urlencode($before) : '' }}">{{ $b['id'] }}</a></td>
                                <td>{{ $b['name'] }}</td>
                                <td class="r mono">{{ $b['progress'] }}%</td>
                                <td class="r mono">{{ $b['pending'] }}</td>
                                <td class="r mono" @if ($b['failed'] > 0) style="color: var(--bad);" @endif>{{ $b['failed'] }}</td>
                                <td class="r mono">{{ $b['total'] }}</td>
                                <td class="r mono faint" style="font-size: 11.5px;">{{ Format::ago($b['createdAt'] * 1000) }}</td>
                                <td class="r mono faint" style="font-size: 11.5px;">{{ $b['finishedAt'] ? Format::ago($b['finishedAt'] * 1000) : '–' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><div class="empty mono">no batches yet</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($before || $page['next'])
                <div class="row between" style="padding: 12px 16px; border-top: 1px solid var(--border);">
                    <div>@if ($before)<a class="btn sm" href="{{ $base }}">Newest</a>@endif</div>
                    <div>@if ($page['next'])<a class="btn sm" href="{{ $base }}?before={{ urlencode($page['next']) }}">Older</a>@endif</div>
                </div>
            @endif
        </div>
    @endif
</x-torque::shell>
