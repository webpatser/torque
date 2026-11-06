<?php

declare(strict_types=1);

use Webpatser\Torque\Redis\UpgradeRunner;

/*
 * The upgrade runner decides, from the version recorded in `{prefix}version`,
 * whether a start may go ahead at all and which data-cleanup steps a deploy
 * still owes. The steps have to run exactly once per version: the master calls
 * it on every start.
 *
 * Steps are injected here as plain callables so the decision logic can be
 * driven across several versions without inventing releases.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-upgrade-'.bin2hex(random_bytes(4)).':';
    $this->versionKey = $this->prefix.'version';
    $this->logged = [];
    $this->ran = [];

    $this->runner = function (string $current, ?array $steps = null): UpgradeRunner {
        return new UpgradeRunner(
            redisUri: torqueRedisUri(),
            prefix: $this->prefix,
            logger: function (string $message) {
                $this->logged[] = $message;
            },
            currentVersion: $current,
            steps: $steps ?? [
                '2.0.0' => function (): array {
                    $this->ran[] = '2.0.0';

                    return ['job_streams' => 2, 'legacy_keys' => 1];
                },
            ],
        );
    };
});

afterEach(function () {
    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('runs the steps and records the version on a fresh install', function () {
    $result = ($this->runner)('2.0.0')->run();

    expect($result['ran'])->toBeTrue()
        ->and($result['from'])->toBeNull()
        ->and($result['to'])->toBe('2.0.0')
        ->and($result['counts'])->toBe(['job_streams' => 2, 'legacy_keys' => 1])
        ->and($this->ran)->toBe(['2.0.0'])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.0.0')
        ->and($this->logged[0])->toContain('Upgrading Torque data from an unrecorded version to 2.0.0')
        ->and($this->logged[1])->toContain('job streams: 2');
});

it('does nothing on a second start at the same version', function () {
    ($this->runner)('2.0.0')->run();
    $this->logged = [];
    $this->ran = [];

    $result = ($this->runner)('2.0.0')->run();

    expect($result['ran'])->toBeFalse()
        ->and($this->ran)->toBe([])
        ->and($this->logged)->toBe([]);
});

it('runs every step newer than the stored version', function () {
    $this->redis->execute('SET', $this->versionKey, '1.4.0');
    $seen = [];

    $steps = [
        '1.4.0' => function () use (&$seen): array {
            $seen[] = '1.4.0';

            return [];
        },
        '2.0.0' => function () use (&$seen): array {
            $seen[] = '2.0.0';

            return ['job_streams' => 1];
        },
        '2.1.0' => function () use (&$seen): array {
            $seen[] = '2.1.0';

            return ['job_streams' => 2];
        },
    ];

    $result = ($this->runner)('2.1.0', $steps)->run();

    expect($seen)->toBe(['2.0.0', '2.1.0'])
        ->and($result['counts'])->toBe(['job_streams' => 3])
        ->and($result['from'])->toBe('1.4.0')
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.1.0');
});

it('leaves a newer stored version alone and says so', function () {
    $this->redis->execute('SET', $this->versionKey, '2.1.0');

    $result = ($this->runner)('2.0.0')->run();

    expect($result['ran'])->toBeFalse()
        ->and($this->ran)->toBe([])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.1.0')
        ->and($this->logged[0])->toContain('newer than the installed 2.0.0');
});

it('always runs on a dev install and stores dev', function () {
    ($this->runner)('dev')->run();
    ($this->runner)('dev')->run();

    expect($this->ran)->toBe(['2.0.0', '2.0.0'])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('dev');
});

it('runs again when a release is deployed over a dev install', function () {
    $this->redis->execute('SET', $this->versionKey, 'dev');

    $result = ($this->runner)('2.0.0')->run();

    expect($result['ran'])->toBeTrue()
        ->and($this->ran)->toBe(['2.0.0'])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.0.0');
});

it('reads the stored version back', function () {
    expect(($this->runner)('2.0.0')->storedVersion())->toBeNull();

    ($this->runner)('2.0.0')->run();

    expect(($this->runner)('2.0.0')->storedVersion())->toBe('2.0.0');
});

it('normalizes the installed version', function () {
    // Whatever composer reports for this checkout, it is either a release
    // number without a leading v, or the literal dev fallback.
    $version = UpgradeRunner::installedVersion();

    expect($version === 'dev' || preg_match('/^\d+\.\d+/', $version) === 1)->toBeTrue()
        ->and($version)->not->toStartWith('v');
});

it('renders counts as a readable line', function () {
    expect(UpgradeRunner::describe(['job_streams' => 1200, 'legacy_keys' => 0]))
        ->toBe('job streams: 1,200, legacy keys: 0');
});

/*
 * The default step map is empty: every start only records the version.
 */

it('owes no steps of its own on a fresh install', function () {
    $result = UpgradeRunner::fromConfig(
        ['redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix]],
        fn (string $message) => null,
        '2.0.0',
    )->run();

    expect($result['ran'])->toBeTrue()
        ->and($result['counts'])->toBe([])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.0.0');
});

it('no longer touches the pre-rollup buckets hash on an upgrade', function () {
    $this->redis->execute('SET', $this->versionKey, '1.4.0');
    $this->redis->execute('HSET', $this->prefix.'metrics:buckets', '29000000', '5');

    $result = UpgradeRunner::fromConfig(
        ['redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix]],
        fn (string $message) => null,
        '2.0.0',
    )->run();

    expect($result['counts'])->toBe([])
        ->and((int) $this->redis->execute('EXISTS', $this->prefix.'metrics:buckets'))->toBe(1)
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.0.0');
});

/*
 * The upgrade gate. Only the leading release number counts, padded to three
 * segments, so neither a suffix nor a fourth segment can decide.
 */

it('refuses a stored release below the minimum', function (string $stored) {
    $this->redis->execute('SET', $this->versionKey, $stored);

    expect(fn () => ($this->runner)('2.0.0')->assertUpgradePath())
        ->toThrow(DomainException::class, "last upgraded by {$stored}")
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe($stored);
})->with([
    'old minor' => '1.3.9',
    'first with rollups' => '1.0.0',
    'pre 1.0' => '0.16.0',
    'two segments' => '1.3',
    'one segment' => '1',
    'four segments' => '1.3.9.9',
    'pre-release of an older release' => '1.3.9-rc.1',
    'branch alias of an older release' => '1.3.x-dev',
    'leading v' => 'v1.3.0',
]);

it('names the path in the refusal, with the real prefix', function () {
    $this->redis->execute('SET', $this->versionKey, '1.3.9');

    expect(fn () => ($this->runner)('2.0.0')->assertUpgradePath())->toThrow(
        DomainException::class,
        "Refusing to start: Torque data in Redis was last upgraded by 1.3.9, below 1.4.0, the oldest release this version upgrades from. Upgrade through 1.4.x first and start a master on it once, or skip the removed upgrade steps with: SET {$this->prefix}version 1.4.0",
    );
});

it('refuses on an installed dev checkout too', function () {
    $this->redis->execute('SET', $this->versionKey, '1.3.9');

    expect(fn () => ($this->runner)('dev')->assertUpgradePath())->toThrow(DomainException::class);
});

it('passes a stored release at or above the minimum', function (string $stored) {
    $this->redis->execute('SET', $this->versionKey, $stored);

    ($this->runner)('2.0.0')->assertUpgradePath();

    expect((string) $this->redis->execute('GET', $this->versionKey))->toBe($stored)
        ->and($this->logged)->toBe([]);
})->with([
    'the minimum' => '1.4.0',
    'the documented escape hatch' => '1.4.0',
    'a patch above' => '1.4.1',
    'two segments' => '1.4',
    'four segments' => '1.4.0.1',
    'pre-release of the minimum' => '1.4.0-rc.1',
    'branch alias of the minimum' => '1.4.x-dev',
    'leading v' => 'v1.4.0',
    'a higher minor, numerically' => '1.10.0',
    'pre-release of a major' => '2.0.0-beta.1',
    'a major' => '2.0.0',
    'dev' => 'dev',
    'nothing comparable' => 'nightly',
]);

it('passes when nothing is stored', function () {
    ($this->runner)('2.0.0')->assertUpgradePath();

    expect($this->redis->execute('GET', $this->versionKey))->toBeNull()
        ->and($this->logged)->toBe([]);
});

it('passes when redis is unreachable', function () {
    $runner = new UpgradeRunner(
        redisUri: 'redis://127.0.0.1:6399',
        prefix: $this->prefix,
        logger: function (string $message) {
            $this->logged[] = $message;
        },
        currentVersion: '2.0.0',
    );

    $runner->assertUpgradePath();

    expect(implode("\n", $this->logged))->toContain('Upgrade path check skipped');
});

it('short-circuits at the same version from the minimum up', function (string $version) {
    $this->redis->execute('SET', $this->versionKey, $version);

    $runner = ($this->runner)($version);
    $runner->assertUpgradePath();
    $result = $runner->run();

    expect($result['ran'])->toBeFalse()
        ->and($this->ran)->toBe([])
        ->and($this->logged)->toBe([])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe($version);
})->with(['1.4.0', '1.4.1', '1.4.0.1', '2.0.0-beta.1', '2.0.0']);

it('moves on from the minimum to a newer release', function () {
    $this->redis->execute('SET', $this->versionKey, '1.4.0');

    $runner = ($this->runner)('2.0.0');
    $runner->assertUpgradePath();
    $result = $runner->run();

    expect($result['ran'])->toBeTrue()
        ->and($result['from'])->toBe('1.4.0')
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('2.0.0');
});

it('never overwrites a stored version below the minimum, even when run directly', function () {
    // The gate passes during an outage; if Redis is back by the time the
    // upgrade runs, the next start must still be able to refuse.
    $this->redis->execute('SET', $this->versionKey, '1.3.9');

    $result = ($this->runner)('2.0.0')->run();

    expect($result['ran'])->toBeFalse()
        ->and($this->ran)->toBe([])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('1.3.9')
        ->and($this->logged[0])->toContain('Refusing to start');
});
