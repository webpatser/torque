<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Redis\UpgradeRunner;

/*
 * The master runs the data upgrade on start, right after the paused-key check
 * and before it forks anything. Driven here through the private hook rather
 * than start(), which would fork a fleet.
 *
 * This checkout reports itself as a dev install, which by design always runs
 * the steps; the once-per-version contract is therefore asserted with a runner
 * pinned to a release number.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-upgrade-master-'.bin2hex(random_bytes(4)).':';
    $this->logged = [];

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
        housekeeper: StreamHousekeeper::fromConfig($this->config),
        logger: function (string $message) {
            $this->logged[] = $message;
        },
        currentVersion: $version,
    );

    $this->seedOrphan = function (string $uuid): void {
        $this->redis->execute(
            'XADD',
            $this->prefix.'job:'.$uuid,
            ((time() - 7200) * 1000).'-0',
            'type', 'started',
        );
    };
});

afterEach(function () {
    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('cleans up leftovers and records the version on start', function () {
    ($this->seedOrphan)('abandoned');

    ($this->upgrade)(($this->master)());

    expect((int) $this->redis->execute('EXISTS', $this->prefix.'job:abandoned'))->toBe(0)
        ->and((string) $this->redis->execute('GET', $this->prefix.'version'))
        ->toBe(UpgradeRunner::installedVersion())
        ->and(implode("\n", $this->logged))->toContain('Upgrading Torque data');
});

it('does nothing on the next start at the same version', function () {
    $master = ($this->master)();
    ($this->upgrade)($master, ($this->pinnedRunner)('0.16.0'));

    $this->logged = [];
    ($this->seedOrphan)('later');

    ($this->upgrade)($master, ($this->pinnedRunner)('0.16.0'));

    expect($this->logged)->toBe([])
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'job:later'))->toBe(1);
});

it('records a newer version without repeating steps that already ran', function () {
    $master = ($this->master)();
    ($this->upgrade)($master, ($this->pinnedRunner)('0.16.0'));

    $this->logged = [];
    ($this->seedOrphan)('after-deploy');

    ($this->upgrade)($master, ($this->pinnedRunner)('0.17.0'));

    // 0.17.0 ships no cleanup step of its own, so the 0.16.0 sweep is not
    // repeated; the version key still moves forward.
    expect((int) $this->redis->execute('EXISTS', $this->prefix.'job:after-deploy'))->toBe(1)
        ->and((string) $this->redis->execute('GET', $this->prefix.'version'))->toBe('0.17.0')
        ->and(implode("\n", $this->logged))->toContain('Upgrading Torque data from 0.16.0 to 0.17.0');
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
