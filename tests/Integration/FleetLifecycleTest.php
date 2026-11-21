<?php

declare(strict_types=1);

use Fledge\Loop\Driver\PollDriver;
use Illuminate\Filesystem\Filesystem;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\LoopStallProbeJob;

/*
|--------------------------------------------------------------------------
| A real fleet, start to graceful stop
|--------------------------------------------------------------------------
|
| `torque:start` in a process of its own (tests/Fixtures/fleet-app.php) forks
| and execs two real `torque:worker` processes. Both commands install the event
| loop driver from `torque.event_loop`, the workers process real jobs from
| Redis, and a SIGTERM to the master drains them: the jobs in flight finish,
| every worker exits and the master exits 0 within the drain grace.
|
| The fixture leads its own process group, so afterEach takes everything down
| with one kill whatever state a failed test left behind.
|
| Requires a running Redis instance.
|
*/

const TORQUE_FLEET_GROUP = 'fleet-group';
const TORQUE_FLEET_QUEUE = 'fleet';
const TORQUE_FLEET_DRAIN_GRACE = 10;

beforeEach(function () {
    $this->redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $this->prefix = "torque-fleet-{$token}:";
    $this->dir = sys_get_temp_dir()."/torque-fleet-{$token}";
    $this->master = null;

    mkdir($this->dir.'/storage/framework', 0777, true);
    file_put_contents($this->dir.'/artisan', "<?php\n\$runDir = __DIR__;\nrequire ".var_export(dirname(__DIR__).'/Fixtures/fleet-app.php', true).";\n");

    $this->queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: TORQUE_FLEET_QUEUE,
        retryAfter: 90,
        blockFor: 0,
        prefix: $this->prefix,
        consumerGroup: TORQUE_FLEET_GROUP,
    );
    $this->queue->setContainer(app());
    $this->queue->setConnectionName('torque');
    $this->queue->ensureConsumerGroup($this->queue->getStreamKey(), TORQUE_FLEET_GROUP);

    $this->output = fn (): string => (string) @file_get_contents($this->dir.'/output.log');

    /** @return list<array{type: string, run: int, pid: int}> */
    $this->jobs = fn (): array => array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        @file($this->dir.'/jobs.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    );

    $this->workers = function (): array {
        preg_match_all('/Worker spawned with PID (\d+)/', ($this->output)(), $matches);

        return array_map(intval(...), $matches[1]);
    };

    $this->waitFor = function (string $what, Closure $condition, float $seconds = 30.0): void {
        $deadline = microtime(true) + $seconds;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out waiting for {$what}. Output:\n".($this->output)());
            }

            usleep(50_000);
        }
    };

    $this->start = function (string $driver): int {
        file_put_contents($this->dir.'/config.json', json_encode([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix, 'cluster' => false],
            'consumer_group' => TORQUE_FLEET_GROUP,
            'serializer' => 'json',
            'event_loop' => $driver,
            'workers' => 2,
            'slots_per_worker' => 4,
            'queues' => [TORQUE_FLEET_QUEUE],
            'streams' => [TORQUE_FLEET_QUEUE => ['retry_after' => 90, 'max_retries' => 3]],
            'poll_interval_ms' => 100,
            'max_worker_lifetime' => 300,
            'max_worker_lifetime_jitter' => 0,
            'drain_grace_seconds' => TORQUE_FLEET_DRAIN_GRACE,
            'metrics' => ['enabled' => false],
            'autoscale' => ['enabled' => false],
        ], JSON_THROW_ON_ERROR));

        $log = $this->dir.'/output.log';

        $this->master = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/fleet-app.php', $this->dir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );

        if (! is_resource($this->master)) {
            throw new RuntimeException('Failed to start the master.');
        }

        return proc_get_status($this->master)['pid'];
    };
});

afterEach(function () {
    if (is_resource($this->master)) {
        $status = proc_get_status($this->master);

        // The fixture leads its own group: master, workers and anything they started.
        if ($status['running']) {
            @posix_kill(-$status['pid'], SIGKILL);
        }

        // Workers can outlive a master that died first: they keep the group.
        foreach (($this->workers)() as $worker) {
            @posix_kill($worker, SIGKILL);
        }

        proc_close($this->master);
    }

    (new Filesystem)->deleteDirectory($this->dir);

    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('runs a fleet on the configured loop driver and drains it on SIGTERM', function (string $driver) {
    if ($driver === 'poll' && ! PollDriver::isSupported()) {
        $this->markTestSkipped('Io\Poll has neither kqueue nor epoll here.');
    }

    $masterPid = ($this->start)($driver);

    ($this->waitFor)('two workers on the loop', fn (): bool => count(($this->workers)()) === 2
        && substr_count(($this->output)(), "[torque:worker] Event loop: {$driver}") === 2
        && str_contains(($this->output)(), 'Starting 4 reader Fibers'));

    expect(($this->output)())->toContain("Event loop: {$driver}")
        ->not->toContain('not applied');

    // Quick jobs first: the fleet picks up and finishes real work.
    foreach (range(1, 12) as $ignored) {
        $this->queue->push(new LoopStallProbeJob);
    }

    ($this->waitFor)('the quick jobs', fn (): bool => count(array_filter(($this->jobs)(), static fn (array $job): bool => $job['type'] === 'end')) === 12);

    $workers = ($this->workers)();

    expect(array_diff(array_column(($this->jobs)(), 'pid'), $workers))->toBe([]);

    // Then jobs that are still running when the stop arrives.
    foreach (range(1, 4) as $ignored) {
        $this->queue->push(new LoopStallProbeJob('suspend', 2));
    }

    ($this->waitFor)('jobs in flight', fn (): bool => count(array_filter(($this->jobs)(), static fn (array $job): bool => $job['type'] === 'run')) >= 14);

    $stoppedAt = microtime(true);
    posix_kill($masterPid, SIGTERM);

    ($this->waitFor)('the master to exit', fn (): bool => ! proc_get_status($this->master)['running'], TORQUE_FLEET_DRAIN_GRACE + 10);

    $runs = array_filter(($this->jobs)(), static fn (array $job): bool => $job['type'] === 'run');
    $ends = array_filter(($this->jobs)(), static fn (array $job): bool => $job['type'] === 'end');

    expect(proc_get_status($this->master)['exitcode'])->toBe(0)
        ->and(microtime(true) - $stoppedAt)->toBeLessThan(TORQUE_FLEET_DRAIN_GRACE)
        // Graceful: every job that started also finished.
        ->and(count($ends))->toBe(count($runs))
        ->and(array_filter($workers, static fn (int $pid): bool => posix_kill($pid, 0)))->toBe([])
        ->and(($this->output)())->not->toContain('killed by signal')
        ->not->toContain('Uncaught in event loop')
        ->not->toContain('Event loop crashed');
})->with(['poll', 'select']);
