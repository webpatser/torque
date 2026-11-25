<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisException;
use Webpatser\Torque\Dashboard\Data\WorkersData;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * @param  array<string, array<string, string>>  $workers  worker id => extra hash fields
 * @return array<string, array<string, mixed>> hosts keyed by host name
 */
function workersByGroupFixture(string $prefix, array $workers): array
{
    $redis = torqueRedis();

    foreach ($workers as $id => $fields) {
        $args = [];

        foreach (['total_slots' => '4', 'active_slots' => '1', ...$fields] as $k => $v) {
            $args[] = $k;
            $args[] = $v;
        }

        $redis->execute('HSET', $prefix.'worker:'.$id, ...$args);
    }

    $publisher = new MetricsPublisher(redisUri: torqueRedisUri(), prefix: $prefix);

    return collect((new WorkersData($publisher, torqueRedisUri(), $prefix))->get('1h')['hosts'])->keyBy('host')->all();
}

function cleanWorkerGroupKeys(string $prefix): void
{
    $redis = torqueRedis();
    $cursor = '0';

    do {
        [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');

        foreach ($keys as $key) {
            $redis->execute('DEL', $key);
        }
    } while ($cursor !== '0');
}

it('groups a host workers by group with default first and counts', function () {
    $prefix = 'torque-workers-group-'.bin2hex(random_bytes(4)).':';

    try {
        $hosts = workersByGroupFixture($prefix, [
            'node-a-1-aaaaaaaa' => ['host' => 'node-a', 'pid' => '1', 'group' => 'mail'],
            'node-a-2-aaaaaaaa' => ['host' => 'node-a', 'pid' => '2', 'group' => 'mail'],
            'node-a-3-aaaaaaaa' => ['host' => 'node-a', 'pid' => '3'],
            'node-a-4-aaaaaaaa' => ['host' => 'node-a', 'pid' => '4', 'group' => 'default'],
            'node-a-5-aaaaaaaa' => ['host' => 'node-a', 'pid' => '5', 'group' => 'bulk'],
        ]);

        $groups = $hosts['node-a']['groups'];

        expect($hosts['node-a']['workers'])->toHaveCount(5)
            ->and(array_column($groups, 'name'))->toBe(['default', 'bulk', 'mail'])
            ->and(array_column($groups, 'count'))->toBe([2, 1, 2])
            ->and($groups[0]['workers'])->toHaveCount(2);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanWorkerGroupKeys($prefix);
    }
});

it('puts a worker without a group field and an empty one in default', function () {
    $prefix = 'torque-workers-group-'.bin2hex(random_bytes(4)).':';

    try {
        $hosts = workersByGroupFixture($prefix, [
            'node-b-1-aaaaaaaa' => ['host' => 'node-b', 'pid' => '1'],
            'node-b-2-aaaaaaaa' => ['host' => 'node-b', 'pid' => '2', 'group' => ''],
        ]);

        expect($hosts['node-b']['groups'])->toHaveCount(1)
            ->and($hosts['node-b']['groups'][0]['name'])->toBe('default')
            ->and($hosts['node-b']['groups'][0]['count'])->toBe(2);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanWorkerGroupKeys($prefix);
    }
});

it('keeps the same group name on two hosts separate', function () {
    $prefix = 'torque-workers-group-'.bin2hex(random_bytes(4)).':';

    try {
        $hosts = workersByGroupFixture($prefix, [
            'node-c-1-aaaaaaaa' => ['host' => 'node-c', 'pid' => '1', 'group' => 'mail'],
            'node-c-2-aaaaaaaa' => ['host' => 'node-c', 'pid' => '2', 'group' => 'mail'],
            'node-d-1-aaaaaaaa' => ['host' => 'node-d', 'pid' => '1', 'group' => 'mail'],
        ]);

        expect($hosts['node-c']['groups'])->toHaveCount(1)
            ->and($hosts['node-c']['groups'][0]['count'])->toBe(2)
            ->and($hosts['node-d']['groups'])->toHaveCount(1)
            ->and($hosts['node-d']['groups'][0]['count'])->toBe(1);
    } catch (RedisException $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    } finally {
        cleanWorkerGroupKeys($prefix);
    }
});
