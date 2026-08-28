<?php

declare(strict_types=1);

use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Redis\UpgradeRunner;

/*
 * The upgrade runner decides, from the version recorded in `{prefix}version`,
 * which data-cleanup steps a deploy still owes. It has to be exactly once per
 * version: the master calls it on every start.
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
            housekeeper: new StreamHousekeeper(
                redisUri: torqueRedisUri(),
                prefix: $this->prefix,
                consumerGroup: 'torque-test',
                queues: ['default'],
                deadLetters: new DeadLetterHandler(
                    redisUri: torqueRedisUri(),
                    prefix: $this->prefix,
                    maxEntries: 0,
                ),
            ),
            logger: function (string $message) {
                $this->logged[] = $message;
            },
            currentVersion: $current,
            steps: $steps ?? [
                '0.16.0' => function (): array {
                    $this->ran[] = '0.16.0';

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
    $result = ($this->runner)('0.16.0')->run();

    expect($result['ran'])->toBeTrue()
        ->and($result['from'])->toBeNull()
        ->and($result['to'])->toBe('0.16.0')
        ->and($result['counts'])->toBe(['job_streams' => 2, 'legacy_keys' => 1])
        ->and($this->ran)->toBe(['0.16.0'])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('0.16.0')
        ->and($this->logged[0])->toContain('Upgrading Torque data from an unrecorded version to 0.16.0')
        ->and($this->logged[1])->toContain('job streams: 2');
});

it('does nothing on a second start at the same version', function () {
    ($this->runner)('0.16.0')->run();
    $this->logged = [];
    $this->ran = [];

    $result = ($this->runner)('0.16.0')->run();

    expect($result['ran'])->toBeFalse()
        ->and($this->ran)->toBe([])
        ->and($this->logged)->toBe([]);
});

it('runs every step newer than the stored version', function () {
    $this->redis->execute('SET', $this->versionKey, '0.15.0');
    $seen = [];

    $steps = [
        '0.15.0' => function () use (&$seen): array {
            $seen[] = '0.15.0';

            return [];
        },
        '0.16.0' => function () use (&$seen): array {
            $seen[] = '0.16.0';

            return ['job_streams' => 1];
        },
        '0.17.0' => function () use (&$seen): array {
            $seen[] = '0.17.0';

            return ['job_streams' => 2];
        },
    ];

    $result = ($this->runner)('0.17.0', $steps)->run();

    expect($seen)->toBe(['0.16.0', '0.17.0'])
        ->and($result['counts'])->toBe(['job_streams' => 3])
        ->and($result['from'])->toBe('0.15.0')
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('0.17.0');
});

it('leaves a newer stored version alone and says so', function () {
    $this->redis->execute('SET', $this->versionKey, '0.17.0');

    $result = ($this->runner)('0.16.0')->run();

    expect($result['ran'])->toBeFalse()
        ->and($this->ran)->toBe([])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('0.17.0')
        ->and($this->logged[0])->toContain('newer than the installed 0.16.0');
});

it('always runs on a dev install and stores dev', function () {
    ($this->runner)('dev')->run();
    ($this->runner)('dev')->run();

    expect($this->ran)->toBe(['0.16.0', '0.16.0'])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('dev');
});

it('runs again when a release is deployed over a dev install', function () {
    $this->redis->execute('SET', $this->versionKey, 'dev');

    $result = ($this->runner)('0.16.0')->run();

    expect($result['ran'])->toBeTrue()
        ->and($this->ran)->toBe(['0.16.0'])
        ->and((string) $this->redis->execute('GET', $this->versionKey))->toBe('0.16.0');
});

it('reads the stored version back', function () {
    expect(($this->runner)('0.16.0')->storedVersion())->toBeNull();

    ($this->runner)('0.16.0')->run();

    expect(($this->runner)('0.16.0')->storedVersion())->toBe('0.16.0');
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
