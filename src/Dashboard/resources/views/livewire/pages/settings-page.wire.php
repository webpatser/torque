<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Attributes\Computed;
use Webpatser\Torque\Dashboard\Concerns\AuthorizesTorqueAccess;

new class extends Component {
    use AuthorizesTorqueAccess;

    #[Computed]
    public function config(): array
    {
        $config = config('torque');

        return [
            'General' => [
                'Workers' => $config['workers'] ?? 4,
                'Coroutines per worker' => $config['coroutines_per_worker'] ?? 50,
                'Max jobs per worker' => number_format($config['max_jobs_per_worker'] ?? 10000),
                'Max worker lifetime' => ($config['max_worker_lifetime'] ?? 3600) . 's',
                'Block timeout' => ($config['block_for'] ?? 2000) . 'ms',
            ],
            'Redis' => [
                'URI' => preg_replace('/\/\/.*@/', '//*****@', $config['redis']['uri'] ?? 'redis://127.0.0.1:6379'),
                'Prefix' => $config['redis']['prefix'] ?? 'torque:',
                'Cluster' => ($config['redis']['cluster'] ?? false) ? 'Yes' : 'No',
                'Consumer group' => $config['consumer_group'] ?? 'torque',
            ],
            'Connection Pools' => [
                'MySQL pool size' => $config['pools']['mysql']['size'] ?? 20,
                'Redis pool size' => $config['pools']['redis']['size'] ?? 30,
                'HTTP pool size' => $config['pools']['http']['size'] ?? 15,
            ],
            'Autoscaling' => [
                'Enabled' => ($config['autoscale']['enabled'] ?? false) ? 'Yes' : 'No',
                'Min workers' => $config['autoscale']['min_workers'] ?? 2,
                'Max workers' => $config['autoscale']['max_workers'] ?? 8,
                'Scale-up threshold' => (($config['autoscale']['scale_up_threshold'] ?? 0.85) * 100) . '%',
                'Scale-down threshold' => (($config['autoscale']['scale_down_threshold'] ?? 0.20) * 100) . '%',
                'Cooldown' => ($config['autoscale']['cooldown'] ?? 30) . 's',
            ],
            'Job Streams' => [
                'Enabled' => ($config['job_streams']['enabled'] ?? true) ? 'Yes' : 'No',
                'TTL' => ($config['job_streams']['ttl'] ?? 300) . 's',
                'Max events' => number_format($config['job_streams']['max_events'] ?? 1000),
            ],
            'Dead Letter' => [
                'TTL' => number_format(($config['dead_letter']['ttl'] ?? 604800) / 86400, 0) . ' days',
            ],
            'Dashboard' => [
                'Path' => '/' . ($config['dashboard']['path'] ?? 'torque'),
                'Middleware' => implode(', ', $config['dashboard']['middleware'] ?? ['web', 'auth']),
                'Default poll interval' => ($config['dashboard']['default_poll_interval'] ?? 2000) . 'ms',
            ],
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="lg">Settings</flux:heading>
        <flux:text class="text-sm mt-1">
            Current Torque configuration (read-only from config/torque.php)
        </flux:text>
    </div>

    <div class="space-y-6">
        @foreach($this->config as $section => $values)
            <flux:card>
                <flux:heading size="sm" class="mb-3">{{ $section }}</flux:heading>
                <dl class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @foreach($values as $key => $value)
                        <div class="flex justify-between py-2 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ $key }}</dt>
                            <dd class="font-mono text-xs text-zinc-900 dark:text-zinc-100">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </flux:card>
        @endforeach
    </div>

    {{-- Streams configuration --}}
    <div class="mt-6 space-y-3">
        <flux:heading size="sm">Configured Streams</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Priority</flux:table.column>
                <flux:table.column>Retry After</flux:table.column>
                <flux:table.column>Max Retries</flux:table.column>
                <flux:table.column>Backoff</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach(config('torque.streams', []) as $name => $stream)
                    <flux:table.row>
                        <flux:table.cell class="font-medium">{{ $name }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $stream['priority'] ?? 0 }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $stream['retry_after'] ?? 60 }}s</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $stream['max_retries'] ?? 3 }}</flux:table.cell>
                        <flux:table.cell>{{ $stream['backoff'] ?? 'exponential' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</div>
