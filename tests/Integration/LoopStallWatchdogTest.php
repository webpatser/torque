<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\LoopStallProbeJob;

use function Fledge\Async\Redis\createRedisClient;

/*
|--------------------------------------------------------------------------
| The master kills a worker whose event loop stopped
|--------------------------------------------------------------------------
|
| A real master and real workers (tests/Fixtures/loop-stall-app.php). Jobs block
| with a real sleep(), which freezes every timer in the worker: T15's timeouts
| cannot fire, so only the master can end it.
|
| Requires a running Redis instance, except for the outage test.
|
*/

const TORQUE_STALL_GROUP = 'loop-stall-group';
const TORQUE_STALL_QUEUE = 'stalled';
const TORQUE_STALL_KILL_LINE = 'Loop stall watchdog';

/**
 * @return array{dir: string, prefix: string, queue: StreamQueue}
 */
function torque_stall_context(): array
{
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-stall-{$token}:";
    $dir = sys_get_temp_dir()."/torque-stall-{$token}";

    mkdir($dir.'/storage/framework', 0777, true);

    file_put_contents($dir.'/artisan', "<?php\n\$runDir = __DIR__;\nrequire ".var_export(dirname(__DIR__).'/Fixtures/loop-stall-app.php', true).";\n");

    $queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: TORQUE_STALL_QUEUE,
        retryAfter: 90,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: TORQUE_STALL_GROUP,
    );
    $queue->setContainer(app());
    $queue->setConnectionName('torque');
    $queue->ensureConsumerGroup($queue->getStreamKey(), TORQUE_STALL_GROUP);

    return ['dir' => $dir, 'prefix' => $prefix, 'queue' => $queue];
}

/**
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $settings
 * @return resource The master's process handle.
 */
function torque_stall_master(array $ctx, array $settings = [])
{
    file_put_contents($ctx['dir'].'/config.json', json_encode(array_replace_recursive([
        'redis' => ['uri' => torqueRedisUri(), 'prefix' => $ctx['prefix'], 'cluster' => false],
        'consumer_group' => TORQUE_STALL_GROUP,
        'serializer' => 'json',
        'workers' => 1,
        'queues' => [TORQUE_STALL_QUEUE],
        'streams' => [TORQUE_STALL_QUEUE => ['retry_after' => 90, 'max_retries' => 3]],
        'coroutines_per_worker' => 1,
        'block_for' => 100,
        'max_worker_lifetime' => 120,
        'max_worker_lifetime_jitter' => 0,
        'drain_grace_seconds' => 1,
        'metrics' => ['enabled' => false],
        'loop_stall_kill_seconds' => 2,
    ], $settings), JSON_THROW_ON_ERROR));

    $output = $ctx['dir'].'/output.log';

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Fixtures/loop-stall-app.php', $ctx['dir']],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start the master.');
    }

    return $process;
}

/**
 * A forwarder to the real Redis, so a test can take Redis away from a running fleet.
 *
 * @param  array<string, mixed>  $ctx
 * @return array{process: resource, uri: string}
 */
function torque_stall_redis_proxy(array $ctx): array
{
    $target = parse_url(torqueRedisUri());
    $portFile = $ctx['dir'].'/proxy.port';

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Fixtures/tcp-proxy.php', $portFile, (string) $target['host'], (string) $target['port']],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start the proxy.');
    }

    torque_stall_wait($ctx, 'the proxy', fn (): bool => (string) @file_get_contents($portFile) !== '', 5);

    return ['process' => $process, 'uri' => 'redis://127.0.0.1:'.file_get_contents($portFile)];
}

/** @param array<string, mixed> $ctx */
function torque_stall_master_log(array $ctx): string
{
    return (string) @file_get_contents($ctx['dir'].'/master.log');
}

/**
 * @param  array<string, mixed>  $ctx
 * @return list<int>
 */
function torque_stall_spawned(array $ctx): array
{
    preg_match_all('/Worker spawned with PID (\d+)/', torque_stall_master_log($ctx), $matches);

    return array_map(intval(...), $matches[1]);
}

/**
 * @param  array<string, mixed>  $ctx
 * @return list<array{type: string, run: int, pid: int}>
 */
function torque_stall_jobs(array $ctx): array
{
    $file = $ctx['dir'].'/jobs.jsonl';
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
}

/**
 * @param  array<string, mixed>  $ctx
 * @return array{type: string, run: int, pid: int}|null
 */
function torque_stall_job_line(array $ctx, string $type, int $run): ?array
{
    foreach (torque_stall_jobs($ctx) as $line) {
        if ($line['type'] === $type && $line['run'] === $run) {
            return $line;
        }
    }

    return null;
}

/** @param array<string, mixed> $ctx */
function torque_stall_heartbeat(array $ctx, int|string $pid): string
{
    return $ctx['dir']."/storage/framework/torque-heartbeats/{$pid}";
}

/** @param array<string, mixed> $ctx */
function torque_stall_wait(array $ctx, string $what, Closure $until, float $seconds = 15.0): void
{
    $deadline = microtime(true) + $seconds;

    while (! $until()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Timed out waiting for {$what}.\n--- master.log\n".torque_stall_master_log($ctx)
                ."\n--- output\n".substr((string) @file_get_contents($ctx['dir'].'/output.log'), -2000));
        }

        usleep(20_000);
    }
}

/**
 * Stop the master and everything it ever spawned, whatever state it is all in.
 *
 * @param  array<string, mixed>  $ctx
 * @param  resource  $process
 */
function torque_stall_cleanup(array $ctx, $process): void
{
    if (is_resource($process)) {
        proc_terminate($process, SIGKILL);
        proc_close($process);
    }

    foreach (torque_stall_spawned($ctx) as $pid) {
        @posix_kill($pid, SIGKILL);
    }

    $redis = createRedisClient(torqueRedisUri());

    foreach ((array) $redis->execute('KEYS', $ctx['prefix'].'*') as $key) {
        $redis->execute('DEL', (string) $key);
    }

    exec('rm -rf '.escapeshellarg($ctx['dir']));
}

it('kills and replaces a worker that blocks its event loop, and the queue carries on', function () {
    $ctx = torque_stall_context();
    // 4s, not 2s: the replacement must survive a loaded machine, or the test
    // reads a second, real kill.
    $master = torque_stall_master($ctx, [
        'streams' => [TORQUE_STALL_QUEUE => ['retry_after' => 1]],
        'loop_stall_kill_seconds' => 4,
    ]);

    try {
        $ctx['queue']->push(new LoopStallProbeJob('block', 60, misbehavingRuns: 1));

        torque_stall_wait($ctx, 'the job to start', fn (): bool => torque_stall_job_line($ctx, 'run', 1) !== null, 20);

        $blockedPid = torque_stall_job_line($ctx, 'run', 1)['pid'];

        torque_stall_wait($ctx, 'the kill', fn (): bool => str_contains(torque_stall_master_log($ctx), "Worker PID {$blockedPid} killed by signal 9"));

        expect(torque_stall_master_log($ctx))
            ->toMatch('/WARN '.TORQUE_STALL_KILL_LINE.": worker PID {$blockedPid} .*heartbeat is \d+s old/")
            ->toContain('Respawning replacement worker');

        // The guarantee is the age the master judged, not a wall clock measured
        // from when this process happened to notice the job had started.
        preg_match("/worker PID {$blockedPid} heartbeat is (\d+)s old/", torque_stall_master_log($ctx), $reported);

        expect((int) $reported[1])->toBeGreaterThan(4);

        // The entry stayed in the PEL: the replacement gets it after retry_after.
        torque_stall_wait($ctx, 'the redelivery', fn (): bool => torque_stall_job_line($ctx, 'end', 2) !== null);

        $replacementPid = torque_stall_job_line($ctx, 'end', 2)['pid'];
        expect($replacementPid)->not->toBe($blockedPid);

        $ctx['queue']->push(new LoopStallProbeJob);

        torque_stall_wait($ctx, 'a job dispatched afterwards', fn (): bool => torque_stall_job_line($ctx, 'end', 3) !== null);

        expect(torque_stall_job_line($ctx, 'end', 3)['pid'])->toBe($replacementPid)
            ->and(torque_stall_spawned($ctx))->toBe([$blockedPid, $replacementPid])
            ->and(is_file(torque_stall_heartbeat($ctx, $blockedPid)))->toBeFalse()
            ->and(is_file(torque_stall_heartbeat($ctx, $replacementPid)))->toBeTrue();
    } finally {
        torque_stall_cleanup($ctx, $master);
    }
});

it('leaves a worker alone while a long job only suspends, and clears stale heartbeats at start', function () {
    $ctx = torque_stall_context();

    mkdir($ctx['dir'].'/storage/framework/torque-heartbeats');
    $dead = torque_stall_heartbeat($ctx, 2147483646);
    $live = torque_stall_heartbeat($ctx, (int) getmypid());
    touch($dead, time() - 3600);
    touch($live, time() - 3600);

    $master = torque_stall_master($ctx, ['loop_stall_kill_seconds' => 4]);

    try {
        // Past the limit, so a job that blocked instead would get killed.
        $ctx['queue']->push(new LoopStallProbeJob('suspend', 6));

        torque_stall_wait($ctx, 'the job to end', fn (): bool => torque_stall_job_line($ctx, 'end', 1) !== null, 25);

        $pid = torque_stall_job_line($ctx, 'end', 1)['pid'];

        clearstatcache();

        expect(torque_stall_master_log($ctx))->not->toContain(TORQUE_STALL_KILL_LINE)
            ->and(torque_stall_spawned($ctx))->toBe([$pid])
            ->and(posix_kill($pid, 0))->toBeTrue()
            ->and(time() - (int) filemtime(torque_stall_heartbeat($ctx, $pid)))->toBeLessThan(3);

        // Takeover: another fleet's live workers share this directory, so only
        // files of dead PIDs may go.
        expect(file_exists($dead))->toBeFalse()
            ->and(file_exists($live))->toBeTrue();
    } finally {
        torque_stall_cleanup($ctx, $master);
    }
});

it('writes no heartbeat and kills nothing when the setting is 0', function () {
    $ctx = torque_stall_context();
    $master = torque_stall_master($ctx, ['loop_stall_kill_seconds' => 0]);

    try {
        $ctx['queue']->push(new LoopStallProbeJob('block', 5));

        torque_stall_wait($ctx, 'the job to end', fn (): bool => torque_stall_job_line($ctx, 'end', 1) !== null, 25);

        $pid = torque_stall_job_line($ctx, 'end', 1)['pid'];

        expect(torque_stall_master_log($ctx))->not->toContain('killed by signal')
            ->and(torque_stall_spawned($ctx))->toBe([$pid])
            ->and(posix_kill($pid, 0))->toBeTrue()
            ->and(file_exists($ctx['dir'].'/storage/framework/torque-heartbeats'))->toBeFalse();
    } finally {
        torque_stall_cleanup($ctx, $master);
    }
});

it('does not kill a healthy worker over a Redis outage', function () {
    $ctx = torque_stall_context();
    $proxy = torque_stall_redis_proxy($ctx);
    $master = torque_stall_master($ctx, [
        'redis' => ['uri' => $proxy['uri']],
        'metrics' => ['enabled' => true],
        'loop_stall_kill_seconds' => 4,
    ]);

    try {
        torque_stall_wait($ctx, 'the worker to spawn', fn (): bool => torque_stall_spawned($ctx) !== [], 20);

        $pid = torque_stall_spawned($ctx)[0];

        torque_stall_wait($ctx, 'the first heartbeat', fn (): bool => is_file(torque_stall_heartbeat($ctx, $pid)), 20);

        proc_terminate($proxy['process'], SIGKILL);

        // Past the limit of 4s.
        usleep(6_000_000);

        clearstatcache();

        expect(torque_stall_master_log($ctx))->not->toContain('killed by signal')
            ->and(torque_stall_spawned($ctx))->toBe([$pid])
            ->and(posix_kill($pid, 0))->toBeTrue()
            ->and(time() - (int) filemtime(torque_stall_heartbeat($ctx, $pid)))->toBeLessThan(3);
    } finally {
        proc_terminate($proxy['process'], SIGKILL);
        proc_close($proxy['process']);
        torque_stall_cleanup($ctx, $master);
    }
});

it('still kills a blocked worker while the master has no Redis', function () {
    $ctx = torque_stall_context();

    // The worker only needs Redis to claim the job, and is frozen from then on.
    $proxy = torque_stall_redis_proxy($ctx);
    $master = torque_stall_master($ctx, [
        'master_redis_uri' => $proxy['uri'],
        'metrics' => ['enabled' => true],
    ]);

    try {
        $ctx['queue']->push(new LoopStallProbeJob('block', 60));

        torque_stall_wait($ctx, 'the job to start', fn (): bool => torque_stall_job_line($ctx, 'run', 1) !== null, 20);

        $pid = torque_stall_job_line($ctx, 'run', 1)['pid'];

        // Redis goes only now, because a master booting without it spends 25s on
        // connect attempts. From here each maintenance call loses seconds to them,
        // which must not read as "the master lost time" forever.
        proc_terminate($proxy['process'], SIGKILL);

        torque_stall_wait($ctx, 'the kill', fn (): bool => str_contains(torque_stall_master_log($ctx), "Worker PID {$pid} killed by signal 9"), 30);

        expect(torque_stall_master_log($ctx))->toMatch('/WARN '.TORQUE_STALL_KILL_LINE.": worker PID {$pid} /");
    } finally {
        proc_terminate($proxy['process'], SIGKILL);
        proc_close($proxy['process']);
        torque_stall_cleanup($ctx, $master);
    }
});

it('still kills a stalled worker while draining, without replacing it', function () {
    $ctx = torque_stall_context();
    $master = torque_stall_master($ctx, ['drain_grace_seconds' => 60]);

    try {
        $ctx['queue']->push(new LoopStallProbeJob('block', 60));

        torque_stall_wait($ctx, 'the job to start', fn (): bool => torque_stall_job_line($ctx, 'run', 1) !== null, 20);

        $pid = torque_stall_job_line($ctx, 'run', 1)['pid'];
        $masterPid = proc_get_status($master)['pid'];

        posix_kill($masterPid, SIGTERM);

        torque_stall_wait($ctx, 'the master to exit', fn (): bool => ! proc_get_status($master)['running']);

        expect(torque_stall_master_log($ctx))
            ->toContain("Worker PID {$pid} killed by signal 9")
            ->not->toContain('Respawning')
            ->and(torque_stall_spawned($ctx))->toBe([$pid]);
    } finally {
        torque_stall_cleanup($ctx, $master);
    }
});

it('derives the limit from the longest retry_after when the setting is null', function () {
    expect(config('torque.loop_stall_kill_seconds'))->toBeNull()
        ->and(MasterProcess::loopStallKillSeconds(config('torque')))->toBe(300)
        ->and(MasterProcess::loopStallKillSeconds([]))->toBe(300)
        ->and(MasterProcess::loopStallKillSeconds(['streams' => ['a' => ['retry_after' => 7200], 'b' => []]]))->toBe(7200)
        ->and(MasterProcess::loopStallKillSeconds(['streams' => ['a' => ['retry_after' => 90]]]))->toBe(300)
        ->and(MasterProcess::loopStallKillSeconds(['loop_stall_kill_seconds' => 45, 'streams' => ['a' => ['retry_after' => 7200]]]))->toBe(45);
});

it('reads an explicit 0 as off and never below the heartbeat can support', function () {
    expect(MasterProcess::loopStallKillSeconds(['loop_stall_kill_seconds' => 0, 'streams' => ['a' => ['retry_after' => 7200]]]))->toBe(0)
        ->and(MasterProcess::loopStallKillSeconds(['loop_stall_kill_seconds' => 0]))->toBe(0)
        ->and(MasterProcess::loopStallKillSeconds(['loop_stall_kill_seconds' => -5]))->toBe(0)
        ->and(MasterProcess::loopStallKillSeconds(['loop_stall_kill_seconds' => 1]))->toBe(2)
        ->and(MasterProcess::loopStallKillSeconds(['loop_stall_kill_seconds' => 120]))->toBe(120);
});

it('skips a watchdog pass when the master itself lost time', function (float $gap, int $limit, bool $skip) {
    expect(MasterProcess::watchdogShouldSkipPass($gap, $limit))->toBe($skip);
})->with([
    'a normal tick' => [0.1, 2, false],
    'a slow tick under a short limit' => [0.9, 2, false],
    'a freeze that would outlive a short limit' => [1.5, 2, true],
    'a slow tick under a long limit' => [4.0, 300, false],
    'laptop sleep under a long limit' => [3600.0, 300, true],
    'a clock stepped backwards' => [-3600.0, 300, true],
]);

it('keeps time spent waiting on Redis out of the tick gap only while both clocks agree', function (float $wall, float $monotonic, float $expected) {
    expect(MasterProcess::lastTickAfterMaintenance(1000.0, $wall, $monotonic))->toBe($expected);
})->with([
    'connect attempts' => [6.0, 6.0, 1006.0],
    'clocks just inside the tolerance' => [6.0, 6.5, 1006.0],
    'clocks exactly at the tolerance' => [7.0, 6.0, 1000.0],
    'laptop sleep during the call' => [3600.0, 6.0, 1000.0],
    'clock stepped back during the call' => [-100.0, 6.0, 1000.0],
]);

it('runs its passes while every maintenance call loses seconds to a dead Redis', function () {
    $lastTick = 1000.0;
    $afterMaintenance = 1006.0;
    $nextTick = 1006.1;

    expect(MasterProcess::watchdogShouldSkipPass($nextTick - $lastTick, 2))->toBeTrue()
        ->and(MasterProcess::watchdogShouldSkipPass(
            $nextTick - MasterProcess::lastTickAfterMaintenance($lastTick, $afterMaintenance - $lastTick, $afterMaintenance - $lastTick),
            2,
        ))->toBeFalse();
});

it('keeps watching after a skipped pass, whichever way the clock moved', function () {
    // The clock stepped back 600s: a heartbeat from the new clock reads older
    // than the spawn, and the next check sits 600s away.
    $before = [101 => 1000, 102 => 300];
    $now = 400.0;

    $rebased = MasterProcess::watchdogRebase($before, $now);

    expect($rebased['spawnedAt'])->toBe([101 => 400, 102 => 300])
        ->and($rebased['baseline'])->toBe(400)
        ->and($rebased['nextCheckAt'])->toBe(0.0);

    // Worker 101 last wrote its heartbeat just after the step, then stopped.
    $mtime = 401;
    $later = 420;

    expect(MasterProcess::stalledHeartbeatAge($mtime, $before[101], $rebased['baseline'], $later, 10))->toBeNull()
        ->and(MasterProcess::stalledHeartbeatAge($mtime, $rebased['spawnedAt'][101], $rebased['baseline'], $later, 10))->toBe(19);

    // A forward step leaves spawn times alone.
    expect(MasterProcess::watchdogRebase([101 => 1000], 5000.0)['spawnedAt'])->toBe([101 => 1000]);
});

it('warns about a default cache store that can block past the limit', function (array $cache, array $database, int $limit, int $socketTimeout, ?string $expected) {
    $warning = MasterProcess::cacheStallWarning($cache, $database, $limit, $socketTimeout);

    if ($expected === null) {
        expect($warning)->toBeNull();
    } else {
        expect($warning)->toContain('WARN Loop stall watchdog')->toContain($expected);
    }
})->with(function () {
    $redis = fn (array $connection, string $client = 'phpredis'): array => [
        ['default' => 'r', 'stores' => ['r' => ['driver' => 'redis', 'connection' => 'cache']]],
        ['redis' => ['client' => $client, 'cache' => $connection]],
    ];
    $memcached = fn (array $options): array => [
        ['default' => 'm', 'stores' => ['m' => ['driver' => 'memcached', 'options' => $options]]],
        [],
    ];
    $database = fn (string $driver): array => [
        ['default' => 'd', 'stores' => ['d' => ['driver' => 'database', 'connection' => 'c']]],
        ['connections' => ['c' => ['driver' => $driver]]],
    ];
    $store = fn (string $driver): array => [['default' => 's', 'stores' => ['s' => ['driver' => $driver]]], []];

    return [
        'array' => [...$store('array'), 5, 60, null],
        'file' => [...$store('file'), 5, 60, null],
        'a custom driver' => [...$store('resp3'), 5, 60, null],
        'sqlite' => [...$database('sqlite'), 5, 60, null],
        'a fiber database driver' => [...$database('fledge-mariadb-native'), 5, 60, null],
        'mysql' => [...$database('mysql'), 5, 60, 'cannot determine'],
        'dynamodb' => [...$store('dynamodb'), 5, 60, 'cannot determine'],
        'phpredis under the limit' => [...$redis(['read_timeout' => 2, 'timeout' => 1]), 5, 60, null],
        'phpredis read timeout at the limit' => [...$redis(['read_timeout' => 5, 'timeout' => 1]), 5, 60, 'up to 5s'],
        'phpredis connect timeout over the limit' => [...$redis(['read_timeout' => 1, 'timeout' => 7.5]), 5, 60, 'up to 7.5s'],
        'phpredis falling back on default_socket_timeout' => [...$redis([]), 5, 60, 'up to 60s'],
        'phpredis with a low default_socket_timeout' => [...$redis([]), 5, 3, null],
        'phpredis without a read timeout at all' => [...$redis(['read_timeout' => -1, 'timeout' => 1]), 5, 60, 'cannot determine'],
        'phpredis with timeouts in the url' => [...$redis(['url' => 'redis://127.0.0.1:6379?read_timeout=2&timeout=1']), 5, 60, null],
        'an unparsable url' => [...$redis(['url' => 'http://']), 5, 60, 'cannot determine'],
        'predis under the limit' => [...$redis(['read_write_timeout' => 2, 'timeout' => 1], 'predis'), 5, 60, null],
        'predis ignores read_timeout' => [...$redis(['read_timeout' => 2, 'timeout' => 1], 'predis'), 5, 60, 'up to 60s'],
        'predis connect default of 5s' => [...$redis(['read_write_timeout' => 2], 'predis'), 5, 60, 'up to 5s'],
        'predis with reads that never time out' => [...$redis(['read_write_timeout' => 0, 'timeout' => 1], 'predis'), 5, 60, 'cannot determine'],
        'an unset default_socket_timeout' => [...$redis([]), 5, -1, 'cannot determine'],
        'a redis cluster' => [
            ['default' => 'r', 'stores' => ['r' => ['driver' => 'redis', 'connection' => 'cache']]],
            ['redis' => ['client' => 'phpredis', 'clusters' => ['cache' => [['host' => 'a']]]]],
            5, 60, 'cannot determine',
        ],
        'a missing redis connection' => [
            ['default' => 'r', 'stores' => ['r' => ['driver' => 'redis', 'connection' => 'cache']]],
            ['redis' => ['client' => 'phpredis']],
            5, 60, 'cannot determine',
        ],
        'memcached without options' => [...$memcached([]), 5, 60, 'cannot determine'],
        'memcached with half the timeouts' => [...$memcached([14 => 1000]), 5, 60, 'cannot determine'],
        'memcached under the limit' => [...$memcached([14 => 1000, 8 => 2000]), 5, 60, null],
        'memcached over the limit' => [...$memcached([14 => 1000, 8 => 6000]), 5, 60, 'up to 6s'],
    ];
});

it('logs the blocking cache warning at boot, and only with the watchdog on', function (int $limit, bool $warns) {
    $ctx = torque_stall_context();
    $master = torque_stall_master($ctx, [
        'loop_stall_kill_seconds' => $limit,
        'app_config' => [
            'cache.default' => 'hanging',
            'cache.stores.hanging' => ['driver' => 'redis', 'connection' => 'hanging'],
            'database.redis.client' => 'phpredis',
            'database.redis.hanging' => ['host' => '127.0.0.1', 'port' => 1],
        ],
    ]);

    try {
        torque_stall_wait($ctx, 'the worker to spawn', fn (): bool => torque_stall_spawned($ctx) !== [], 20);

        $warning = "WARN Loop stall watchdog: worker timers read the default cache store 'hanging'";

        if ($warns) {
            expect(torque_stall_master_log($ctx))->toContain($warning);
        } else {
            expect(torque_stall_master_log($ctx))->not->toContain($warning);
        }
    } finally {
        torque_stall_cleanup($ctx, $master);
    }
})->with([
    'watchdog on' => [4, true],
    'watchdog off' => [0, false],
]);

it('judges a heartbeat', function (?int $mtime, int $spawnedAt, int $baseline, ?int $age) {
    $now = 1_000;
    $limit = 10;

    expect(MasterProcess::stalledHeartbeatAge($mtime, $spawnedAt, $baseline, $now, $limit))->toBe($age);
})->with([
    'fresh' => [995, 500, 500, null],
    'exactly at the limit' => [990, 500, 500, null],
    'past the limit' => [989, 500, 500, 11],
    'no file, however long ago the spawn' => [null, 1, 500, null],
    'left by an earlier process with this PID' => [400, 500, 500, null],
    'old only because the master lost time' => [100, 50, 995, null],
    'old again a full limit after the re-baseline' => [100, 50, 985, 15],
    'from the future after a clock step' => [2_000, 500, 500, null],
]);
