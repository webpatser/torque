<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Redis\UpgradeRunner;

/*
 * The master checks the upgrade path first thing on start, then runs the data
 * upgrade right after the paused-key check and before it forks anything.
 *
 * The gate is driven through a real master and its real workers
 * (tests/Fixtures/loop-stall-app.php), since what it promises is an exit code and
 * the absence of a fleet. The best-effort upgrade goes through the private hook
 * rather than start(), which would fork a fleet for each assertion.
 *
 * This checkout reports itself as a dev install, which by design always runs
 * the steps; the once-per-version contract is therefore asserted with a runner
 * pinned to a release number.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-upgrade-master-'.bin2hex(random_bytes(4)).':';
    $this->versionKey = $this->prefix.'version';
    $this->logged = [];
    $this->realMaster = null;

    $this->config = [
        'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix],
        'consumer_group' => 'torque-test',
        'streams' => ['default' => []],
        'dead_letter' => ['ttl' => 60, 'max_entries' => 0],
        'job_streams' => ['ttl' => 300],
    ];

    $this->master = fn (): MasterProcess => new MasterProcess($this->config, function (string $message) {
        $this->logged[] = $message;
    });

    $this->upgrade = function (MasterProcess $master, ?UpgradeRunner $runner = null): void {
        (new ReflectionMethod($master, 'runDataUpgrade'))->invoke($master, $runner);
    };

    $this->pinnedRunner = fn (string $version): UpgradeRunner => new UpgradeRunner(
        redisUri: torqueRedisUri(),
        prefix: $this->prefix,
        logger: function (string $message) {
            $this->logged[] = $message;
        },
        currentVersion: $version,
    );

    $this->runDir = sys_get_temp_dir().'/torque-upgrade-'.bin2hex(random_bytes(4));
    mkdir($this->runDir.'/storage/framework', 0777, true);
    file_put_contents($this->runDir.'/artisan', "<?php\n\$runDir = __DIR__;\nrequire ".var_export(dirname(__DIR__, 2).'/Fixtures/loop-stall-app.php', true).";\n");

    $this->masterLog = fn (): string => (string) @file_get_contents($this->runDir.'/master.log');

    $this->startRealMaster = function (array $settings = []): void {
        file_put_contents($this->runDir.'/config.json', json_encode([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix, 'cluster' => false],
            'consumer_group' => 'torque-test',
            'workers' => 1,
            'streams' => ['default' => []],
        ] + $settings, JSON_THROW_ON_ERROR));

        $output = $this->runDir.'/output.log';

        $this->realMaster = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/loop-stall-app.php', $this->runDir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']],
            $pipes,
        );
    };

    // The exit code of a master expected to stop by itself.
    $this->realMasterExitCode = function (): int {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->realMaster);

            if (! $status['running']) {
                return $status['exitcode'];
            }

            usleep(50_000);
        }

        throw new RuntimeException('The master did not exit: '.($this->masterLog)());
    };

    // The PIDs of the workers a live master actually forked.
    $this->realWorkers = function (): array {
        exec('pgrep -P '.proc_get_status($this->realMaster)['pid'], $pids);

        return array_map(intval(...), $pids);
    };

    // Long enough for a start without Redis: each of the master's four startup
    // reads spends about seven seconds on the client's connect retries.
    $this->waitForRealWorker = function (): void {
        $deadline = microtime(true) + 60;

        while (($this->realWorkers)() === []) {
            if (microtime(true) > $deadline || ! proc_get_status($this->realMaster)['running']) {
                throw new RuntimeException('The master forked no worker: '.($this->masterLog)());
            }

            usleep(50_000);
        }
    };
});

afterEach(function () {
    if (is_resource($this->realMaster)) {
        $status = proc_get_status($this->realMaster);

        if ($status['running']) {
            $workers = ($this->realWorkers)();
            posix_kill($status['pid'], SIGKILL);

            foreach ($workers as $worker) {
                posix_kill($worker, SIGKILL);
            }
        }

        proc_close($this->realMaster);
    }

    (new Filesystem)->deleteDirectory($this->runDir);

    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('records the version on start', function () {
    ($this->upgrade)(($this->master)());

    expect((string) $this->redis->execute('GET', $this->versionKey))
        ->toBe(UpgradeRunner::installedVersion())
        ->and(implode("\n", $this->logged))->toContain('Upgrading Torque data');
});

it('does nothing on the next start at the same version', function () {
    $master = ($this->master)();
    ($this->upgrade)($master, ($this->pinnedRunner)('2.0.0'));

    $this->logged = [];

    ($this->upgrade)($master, ($this->pinnedRunner)('2.0.0'));

    expect($this->logged)->toBe([])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.0.0');
});

it('records a newer version on the next start', function () {
    $master = ($this->master)();
    ($this->upgrade)($master, ($this->pinnedRunner)('2.0.0'));

    $this->logged = [];

    ($this->upgrade)($master, ($this->pinnedRunner)('2.1.0'));

    expect((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.1.0')
        ->and(implode("\n", $this->logged))->toContain('Upgrading Torque data from 2.0.0 to 2.1.0');
});

it('never blocks startup when redis is unreachable', function () {
    $master = new MasterProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6399', 'prefix' => 'torque-test-upgrade-down:'],
        'streams' => ['default' => []],
    ], function (string $message) {
        $this->logged[] = $message;
    });

    ($this->upgrade)($master);

    expect(implode("\n", $this->logged))->toContain('Data upgrade skipped');
});

it('refuses to start on a stored version below the minimum', function () {
    $this->redis->execute('SET', $this->versionKey, '1.3.9');

    ($this->startRealMaster)();

    expect(($this->realMasterExitCode)())->toBe(1)
        ->and(($this->masterLog)())
        ->toContain('Refusing to start')
        ->toContain('last upgraded by 1.3.9')
        ->toContain("SET {$this->prefix}version 1.4.0")
        ->not->toContain('Starting 1 worker')
        ->and(file_exists($this->runDir.'/storage/torque.pid'))->toBeFalse()
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('1.3.9');
});

it('starts a fleet on the minimum stored version and moves the version on', function () {
    $this->redis->execute('SET', $this->versionKey, '1.4.0');

    ($this->startRealMaster)();
    ($this->waitForRealWorker)();

    expect(($this->masterLog)())->toContain('Starting 1 worker')
        ->not->toContain('Refusing to start')
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe(UpgradeRunner::installedVersion());
});

it('starts a fleet when the stored version is dev or missing', function (?string $stored) {
    if ($stored !== null) {
        $this->redis->execute('SET', $this->versionKey, $stored);
    }

    ($this->startRealMaster)();
    ($this->waitForRealWorker)();

    expect(($this->masterLog)())->toContain('Starting 1 worker')
        ->not->toContain('Refusing to start');
})->with(['dev' => 'dev', 'missing' => null]);

it('starts a fleet when the master cannot reach redis, leaving the stored version alone', function () {
    $this->redis->execute('SET', $this->versionKey, '1.3.9');

    ($this->startRealMaster)(['master_redis_uri' => 'redis://127.0.0.1:6399']);
    ($this->waitForRealWorker)();

    expect(($this->masterLog)())->toContain('Upgrade path check skipped')
        ->toContain('Data upgrade skipped')
        ->toContain('Starting 1 worker')
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('1.3.9');
});
