<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Dashboard\Data\WorkersData;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * A host whose name needs cleaning appears once on the workers screen: its
 * live heartbeat (raw name) and its index entry (normalised name) are the same
 * machine, and its history must be found under the stored name.
 */
it('shows a host with an unsafe name as one row with its history', function () {
    $prefix = 'torque-workers-host-test:';
    $publisher = new MetricsPublisher(redisUri: torqueRedisUri(), prefix: $prefix);
    $redis = torqueRedis();

    try {
        $redis->execute('HSET', $prefix.'worker:w1',
            'pid', '4242',
            'host', 'web 01',
            'total_slots', '10',
            'active_slots', '2',
            'jobs_processed', '7',
            'jobs_failed', '0',
        );

        $publisher->recordHostOutcomes(['web 01' => [5, 1]]);

        $hosts = (new WorkersData($publisher))->get('1h')['hosts'];

        expect($hosts)->toHaveCount(1)
            ->and($hosts[0]['status'])->toBe('active')
            ->and($hosts[0]['processed'])->toBe(5)
            ->and($hosts[0]['failed'])->toBe(1)
            ->and($hosts[0]['lastSeen'])->not->toBeNull()
            ->and($hosts[0]['workers'])->toHaveCount(1);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        $cursor = '0';

        do {
            [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');

            foreach ($keys as $key) {
                $redis->execute('DEL', $key);
            }
        } while ($cursor !== '0');
    }
});

it('shows node pause, drain, version and supervision per host', function () {
    $prefix = 'torque-workers-node-'.bin2hex(random_bytes(4)).':';
    $publisher = new MetricsPublisher(redisUri: torqueRedisUri(), prefix: $prefix);
    $redis = torqueRedis();

    try {
        foreach (['node-a' => 111, 'node-b' => 222, 'node-c' => 333] as $node => $pid) {
            $redis->execute('HSET', $prefix.'worker:'.$node.'-'.$pid.'-aaaaaaaa',
                'pid', (string) $pid, 'host', $node, 'total_slots', '4', 'active_slots', '1');
        }

        $registry = new MasterRegistry($redis, $prefix);
        $registry->publish('node-a-111-aaaaaaaa', [
            'node' => 'node-a', 'pid' => 111, 'started_at' => time(), 'version' => '2.5.0',
            'supervised' => true, 'queues' => ['default'], 'workers' => 1, 'slots' => 4, 'state' => 'running',
        ]);
        $registry->publish('node-b-222-aaaaaaaa', [
            'node' => 'node-b', 'pid' => 222, 'started_at' => time(), 'version' => '2.5.0',
            'supervised' => false, 'queues' => ['default'], 'workers' => 1, 'slots' => 4, 'state' => 'draining',
        ]);
        (new PauseScopes($redis, $prefix))->pauseNode('node-a');

        $byHost = collect((new WorkersData($publisher, torqueRedisUri(), $prefix))->get('1h')['hosts'])->keyBy('host');

        expect($byHost['node-a']['paused'])->toBeTrue()
            ->and($byHost['node-a']['draining'])->toBeFalse()
            ->and($byHost['node-a']['version'])->toBe('2.5.0')
            ->and($byHost['node-a']['supervised'])->toBeTrue()
            ->and($byHost['node-b']['paused'])->toBeFalse()
            ->and($byHost['node-b']['draining'])->toBeTrue()
            ->and($byHost['node-b']['supervised'])->toBeFalse()
            ->and($byHost['node-c']['paused'])->toBeFalse()
            ->and($byHost['node-c']['draining'])->toBeFalse()
            ->and($byHost['node-c']['version'])->toBeNull()
            ->and($byHost['node-c']['supervised'])->toBeNull();
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        $cursor = '0';

        do {
            [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');

            foreach ($keys as $key) {
                $redis->execute('DEL', $key);
            }
        } while ($cursor !== '0');
    }
});

it('keeps a gone host with an unsafe name on its stored history', function () {
    $prefix = 'torque-workers-gone-test:';
    $publisher = new MetricsPublisher(redisUri: torqueRedisUri(), prefix: $prefix);
    $redis = torqueRedis();

    try {
        $publisher->recordHostOutcomes(['db:02' => [3, 0]]);

        $hosts = (new WorkersData($publisher))->get('1h')['hosts'];

        expect($hosts)->toHaveCount(1)
            ->and($hosts[0]['status'])->toBe('gone')
            ->and($hosts[0]['processed'])->toBe(3);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        $cursor = '0';

        do {
            [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');

            foreach ($keys as $key) {
                $redis->execute('DEL', $key);
            }
        } while ($cursor !== '0');
    }
});
