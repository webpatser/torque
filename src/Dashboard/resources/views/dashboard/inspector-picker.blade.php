@php
    use Webpatser\Torque\Dashboard\Support\Format;

    $runningJobs = collect($running)->filter(fn ($j) => in_array($j['status'], ['running', 'retrying'], true))->take(5);
@endphp
<x-torque::shell title="Job inspector" crumb="per-job event stream" active="inspector"
    :dead-count="$deadCount" :worker-count="$workerCount" :poll-interval="$pollInterval">

    <div class="card" style="max-width: 720px; margin: 8px auto;">
        <div class="card-head">
            <x-torque::icon name="inspect" :size="16"/>
            <h3>Job inspector</h3>
            <span class="sub">select a job to trace its event stream</span>
        </div>
        <div class="card-pad">
            <div class="eyebrow mb12">Currently running</div>
            <div class="col" style="gap: 6px;">
                @forelse ($runningJobs as $j)
                    <a href="{{ route('torque.inspector.job', ['uuid' => $j['id']]) }}" wire:navigate class="row between"
                        style="padding: 10px 12px; border-radius: var(--r-md); border: 1px solid var(--border); cursor: pointer; color: inherit; text-decoration: none;">
                        <div class="row gap12">
                            <x-torque::badge :status="$j['status']" tiny/>
                            <x-torque::jobname :ns="$j['ns']" :cls="$j['cls']"/>
                        </div>
                        <span class="mono faint" style="font-size: 11px;">{{ \Illuminate\Support\Str::substr($j['id'], 0, 13) }} <x-torque::icon name="chevR" :size="13"/></span>
                    </a>
                @empty
                    <div class="empty mono" style="padding: 20px;">no running jobs</div>
                @endforelse
            </div>

            <div class="eyebrow mb12 mt24">Recent failures</div>
            <div class="col" style="gap: 6px;">
                @forelse (collect($failed)->take(4) as $j)
                    <a href="{{ route('torque.inspector.job', ['uuid' => $j['id']]) }}" wire:navigate class="row between"
                        style="padding: 10px 12px; border-radius: var(--r-md); border: 1px solid var(--border); cursor: pointer; color: inherit; text-decoration: none;">
                        <div class="row gap12">
                            <x-torque::badge status="failed" tiny/>
                            <x-torque::jobname :ns="$j['ns']" :cls="$j['cls']"/>
                        </div>
                        <span class="mono faint" style="font-size: 11px;">{{ Format::ago($j['failedAt']) }} <x-torque::icon name="chevR" :size="13"/></span>
                    </a>
                @empty
                    <div class="empty mono" style="padding: 20px;">no recent failures</div>
                @endforelse
            </div>
        </div>
    </div>
</x-torque::shell>
