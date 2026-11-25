<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Dashboard\Data\OverviewData;
use Webpatser\Torque\Dashboard\Data\WorkersData;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * The workers page is rendered for real (route, gate, Livewire, Blade) against
 * a seeded Redis, so the per-host badges and group headings are asserted on
 * the HTML and not only on the WorkersData read model.
 */
beforeEach(function () {
    $this->workersViewPrefix = 'torque-workers-view-'.bin2hex(random_bytes(4)).':';

    config()->set('torque.dashboard.enabled', true);
    config()->set('torque.redis.uri', torqueRedisUri());
    config()->set('torque.redis.prefix', $this->workersViewPrefix);

    foreach ([MetricsPublisher::class, WorkersData::class, OverviewData::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    TorqueDashboardController::register();
    Gate::define('viewTorque', fn ($user): bool => true);

    try {
        torqueRedis();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

afterEach(function () {
    $redis = torqueRedis();
    $cursor = '0';

    do {
        [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $this->workersViewPrefix.'*', 'COUNT', '100');

        foreach ($keys as $key) {
            $redis->execute('DEL', $key);
        }
    } while ($cursor !== '0');
});

/**
 * @return array<string, string> the heading part of each host card, keyed by host
 */
function workersViewHostCards(string $html): array
{
    $cards = [];

    foreach (array_slice(explode('<span class="mono" style="font-size: 15px; font-weight: 700;">', $html), 1) as $part) {
        $cards[Str::before($part, '</span>')] = Str::before($part, 'throughput');
    }

    return $cards;
}

it('renders node state badges and group headings per host', function () {
    $prefix = $this->workersViewPrefix;
    $redis = torqueRedis();

    $workers = [
        'alpha-101-aaaaaaaa' => ['alpha', '101', 'imports'],
        'alpha-102-aaaaaaaa' => ['alpha', '102', 'imports'],
        'alpha-103-aaaaaaaa' => ['alpha', '103', 'mail'],
        'beta-201-aaaaaaaa' => ['beta', '201', 'default'],
        'gamma-301-aaaaaaaa' => ['gamma', '301', 'default'],
    ];

    foreach ($workers as $id => [$host, $pid, $group]) {
        $redis->execute('HSET', $prefix.'worker:'.$id,
            'pid', $pid, 'host', $host, 'group', $group, 'total_slots', '4', 'active_slots', '1');
    }

    $registry = new MasterRegistry($redis, $prefix);
    $registry->publish('alpha-101-aaaaaaaa', [
        'node' => 'alpha', 'pid' => 101, 'started_at' => time(), 'version' => '2.5.0',
        'supervised' => true, 'queues' => ['imports', 'emails'], 'workers' => 3, 'slots' => 4, 'state' => 'running',
    ]);
    $registry->publish('beta-201-aaaaaaaa', [
        'node' => 'beta', 'pid' => 201, 'started_at' => time(), 'version' => '2.4.0',
        'supervised' => false, 'queues' => ['default'], 'workers' => 1, 'slots' => 4, 'state' => 'draining',
    ]);
    (new PauseScopes($redis, $prefix))->pauseNode('alpha');

    $html = $this->actingAs(torqueTestUser())->get('/torque/workers')->assertOk()->getContent();
    $cards = workersViewHostCards($html);

    expect(array_keys($cards))->toContain('alpha', 'beta', 'gamma');

    // alpha: paused, version, supervised, both group headings with counts.
    expect($cards['alpha'])->toContain('>paused<')
        ->and($cards['alpha'])->not->toContain('>draining<')
        ->and($cards['alpha'])->toContain('v2.5.0')
        ->and($cards['alpha'])->toContain('>supervised<')
        ->and($cards['alpha'])->not->toContain('unsupervised')
        ->and($cards['alpha'])->toContain('imports · 2 workers')
        ->and($cards['alpha'])->toContain('mail · 1 worker<');

    // beta: draining, unsupervised, only the default group so no heading.
    expect($cards['beta'])->toContain('>draining<')
        ->and($cards['beta'])->not->toContain('>paused<')
        ->and($cards['beta'])->toContain('v2.4.0')
        ->and($cards['beta'])->toContain('>unsupervised<')
        ->and($cards['beta'])->not->toContain('default ·');

    // gamma: no master record, so none of the node-state labels.
    expect($cards['gamma'])->toContain('>active<')
        ->and($cards['gamma'])->not->toContain('>paused<')
        ->and($cards['gamma'])->not->toContain('>draining<')
        ->and($cards['gamma'])->not->toContain('supervised')
        ->and($cards['gamma'])->not->toMatch('/>v\d/')
        ->and($cards['gamma'])->not->toContain('default ·');
});
