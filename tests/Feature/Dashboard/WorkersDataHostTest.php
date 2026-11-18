<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
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
