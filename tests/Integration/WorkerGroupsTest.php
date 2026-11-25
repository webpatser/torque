<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\LoopStallProbeJob;

/*
|--------------------------------------------------------------------------
| Worker groups on a real fleet
|--------------------------------------------------------------------------
|
| One fixture fleet (tests/Fixtures/fleet-app.php) whose node block defines
| two worker groups on a random key prefix: `scaling` autoscales on its own
| queue up to a low ceiling, `fixed` runs a fixed number of workers on its
| own queue. The fleet logs every job it ran with the worker's PID, and the
| master logs every spawn tagged with the group, so the logs say which group
| ran what. CLI commands run through the same fixture app.
|
| Autoscale timings are kept short through the config (one second cooldown,
| scale-down off), so growth shows within a few maintenance ticks.
|
| Requires a running Redis instance, and `ps` and `pgrep`; see
| tests/Integration/FleetLifecycleTest.php for running it in a Linux image.
|
*/

const TORQUE_GROUPS_CONSUMER_GROUP = 'groups-group';
const TORQUE_GROUPS_NODE = 'groups-node';
const TORQUE_GROUPS_SCALING = 'scaling';
const TORQUE_GROUPS_FIXED = 'fixed';
const TORQUE_GROUPS_SCALING_QUEUE = 'scale-q';
const TORQUE_GROUPS_FIXED_QUEUE = 'fixed-q';
const TORQUE_GROUPS_SCALING_MAX = 3;
const TORQUE_GROUPS_FIXED_WORKERS = 2;

beforeEach(function () {
    $this->redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $this->prefix = "torque-groups-{$token}:";
    $this->registry = new MasterRegistry($this->redis, $this->prefix);
    $this->process = null;
    $this->dir = sys_get_temp_dir()."/torque-groups-{$token}";

    mkdir($this->dir.'/storage/framework', 0777, true);
    file_put_contents($this->dir.'/artisan', "<?php\n\$runDir = __DIR__;\nrequire ".var_export(dirname(__DIR__).'/Fixtures/fleet-app.php', true).";\n");

    $this->queues = [];

    foreach ([TORQUE_GROUPS_SCALING_QUEUE, TORQUE_GROUPS_FIXED_QUEUE] as $name) {
        $queue = new StreamQueue(
            redisUri: torqueRedisUri(),
            default: $name,
            retryAfter: 90,
            blockFor: 0,
            prefix: $this->prefix,
            consumerGroup: TORQUE_GROUPS_CONSUMER_GROUP,
        );
        $queue->setContainer(app());
        $queue->setConnectionName('torque');
        $queue->ensureConsumerGroup($queue->getStreamKey(), TORQUE_GROUPS_CONSUMER_GROUP);
        $this->queues[$name] = $queue;
    }

    $this->push = function (int $count, string $queue, string $behaviour = 'none', int $seconds = 0): void {
        foreach (range(1, $count) as $ignored) {
            $this->queues[$queue]->push(new LoopStallProbeJob($behaviour, $seconds));
        }
    };

    $this->output = fn (): string => (string) @file_get_contents($this->dir.'/output.log');

    /** The PIDs of the finished jobs in the fleet's log, in order. */
    $this->ends = fn (): array => array_values(array_map(
        static fn (string $line): int => (int) json_decode($line, true, flags: JSON_THROW_ON_ERROR)['pid'],
        array_filter(
            @file($this->dir.'/jobs.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            static fn (string $line): bool => str_contains($line, '"type":"end"'),
        ),
    ));

    /** Every worker the master spawned, optionally only those of one group. */
    $this->workers = function (?string $group = null): array {
        $tag = $group === null ? '(?:\[group [a-z0-9_-]+\] )?' : preg_quote("[group {$group}] ", '/');
        preg_match_all('/'.$tag.'Worker spawned with PID (\d+)/', ($this->output)(), $matches);

        return array_map(intval(...), $matches[1]);
    };

    $this->alive = fn (?string $group = null): array => array_values(array_filter(
        ($this->workers)($group),
        static fn (int $pid): bool => posix_kill($pid, 0),
    ));

    $this->waitFor = function (string $what, Closure $condition, float $seconds = 30.0): void {
        $deadline = microtime(true) + $seconds;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out waiting for {$what}. Output:\n".($this->output)());
            }

            usleep(50_000);
        }
    };

    $this->running = fn (): bool => is_resource($this->process) && proc_get_status($this->process)['running'];

    /** This fleet's registry record. */
    $this->master = fn (): ?array => array_values(array_filter(
        $this->registry->all(),
        static fn (array $record): bool => $record['node'] === TORQUE_GROUPS_NODE,
    ))[0] ?? null;

    /** Live worker count per group, from the registry record. */
    $this->groupCounts = function (): array {
        $counts = [];

        foreach ((($this->master)()['groups'] ?? []) as $name => $group) {
            $counts[(string) $name] = (int) ($group['workers'] ?? -1);
        }

        return $counts;
    };

    /** The worker hash of a PID, once the worker published it. */
    $this->workerHash = function (int $pid): ?array {
        foreach ($this->redis->execute('KEYS', $this->prefix.'worker:*') as $key) {
            $key = (string) $key;

            if ((string) $this->redis->execute('HGET', $key, 'pid') === (string) $pid) {
                return [
                    'group' => $this->redis->execute('HGET', $key, 'group'),
                    'master' => $this->redis->execute('HGET', $key, 'master'),
                ];
            }
        }

        return null;
    };

    $this->commandLine = fn (int $pid): string => trim((string) shell_exec('ps -ww -o args= -p '.$pid.' 2>/dev/null'));

    $this->start = function (): void {
        $env = getenv();
        unset($env['FLEDGE_EVENT_LOOP'], $env['TORQUE_NODE'], $env['TORQUE_SUPERVISED']);

        file_put_contents($this->dir.'/config.json', json_encode([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix, 'cluster' => false],
            'node' => TORQUE_GROUPS_NODE,
            'consumer_group' => TORQUE_GROUPS_CONSUMER_GROUP,
            'serializer' => 'json',
            'event_loop' => 'select',
            'streams' => [
                TORQUE_GROUPS_SCALING_QUEUE => ['retry_after' => 90, 'max_retries' => 3],
                TORQUE_GROUPS_FIXED_QUEUE => ['retry_after' => 90, 'max_retries' => 3],
            ],
            'poll_interval_ms' => 100,
            'max_worker_lifetime' => 300,
            'max_worker_lifetime_jitter' => 0,
            'drain_grace_seconds' => 10,
            'reload_pause_seconds' => 4,
            'intervals' => ['pause_check_seconds' => 0.25, 'paused_poll_seconds' => 0.25],
            'metrics' => ['enabled' => false],
            'autoscale' => ['enabled' => false],
            'nodes' => ['*' => ['groups' => [
                TORQUE_GROUPS_SCALING => [
                    'queues' => [TORQUE_GROUPS_SCALING_QUEUE],
                    'workers' => 1,
                    'slots_per_worker' => 2,
                    'autoscale' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => TORQUE_GROUPS_SCALING_MAX,
                        'scale_up_threshold' => 0.5,
                        // Never scale down here: the counts must only move on load.
                        'scale_down_threshold' => 0.0,
                        'cooldown' => 1,
                    ],
                ],
                TORQUE_GROUPS_FIXED => [
                    'queues' => [TORQUE_GROUPS_FIXED_QUEUE],
                    'workers' => TORQUE_GROUPS_FIXED_WORKERS,
                    'slots_per_worker' => 3,
                    'autoscale' => ['enabled' => false],
                ],
            ]]],
        ], JSON_THROW_ON_ERROR));

        $log = $this->dir.'/output.log';
        $this->process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/fleet-app.php', $this->dir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            $env,
        );

        if (! is_resource($this->process)) {
            throw new RuntimeException('Failed to start the master.');
        }

        ($this->waitFor)('both groups running at their configured counts', function (): bool {
            $output = ($this->output)();

            return (($this->master)()['state'] ?? null) === 'running'
                && ($this->groupCounts)() === [TORQUE_GROUPS_SCALING => 1, TORQUE_GROUPS_FIXED => TORQUE_GROUPS_FIXED_WORKERS]
                && substr_count($output, 'Starting 2 reader Fibers') === 1
                && substr_count($output, 'Starting 3 reader Fibers') === TORQUE_GROUPS_FIXED_WORKERS;
        });
    };

    /**
     * Run a CLI command line through the fixture app.
     *
     * @return array{code: int, output: string}
     */
    $this->artisan = function (string $commandLine, float $seconds = 40.0): array {
        $env = getenv();
        unset($env['FLEDGE_EVENT_LOOP'], $env['TORQUE_NODE'], $env['TORQUE_SUPERVISED']);

        $log = $this->dir.'/cli-'.bin2hex(random_bytes(4)).'.log';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/fleet-app.php', $this->dir, ...explode(' ', $commandLine)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            $env,
        );

        $deadline = microtime(true) + $seconds;

        while (($status = proc_get_status($process))['running']) {
            if (microtime(true) > $deadline) {
                @posix_kill($status['pid'], SIGKILL);
                proc_close($process);

                throw new RuntimeException("`{$commandLine}` did not finish within {$seconds}s. Output:\n".@file_get_contents($log));
            }

            usleep(50_000);
        }

        proc_close($process);

        return ['code' => $status['exitcode'], 'output' => (string) @file_get_contents($log)];
    };

    // Last, so afterEach finds everything it cleans up.
    foreach (['ps', 'pgrep'] as $tool) {
        if (trim((string) shell_exec('command -v '.$tool.' 2>/dev/null')) === '') {
            $this->markTestSkipped("Needs `{$tool}` (Debian and Ubuntu images: apt-get install procps).");
        }
    }
});

afterEach(function () {
    if (is_resource($this->process ?? null)) {
        $status = proc_get_status($this->process);

        // The fixture leads its own group: master, workers and anything they started.
        if ($status['running']) {
            @posix_kill(-$status['pid'], SIGKILL);
        }

        // Workers can outlive a master that died first: they keep the group.
        foreach (($this->workers)() as $worker) {
            @posix_kill($worker, SIGKILL);
        }

        proc_close($this->process);
    }

    if (isset($this->dir)) {
        (new Filesystem)->deleteDirectory($this->dir);
    }

    if (isset($this->redis, $this->prefix)) {
        foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
            $this->redis->execute('DEL', (string) $key);
        }
    }
});

it('runs each group on its own queues at its configured count and lists both in torque:status', function () {
    ($this->start)();

    $scaling = ($this->alive)(TORQUE_GROUPS_SCALING);
    $fixed = ($this->alive)(TORQUE_GROUPS_FIXED);

    expect($scaling)->toHaveCount(1)
        ->and($fixed)->toHaveCount(TORQUE_GROUPS_FIXED_WORKERS);

    foreach ($scaling as $pid) {
        expect(($this->commandLine)($pid))->toContain('--queues='.TORQUE_GROUPS_SCALING_QUEUE.' ')
            ->toContain('--group='.TORQUE_GROUPS_SCALING);
    }

    foreach ($fixed as $pid) {
        expect(($this->commandLine)($pid))->toContain('--queues='.TORQUE_GROUPS_FIXED_QUEUE.' ')
            ->toContain('--group='.TORQUE_GROUPS_FIXED);
    }

    // Before any load, so the scaling group is still at its configured count.
    $status = ($this->artisan)('torque:status');

    expect($status['code'])->toBe(0)
        ->and($status['output'])->toContain('Groups')
        ->toMatch('/^\s*\|\s*'.TORQUE_GROUPS_SCALING.'\s*\|\s*'.TORQUE_GROUPS_SCALING_QUEUE.'\s*\|\s*1\s*\|\s*1\s*\|\s*1\s*\|\s*'.TORQUE_GROUPS_SCALING_MAX.'\s*\|\s*2\s*\|\s*$/m')
        ->toMatch('/^\s*\|\s*'.TORQUE_GROUPS_FIXED.'\s*\|\s*'.TORQUE_GROUPS_FIXED_QUEUE.'\s*\|\s*'.TORQUE_GROUPS_FIXED_WORKERS.'\s*\|\s*'.TORQUE_GROUPS_FIXED_WORKERS.'\s*\|.*\|\s*3\s*\|\s*$/m');

    // The fixed group's queue goes to the fixed group's workers only.
    ($this->push)(6, TORQUE_GROUPS_FIXED_QUEUE);
    ($this->waitFor)('the fixed queue to be consumed', fn (): bool => count(($this->ends)()) === 6, 15.0);

    expect(array_diff(($this->ends)(), $fixed))->toBe([]);

    // And the scaling group's queue to the scaling group's workers only.
    ($this->push)(4, TORQUE_GROUPS_SCALING_QUEUE);
    ($this->waitFor)('the scaling queue to be consumed', fn (): bool => count(($this->ends)()) === 10, 15.0);

    expect(array_diff(array_slice(($this->ends)(), 6), ($this->workers)(TORQUE_GROUPS_SCALING)))->toBe([]);
});

it('respawns a SIGKILLed worker of the fixed group into the fixed group', function () {
    ($this->start)();

    $masterId = ($this->master)()['master_id'];
    $victim = ($this->alive)(TORQUE_GROUPS_FIXED)[0];

    ($this->waitFor)('the victim to publish its worker hash', fn (): bool => ($this->workerHash)($victim) !== null, 10.0);

    expect(($this->workerHash)($victim))->toBe(['group' => TORQUE_GROUPS_FIXED, 'master' => $masterId]);

    $before = ($this->workers)();
    posix_kill($victim, SIGKILL);

    ($this->waitFor)('a replacement in the fixed group', fn (): bool => array_diff(($this->workers)(TORQUE_GROUPS_FIXED), $before) !== [], 20.0);

    $replacement = array_values(array_diff(($this->workers)(TORQUE_GROUPS_FIXED), $before))[0];

    // Nothing else was spawned: the scaling group kept its one worker.
    expect(array_values(array_diff(($this->workers)(), $before)))->toBe([$replacement])
        ->and(($this->commandLine)($replacement))->toContain('--queues='.TORQUE_GROUPS_FIXED_QUEUE.' ')
        ->toContain('--group='.TORQUE_GROUPS_FIXED);

    ($this->waitFor)('the replacement to publish its worker hash', fn (): bool => ($this->workerHash)($replacement) !== null, 10.0);

    expect(($this->workerHash)($replacement))->toBe(['group' => TORQUE_GROUPS_FIXED, 'master' => $masterId]);

    ($this->waitFor)('the registry to show the groups back at their counts', fn (): bool => ($this->groupCounts)() === [
        TORQUE_GROUPS_SCALING => 1,
        TORQUE_GROUPS_FIXED => TORQUE_GROUPS_FIXED_WORKERS,
    ], 10.0);

    // The replacement consumes the fixed group's queue.
    ($this->push)(6, TORQUE_GROUPS_FIXED_QUEUE);
    ($this->waitFor)('the fixed queue to be consumed', fn (): bool => count(($this->ends)()) === 6, 15.0);

    expect(array_diff(($this->ends)(), ($this->alive)(TORQUE_GROUPS_FIXED)))->toBe([]);
});

it('grows the scaling group up to its max_workers under load while the fixed group stays the same', function () {
    ($this->start)();

    $fixedBefore = ($this->alive)(TORQUE_GROUPS_FIXED);
    $fixedCounts = [];
    $scalingPeak = 0;

    $sample = function () use (&$fixedCounts, &$scalingPeak): array {
        $counts = ($this->groupCounts)();
        $fixedCounts[] = $counts[TORQUE_GROUPS_FIXED] ?? -1;
        $scalingPeak = max($scalingPeak, $counts[TORQUE_GROUPS_SCALING] ?? 0, count(($this->alive)(TORQUE_GROUPS_SCALING)));

        return $counts;
    };

    // Two slots per worker, each job holds one for two seconds: enough for
    // well over the ceiling, so the group stays saturated while it grows.
    ($this->push)(40, TORQUE_GROUPS_SCALING_QUEUE, 'suspend', 2);

    ($this->waitFor)('the scaling group to reach its max_workers', fn (): bool => (($sample)()[TORQUE_GROUPS_SCALING] ?? 0) === TORQUE_GROUPS_SCALING_MAX, 20.0);

    // Still saturated: a few more autoscale ticks must not pass the ceiling.
    $until = microtime(true) + 2.5;

    while (microtime(true) < $until) {
        ($sample)();
        usleep(100_000);
    }

    expect($scalingPeak)->toBe(TORQUE_GROUPS_SCALING_MAX)
        ->and(($this->alive)(TORQUE_GROUPS_SCALING))->toHaveCount(TORQUE_GROUPS_SCALING_MAX)
        ->and(array_unique($fixedCounts))->toBe([TORQUE_GROUPS_FIXED_WORKERS])
        ->and(($this->alive)(TORQUE_GROUPS_FIXED))->toBe($fixedBefore)
        ->and(($this->workers)(TORQUE_GROUPS_FIXED))->toBe($fixedBefore)
        ->and(substr_count(($this->output)(), '[group '.TORQUE_GROUPS_SCALING.'] Autoscaler: scaling up'))->toBe(TORQUE_GROUPS_SCALING_MAX - 1)
        ->and(($this->output)())->not->toContain('[group '.TORQUE_GROUPS_FIXED.'] Autoscaler');

    // The jobs ran on the scaling group alone.
    expect(array_diff(($this->ends)(), ($this->workers)(TORQUE_GROUPS_SCALING)))->toBe([]);
});

it('stops every group on a drain', function () {
    ($this->start)();

    $all = ($this->alive)();

    expect($all)->toHaveCount(1 + TORQUE_GROUPS_FIXED_WORKERS);

    posix_kill(proc_get_status($this->process)['pid'], SIGUSR2);

    ($this->waitFor)('the master to exit after its drain', fn (): bool => ! ($this->running)(), 25.0);

    expect(proc_get_status($this->process)['exitcode'])->toBe(0)
        ->and(array_filter($all, static fn (int $pid): bool => posix_kill($pid, 0)))->toBe([])
        ->and(($this->workers)())->toBe($all)
        ->and(($this->master)())->toBeNull();
});
