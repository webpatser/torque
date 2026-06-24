@php
    use Webpatser\Torque\Dashboard\Support\Format;

    $selCount = count($selected);
    $from = $total ? $page * $perPage + 1 : 0;
    $to = min($total, $page * $perPage + $perPage);
@endphp
<x-torque::shell title="Dead-letter" crumb="torque:stream:dead-letter" active="dead"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval">

    <div class="card mb16" style="border-color: {{ $deadCount ? 'color-mix(in oklab, var(--bad) 22%, var(--border))' : 'var(--border)' }};">
        <div class="card-pad row between wrap gap16">
            <div class="row gap16">
                <div class="row gap8">
                    <x-torque::icon name="dead" :size="20"/>
                    <span style="font-size: 17px; font-weight: 600;">Dead-letter stream</span>
                </div>
                <span class="mono faint" style="font-size: 11.5px;">torque:stream:dead-letter · jobs that exhausted all retries</span>
            </div>
            <div class="row gap8">
                <span class="mono" style="font-size: 13px; color: var(--bad); font-weight: 600;">{{ $deadCount }} dead</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>Failed jobs</h3>
            <div class="grow"></div>
            <div class="row gap8">
                <div class="row gap6" style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--r-md); padding: 0 10px; height: 32px;">
                    <x-torque::icon name="search" :size="14"/>
                    <input wire:model.live.debounce.300ms="search" placeholder="filter by class or exception…" class="mono"
                        style="border: none; background: transparent; color: var(--text); outline: none; font-size: 12px; width: 200px;">
                </div>
                @if ($selCount > 0)
                    <button type="button" class="btn sm primary" wire:click="retrySelected" wire:loading.attr="disabled">
                        <x-torque::icon name="retry" :size="13"/> Retry {{ $selCount }}
                    </button>
                    <button type="button" class="btn sm danger" wire:click="purgeSelected" wire:loading.attr="disabled">
                        <x-torque::icon name="trash" :size="13"/> Delete {{ $selCount }}
                    </button>
                @endif
            </div>
        </div>
        <table class="tbl">
            <thead>
                <tr>
                    <th style="width: 30px;"></th>
                    <th>Job</th>
                    <th>Exception</th>
                    <th class="r">Tries</th>
                    <th class="r">Failed</th>
                    <th style="width: 80px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shown as $j)
                    @php $isSel = in_array($j['id'], $selected, true); @endphp
                    <tr class="clickable">
                        <td wire:click="toggleSelect('{{ $j['id'] }}')">
                            <span style="width: 16px; height: 16px; border-radius: 4px; border: 1.5px solid {{ $isSel ? 'var(--accent)' : 'var(--border-2)' }}; background: {{ $isSel ? 'var(--accent)' : 'transparent' }}; display: grid; place-items: center;">
                                @if ($isSel)
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="var(--accent-ink)" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                @endif
                            </span>
                        </td>
                        <td @click="Livewire.navigate('{{ route('torque.inspector.job', ['uuid' => $j['id']]) }}')">
                            <x-torque::jobname :ns="$j['ns']" :cls="$j['cls']"/>
                            <div class="mono faint" style="font-size: 10px; margin-top: 2px;">
                                {{ \Illuminate\Support\Str::substr($j['id'], 0, 8) }} · {{ $j['queue'] }}@if ($j['worker']) · {{ $j['worker'] }}@endif
                            </div>
                        </td>
                        <td @click="Livewire.navigate('{{ route('torque.inspector.job', ['uuid' => $j['id']]) }}')" style="max-width: 320px;">
                            <div class="mono" style="font-size: 11.5px; color: var(--warn);">{{ $j['exception'] }}</div>
                            <div class="mono faint" style="font-size: 11px; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $j['message'] }}</div>
                        </td>
                        <td class="r mono"><span class="badge s-failed tiny">{{ $j['attempts'] ?? '–' }}</span></td>
                        <td class="r mono faint" style="font-size: 11.5px;">{{ Format::ago($j['failedAt']) }}</td>
                        <td class="r">
                            <div class="row gap6" style="justify-content: flex-end;">
                                <button type="button" class="icon-btn" style="width: 28px; height: 28px;" title="Retry" wire:click="retryOne('{{ $j['id'] }}')" wire:loading.attr="disabled">
                                    <x-torque::icon name="retry" :size="14"/>
                                </button>
                                <button type="button" class="icon-btn" style="width: 28px; height: 28px;" title="Delete" wire:click="purgeOne('{{ $j['id'] }}')" wire:loading.attr="disabled">
                                    <x-torque::icon name="trash" :size="14"/>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty mono">{{ $search ? 'no matches' : 'dead-letter stream is empty 🎉' }}</div></td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($total > $perPage)
            <div class="row between" style="padding: 12px 16px; border-top: 1px solid var(--border);">
                <span class="mono faint" style="font-size: 11px;">{{ $from }}–{{ $to }} of {{ $total }} · cursor-paginated</span>
                <div class="row gap8">
                    <button type="button" class="btn sm" wire:click="$set('page', {{ max(0, $page - 1) }})" @disabled($page === 0)>
                        <x-torque::icon name="chevL" :size="13"/> Prev
                    </button>
                    <button type="button" class="btn sm" wire:click="$set('page', {{ $page + 1 }})" @disabled(($page + 1) * $perPage >= $total)>
                        Next <x-torque::icon name="chevR" :size="13"/>
                    </button>
                </div>
            </div>
        @endif
    </div>
</x-torque::shell>
