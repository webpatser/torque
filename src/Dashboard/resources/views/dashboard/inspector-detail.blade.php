@php
    use Webpatser\Torque\Dashboard\Support\Format;

    $shortId = \Illuminate\Support\Str::substr($job['id'], 0, 8);
@endphp
<x-torque::shell title="Job inspector" crumb="per-job event stream" active="inspector"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval">

    {{-- header --}}
    <div class="card mb16">
        <div class="card-pad" style="display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center;">
            <div class="col" style="gap: 8px; min-width: 0;">
                <div class="row gap12 wrap">
                    <a href="{{ route('torque.feed') }}" wire:navigate class="btn sm"><x-torque::icon name="chevL" :size="13"/> Feed</a>
                    <span style="font-size: 18px; font-weight: 600; letter-spacing: -0.01em;" class="mono">{{ $job['cls'] }}</span>
                    <x-torque::badge :status="$status"/>
                    @if ($isLive)
                        <span class="badge s-running tiny"><span class="livedot" style="width: 6px; height: 6px;"></span>tailing</span>
                    @endif
                </div>
                <div class="row gap16 wrap mono faint" style="font-size: 11.5px;">
                    <span>{{ $job['ns'] }}{{ $job['cls'] }}</span>
                    <span style="color: var(--text-dim);">{{ $job['id'] }}</span>
                </div>
            </div>
            <div class="row gap8" x-data="{ copied: false }">
                @if ($canRetry)
                    <button type="button" class="btn primary sm" wire:click="retry('{{ $retryId }}')">
                        <x-torque::icon name="retry" :size="14"/> Retry
                    </button>
                @endif
                <button type="button" class="btn sm" @click="navigator.clipboard?.writeText('{{ $job['id'] }}'); copied = true; setTimeout(() => copied = false, 1200)">
                    <x-torque::icon name="copy" :size="14"/>
                    <span x-text="copied ? 'Copied' : 'Copy UUID'">Copy UUID</span>
                </button>
            </div>
        </div>
        <hr class="hr">
        <div class="card-pad row gap20 wrap" style="padding: 12px 20px;">
            @php
                $metas = [
                    ['queue', $job['queue'] ?: '–'],
                    ['connection', $job['connection'] ?? 'torque'],
                    ['attempt', $attemptVal.' / '.($job['maxTries'] ?? 3)],
                    ['events', count($events)],
                    ['started', $events[0]['ts'] ?? null ? Format::hms($events[0]['ts']) : '–'],
                    ['worker', $lastWorker ?: ($job['worker'] ?? '–')],
                ];
            @endphp
            @foreach ($metas as [$k, $v])
                <div class="col" style="gap: 2px;">
                    <span class="mono faint" style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em;">{{ $k }}</span>
                    <span class="mono" style="font-size: 12.5px; font-weight: 600;">{{ $v }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid" style="grid-template-columns: minmax(0,1.25fr) minmax(0,1fr); align-items: start;">
        {{-- timeline: per-job event stream --}}
        <div class="card">
            <div class="card-head">
                <h3>Event stream</h3>
                <span class="sub">per-job Redis Stream · torque:job:{{ $shortId }}</span>
                <div class="grow"></div>
                @if ($isLive)
                    <span class="row gap6"><span class="livedot"></span><span class="mono faint" style="font-size: 11px;">live</span></span>
                @endif
            </div>
            <div class="card-pad" style="max-height: 560px; overflow-y: auto;">
                @if (count($events))
                    <x-torque::timeline :events="$events" :live="$isLive" :new-count="$isLive ? 1 : 0"/>
                @else
                    <div class="empty mono">no event stream for this job</div>
                @endif
            </div>
        </div>

        {{-- right: payload / exception / tail --}}
        <div class="card" style="position: sticky; top: 0;">
            <div class="card-head" style="padding: 9px 12px;">
                <div class="seg">
                    <button type="button" wire:click="setTab('payload')" @class(['on' => $tab === 'payload'])>payload</button>
                    <button type="button" wire:click="setTab('exception')" @class(['on' => $tab === 'exception']) @style(['color: var(--warn)' => (bool) $exception])>exception{{ $exception ? ' !' : '' }}</button>
                    <button type="button" wire:click="setTab('tail')" @class(['on' => $tab === 'tail'])>raw tail</button>
                </div>
            </div>
            <div class="card-pad">
                @if ($tab === 'payload')
                    @if ($payload)
                        <div class="code" style="max-height: 520px; overflow: auto;">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
                    @else
                        <div class="empty mono">no payload available</div>
                    @endif
                @elseif ($tab === 'exception')
                    @if ($exception)
                        <div>
                            <div class="row gap8 mb12">
                                <x-torque::icon name="x" :size="15" :stroke="2.2"/>
                                <span class="mono" style="color: var(--warn); font-weight: 600; font-size: 12.5px;">{{ $exception }}</span>
                            </div>
                            @if ($exceptionMsg)
                                <div class="code" style="color: var(--text); white-space: pre-wrap;">{{ $exceptionMsg }}</div>
                            @endif
                            @if ($trace)
                                <div class="eyebrow mt16 mb12">Stack trace</div>
                                <div class="code" style="max-height: 280px; overflow: auto; color: var(--text-faint); white-space: pre-wrap;">{{ $trace }}</div>
                            @endif
                        </div>
                    @else
                        <div class="empty mono">no exceptions recorded (clean run)</div>
                    @endif
                @elseif ($tab === 'tail')
                    <div class="term" style="max-height: 520px;">
                        @forelse ($events as $ev)
                            @php
                                $d = $ev['data'] ?? [];
                                $msg = $d['message'] ?? $d['exception_class'] ?? $d['worker'] ?? (! empty($d['memory_bytes']) ? 'memory='.number_format($d['memory_bytes'] / 1048576, 1).'MB' : '') ?: '';
                                $evCls = $ev['type'] === 'dead_lettered' ? 'failed' : $ev['type'];
                            @endphp
                            <div class="ln">
                                <span class="t">{{ Format::hms($ev['ts']) }}</span>
                                <span class="ev {{ $evCls }}">{{ $ev['type'] }}</span>
                                <span class="msg">{{ $msg }}</span>
                            </div>
                        @empty
                            <div class="empty mono">no events</div>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-torque::shell>
