<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Webpatser\Torque\Console\TorqueMonitorCommand;

use function Fledge\Async\Redis\createRedisClient;

/*
|--------------------------------------------------------------------------
| Stream Keys in the Flush and Monitor Commands
|--------------------------------------------------------------------------
|
| Workers and producers use the hash-tagged key in cluster mode, so that is the
| key these commands have to reach. On a single node `q` and `{q}` are different
| keys too, which makes a command aimed at the wrong one visible.
|
| Requires a running Redis instance.
|
*/

beforeEach(function () {
    $this->prefix = 'torque-cluster-keys-'.bin2hex(random_bytes(4)).':';
    $this->redis = createRedisClient(config('torque.redis.uri'));

    config(['torque.redis.prefix' => $this->prefix]);
});

afterEach(function () {
    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('flushes the hash-tagged stream in cluster mode', function (string $configured, array $arguments) {
    config(['torque.redis.cluster' => true, 'torque.streams' => [$configured => []]]);

    $this->redis->execute('XADD', $this->prefix.'{capped}', '*', 'payload', 'job');
    $this->redis->execute('ZADD', $this->prefix.'{capped}:delayed', '1', 'job');

    expect(Artisan::call('torque:flush', $arguments))->toBe(0)
        ->and(Artisan::output())->toContain('1 queued, 1 delayed')
        ->and((int) $this->redis->execute('XLEN', $this->prefix.'{capped}'))->toBe(0)
        ->and((int) $this->redis->execute('ZCARD', $this->prefix.'{capped}:delayed'))->toBe(0);
})->with([
    'plain name in config' => ['capped', []],
    'tagged name in config' => ['{capped}', []],
    'plain name in --queues' => ['other', ['--queues' => 'capped']],
    'tagged name in --queues' => ['other', ['--queues' => '{capped}']],
]);

it('flushes the plain stream without cluster mode and leaves a tagged key alone', function () {
    config(['torque.redis.cluster' => false, 'torque.streams' => ['capped' => []]]);

    $this->redis->execute('XADD', $this->prefix.'capped', '*', 'payload', 'job');
    $this->redis->execute('XADD', $this->prefix.'{capped}', '*', 'payload', 'unrelated');

    expect(Artisan::call('torque:flush'))->toBe(0)
        ->and((int) $this->redis->execute('XLEN', $this->prefix.'capped'))->toBe(0)
        ->and((int) $this->redis->execute('XLEN', $this->prefix.'{capped}'))->toBe(1);
});

/**
 * The queue table torque:monitor draws: the command itself loops until SIGINT.
 *
 * @return list<string>
 */
function torque_monitor_queue_lines(): array
{
    $command = new TorqueMonitorCommand;

    (new ReflectionMethod($command, 'renderQueues'))->invoke($command, config('torque'), config('torque.redis.uri'));

    return (new ReflectionProperty($command, 'lines'))->getValue($command);
}

it('monitors the stream workers use', function (bool $cluster, string $configured, string $streamName) {
    config(['torque.redis.cluster' => $cluster, 'torque.streams' => [$configured => []]]);

    $streamKey = $this->prefix.$streamName;
    $group = config('torque.consumer_group');

    $this->redis->execute('XGROUP', 'CREATE', $streamKey, $group, '$', 'MKSTREAM');
    $this->redis->execute('XADD', $streamKey, '*', 'payload', 'claimed');
    $this->redis->execute('XADD', $streamKey, '*', 'payload', 'waiting');
    $this->redis->execute('XREADGROUP', 'GROUP', $group, 'consumer', 'COUNT', '1', 'STREAMS', $streamKey, '>');
    $this->redis->execute('ZADD', $streamKey.':delayed', '1', 'job');

    expect(torque_monitor_queue_lines())->toContain(sprintf('  │ %-18s │ %6s │ %7s │ %7s │', $configured, '2', '1', '1'));
})->with([
    'cluster, plain name' => [true, 'capped', '{capped}'],
    'cluster, tagged name' => [true, '{capped}', '{capped}'],
    'no cluster' => [false, 'capped', 'capped'],
]);
