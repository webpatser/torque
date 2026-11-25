<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Webpatser\Torque\Control\LeaderLease;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\LoopStallProbeJob;

/*
|--------------------------------------------------------------------------
| Two real nodes on one Redis prefix
|--------------------------------------------------------------------------
|
| Two fixture fleets (tests/Fixtures/fleet-app.php), each with its own run
| directory and its own node name, share one random key prefix, one consumer
| group and the same queues: two hosts as far as Torque can tell. Each fleet
| logs the jobs it ran into its own run directory, so the log says which node
| consumed what. CLI commands run through the same fixture app.
|
| Pauses are invisible until a worker reads them on its pause timer, so the
| fleets check every 0.25s and a test waits a few of those periods before it
| relies on one.
|
| Requires a running Redis instance, and `ps` and `pgrep`; see
| tests/Integration/FleetLifecycleTest.php for running it in a Linux image.
|
*/

const TORQUE_MULTI_GROUP = 'multi-group';
const TORQUE_MULTI_QUEUE = 'fleet';
const TORQUE_MULTI_OTHER_QUEUE = 'other';
const TORQUE_MULTI_NODES = ['alpha', 'beta'];
const TORQUE_MULTI_PAUSE_SETTLE_US = 1_000_000;

beforeEach(function () {
    $this->redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $this->prefix = "torque-multi-{$token}:";
    $this->fleets = [];
    $this->registry = new MasterRegistry($this->redis, $this->prefix);
    $this->lease = new LeaderLease($this->redis, $this->prefix);

    foreach (TORQUE_MULTI_NODES as $node) {
        $dir = sys_get_temp_dir()."/torque-multi-{$token}-{$node}";
        mkdir($dir.'/storage/framework', 0777, true);
        file_put_contents($dir.'/artisan', "<?php\n\$runDir = __DIR__;\nrequire ".var_export(dirname(__DIR__).'/Fixtures/fleet-app.php', true).";\n");
        $this->fleets[$node] = ['dir' => $dir, 'process' => null];
    }

    $this->queues = [];

    foreach ([TORQUE_MULTI_QUEUE, TORQUE_MULTI_OTHER_QUEUE] as $name) {
        $queue = new StreamQueue(
            redisUri: torqueRedisUri(),
            default: $name,
            retryAfter: 90,
            blockFor: 0,
            prefix: $this->prefix,
            consumerGroup: TORQUE_MULTI_GROUP,
        );
        $queue->setContainer(app());
        $queue->setConnectionName('torque');
        $queue->ensureConsumerGroup($queue->getStreamKey(), TORQUE_MULTI_GROUP);
        $this->queues[$name] = $queue;
    }

    $this->push = function (int $count, string $queue = TORQUE_MULTI_QUEUE, string $behaviour = 'none', int $seconds = 0): void {
        foreach (range(1, $count) as $ignored) {
            $this->queues[$queue]->push(new LoopStallProbeJob($behaviour, $seconds));
        }
    };

    $this->output = fn (string $node): string => (string) @file_get_contents($this->fleets[$node]['dir'].'/output.log');

    $this->outputs = function (): string {
        $all = '';

        foreach (array_keys($this->fleets) as $node) {
            $all .= "--- {$node} ---\n".($this->output)($node)."\n";
        }

        return $all;
    };

    /** Finished jobs in the node's log. */
    $this->ends = fn (string $node): int => count(array_filter(
        @file($this->fleets[$node]['dir'].'/jobs.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        static fn (string $line): bool => str_contains($line, '"type":"end"'),
    ));

    $this->workers = function (string $node): array {
        preg_match_all('/Worker spawned with PID (\d+)/', ($this->output)($node), $matches);

        return array_map(intval(...), $matches[1]);
    };

    $this->waitFor = function (string $what, Closure $condition, float $seconds = 30.0): void {
        $deadline = microtime(true) + $seconds;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out waiting for {$what}. Output:\n".($this->outputs)());
            }

            usleep(50_000);
        }
    };

    $this->running = fn (string $node): bool => is_resource($this->fleets[$node]['process'])
        && proc_get_status($this->fleets[$node]['process'])['running'];

    $this->environment = function (array $extra = []): array {
        $env = getenv();
        unset($env['FLEDGE_EVENT_LOOP'], $env['TORQUE_NODE'], $env['TORQUE_SUPERVISED']);

        return array_replace($env, $extra);
    };

    $this->start = function (string $node, array $settings = [], array $env = []): int {
        $dir = $this->fleets[$node]['dir'];

        file_put_contents($dir.'/config.json', json_encode(array_replace_recursive([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix, 'cluster' => false],
            'node' => $node,
            'consumer_group' => TORQUE_MULTI_GROUP,
            'serializer' => 'json',
            'event_loop' => 'select',
            'workers' => 2,
            'slots_per_worker' => 4,
            'queues' => [TORQUE_MULTI_QUEUE, TORQUE_MULTI_OTHER_QUEUE],
            'streams' => [
                TORQUE_MULTI_QUEUE => ['retry_after' => 90, 'max_retries' => 3],
                TORQUE_MULTI_OTHER_QUEUE => ['retry_after' => 90, 'max_retries' => 3],
            ],
            'poll_interval_ms' => 100,
            'max_worker_lifetime' => 300,
            'max_worker_lifetime_jitter' => 0,
            'drain_grace_seconds' => 10,
            'reload_pause_seconds' => 4,
            'intervals' => ['pause_check_seconds' => 0.25, 'paused_poll_seconds' => 0.25],
            'metrics' => ['enabled' => false],
            'autoscale' => ['enabled' => false],
        ], $settings), JSON_THROW_ON_ERROR));

        $log = $dir.'/output.log';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/fleet-app.php', $dir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            ($this->environment)($env),
        );

        if (! is_resource($process)) {
            throw new RuntimeException("Failed to start the master of {$node}.");
        }

        $this->fleets[$node]['process'] = $process;

        return proc_get_status($process)['pid'];
    };

    /** The live registry records by node. */
    $this->masters = function (): array {
        $byNode = [];

        foreach ($this->registry->all() as $record) {
            $byNode[$record['node']] = $record;
        }

        return $byNode;
    };

    $this->startBoth = function () {
        foreach (TORQUE_MULTI_NODES as $node) {
            ($this->start)($node);
        }

        ($this->waitFor)('both fleets running with two workers each', function (): bool {
            $masters = ($this->masters)();

            foreach (TORQUE_MULTI_NODES as $node) {
                if (($masters[$node]['state'] ?? null) !== 'running'
                    || substr_count(($this->output)($node), 'Starting 4 reader Fibers') < 2) {
                    return false;
                }
            }

            return true;
        });
    };

    /**
     * Run a CLI command line through the fixture app of the given node.
     *
     * @return array{code: int, output: string}
     */
    $this->artisan = function (string $node, string $commandLine, float $seconds = 40.0): array {
        $log = $this->fleets[$node]['dir'].'/cli-'.bin2hex(random_bytes(4)).'.log';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/fleet-app.php', $this->fleets[$node]['dir'], ...explode(' ', $commandLine)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            ($this->environment)(),
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

    $this->stopFleet = function (string $node) {
        $process = $this->fleets[$node]['process'];

        if (is_resource($process) && proc_get_status($process)['running']) {
            posix_kill(proc_get_status($process)['pid'], SIGTERM);
            ($this->waitFor)("{$node} to exit", fn (): bool => ! ($this->running)($node), 25.0);
        }
    };

    // Last, so afterEach finds everything it cleans up.
    foreach (['ps', 'pgrep'] as $tool) {
        if (trim((string) shell_exec('command -v '.$tool.' 2>/dev/null')) === '') {
            $this->markTestSkipped("Needs `{$tool}` (Debian and Ubuntu images: apt-get install procps).");
        }
    }
});

afterEach(function () {
    foreach ($this->fleets ?? [] as $node => $fleet) {
        if (is_resource($fleet['process'])) {
            $status = proc_get_status($fleet['process']);

            // The fixture leads its own group: master, workers and anything they started.
            if ($status['running']) {
                @posix_kill(-$status['pid'], SIGKILL);
            }

            // Workers can outlive a master that died first: they keep the group.
            foreach (($this->workers)($node) as $worker) {
                @posix_kill($worker, SIGKILL);
            }

            proc_close($fleet['process']);
        }

        (new Filesystem)->deleteDirectory($fleet['dir']);
    }

    if (isset($this->redis, $this->prefix)) {
        foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
            $this->redis->execute('DEL', (string) $key);
        }
    }
});

it('lists both masters and keeps exactly one leader that moves when the leader stops', function () {
    ($this->startBoth)();

    $ids = array_column(($this->masters)(), 'master_id', 'node');

    ($this->waitFor)('a leader', fn (): bool => $this->lease->leader() !== null);

    $leader = $this->lease->leader();
    $leaderNode = array_search($leader, $ids, true);

    expect($leaderNode)->toBeIn(TORQUE_MULTI_NODES)
        ->and(array_filter($ids, fn (string $id): bool => $this->lease->isLeader($id)))->toHaveCount(1);

    $status = ($this->artisan)('alpha', 'torque:status');

    expect($status['code'])->toBe(0)
        ->and($status['output'])->toContain('alpha')
        ->toContain('beta')
        ->toMatch('/Leader/');

    // Exactly one row carries the leader mark.
    preg_match_all('/^\s*\|\s*(alpha|beta)\s*\|.*\|\s*\*\s*\|\s*$/m', $status['output'], $rows);

    expect($rows[1])->toBe([$leaderNode]);

    $follower = $leaderNode === 'alpha' ? 'beta' : 'alpha';
    ($this->stopFleet)($leaderNode);

    ($this->waitFor)('the lease to move to the other master', fn (): bool => $this->lease->leader() === $ids[$follower], 20.0);

    expect(($this->running)($follower))->toBeTrue()
        ->and(array_keys(($this->masters)()))->toBe([$follower]);
});

it('drains node alpha while beta keeps consuming', function () {
    ($this->startBoth)();

    $alphaMaster = proc_get_status($this->fleets['alpha']['process'])['pid'];
    posix_kill($alphaMaster, SIGUSR2);

    ($this->waitFor)('alpha to report draining', fn (): bool => (($this->masters)()['alpha']['state'] ?? 'gone') !== 'running');
    usleep(TORQUE_MULTI_PAUSE_SETTLE_US);

    $alphaBefore = ($this->ends)('alpha');
    ($this->push)(16, behaviour: 'suspend', seconds: 1);

    ($this->waitFor)('beta to run every job', fn (): bool => ($this->ends)('beta') === 16, 20.0);

    expect(($this->ends)('alpha'))->toBe($alphaBefore);

    ($this->waitFor)('alpha to exit after its drain', fn (): bool => ! ($this->running)('alpha'), 25.0);

    expect(proc_get_status($this->fleets['alpha']['process'])['exitcode'])->toBe(0)
        ->and(($this->running)('beta'))->toBeTrue()
        ->and(array_keys(($this->masters)()))->toBe(['beta']);

    // Alpha's drain key never paused beta.
    ($this->push)(4);
    ($this->waitFor)('beta to keep consuming', fn (): bool => ($this->ends)('beta') === 20, 15.0);
});

it('pauses only the workers of a paused node and resumes them on continue', function () {
    ($this->startBoth)();

    $pause = ($this->artisan)('beta', 'torque:pause pause --node=alpha');

    expect($pause['code'])->toBe(0);

    usleep(TORQUE_MULTI_PAUSE_SETTLE_US);

    // Long enough that beta alone cannot clear them before alpha could claim some.
    ($this->push)(16, behaviour: 'suspend', seconds: 1);

    ($this->waitFor)('beta to run every job', fn (): bool => ($this->ends)('beta') === 16, 20.0);

    expect(($this->ends)('alpha'))->toBe(0);

    $continue = ($this->artisan)('beta', 'torque:pause continue --node=alpha');

    expect($continue['code'])->toBe(0);

    usleep(TORQUE_MULTI_PAUSE_SETTLE_US);

    ($this->push)(16, behaviour: 'suspend', seconds: 1);

    ($this->waitFor)('all jobs done', fn (): bool => ($this->ends)('alpha') + ($this->ends)('beta') === 32, 20.0);

    expect(($this->ends)('alpha'))->toBeGreaterThan(0);
});

it('pauses one queue on both nodes', function () {
    ($this->startBoth)();

    $pause = ($this->artisan)('alpha', 'torque:pause pause --queue='.TORQUE_MULTI_QUEUE);

    expect($pause['code'])->toBe(0);

    usleep(TORQUE_MULTI_PAUSE_SETTLE_US);

    ($this->push)(8, TORQUE_MULTI_QUEUE);
    ($this->push)(8, TORQUE_MULTI_OTHER_QUEUE);

    $total = fn (): int => ($this->ends)('alpha') + ($this->ends)('beta');

    ($this->waitFor)('the other queue to be consumed', fn (): bool => $total() >= 8, 15.0);

    // Give the paused queue a fair chance to be (wrongly) consumed.
    usleep(TORQUE_MULTI_PAUSE_SETTLE_US);

    expect($total())->toBe(8);

    $continue = ($this->artisan)('alpha', 'torque:pause continue --queue='.TORQUE_MULTI_QUEUE);

    expect($continue['code'])->toBe(0);

    ($this->waitFor)('the paused queue to be consumed after continue', fn (): bool => $total() === 16, 15.0);
});

it('stops node beta remotely and leaves alpha running', function () {
    ($this->startBoth)();

    $stop = ($this->artisan)('alpha', 'torque:stop --node=beta --timeout=25');

    expect($stop['code'])->toBe(0)
        ->and($stop['output'])->toContain('acknowledged the stop command')
        ->toContain('stopped.');

    ($this->waitFor)('beta to exit', fn (): bool => ! ($this->running)('beta'), 10.0);

    expect(proc_get_status($this->fleets['beta']['process'])['exitcode'])->toBe(0)
        ->and(array_filter(($this->workers)('beta'), static fn (int $pid): bool => posix_kill($pid, 0)))->toBe([])
        ->and(($this->running)('alpha'))->toBeTrue()
        ->and(array_keys(($this->masters)()))->toBe(['alpha']);

    ($this->push)(4);
    ($this->waitFor)('alpha to keep consuming', fn (): bool => ($this->ends)('alpha') === 4, 15.0);
});

it('refuses a remote reload of an unsupervised master', function () {
    ($this->startBoth)();

    $reload = ($this->artisan)('alpha', 'torque:reload --node=beta');

    expect($reload['code'])->not->toBe(0)
        ->and($reload['output'])->toContain('is not supervised');

    usleep(TORQUE_MULTI_PAUSE_SETTLE_US);

    expect(($this->running)('beta'))->toBeTrue()
        ->and(($this->masters)()['beta']['state'] ?? null)->toBe('running');
});
