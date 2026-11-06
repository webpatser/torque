<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Symfony\Component\Process\Process;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Support\LegacyConfig;

$publishedOldKeys = [
    'coroutines_per_worker' => 1,
    'block_for' => 750,
    'redis' => ['uri' => 'redis://127.0.0.1:6379', 'read_timeout' => 12.5],
    'takeover_ready_timeout' => 45,
    'job_streams' => ['enabled' => false],
    'job_limits' => 'stream',
];

$migrated = function (array $torque): array {
    $config = new Repository(['torque' => $torque]);

    LegacyConfig::migrate($config);

    return $config->get('torque');
};

it('moves an old key to its new key and records it', function (array $torque, string $old, string $new, mixed $value) use ($migrated) {
    $result = $migrated($torque);

    expect(Arr::get($result, $new))->toBe($value)
        ->and(Arr::has($result, $old))->toBeFalse()
        ->and($result['_legacy_keys'])->toBe([$old => $new]);
})->with([
    'coroutines_per_worker, as in demarcify' => [['coroutines_per_worker' => 1, 'slots_per_worker' => 50], 'coroutines_per_worker', 'slots_per_worker', 1],
    'block_for' => [['block_for' => 750, 'poll_interval_ms' => 2000], 'block_for', 'poll_interval_ms', 750],
    'redis.read_timeout' => [['redis' => ['read_timeout' => 12.5, 'read_timeout_seconds' => 30.0]], 'redis.read_timeout', 'redis.read_timeout_seconds', 12.5],
    'takeover_ready_timeout' => [['takeover_ready_timeout' => 45, 'takeover_ready_timeout_seconds' => 30], 'takeover_ready_timeout', 'takeover_ready_timeout_seconds', 45],
    'job_streams' => [['job_streams' => ['ttl' => 9], 'job_events' => ['ttl' => 300]], 'job_streams', 'job_events', ['ttl' => 9]],
    'job_limits' => [['job_limits' => 'stream', 'honour_job_limits' => true], 'job_limits', 'honour_job_limits', false],
]);

it('maps job_limits to a bool the way the 1.x comparison to stream did', function (mixed $old, bool $honoured) use ($migrated) {
    expect($migrated(['job_limits' => $old])['honour_job_limits'])->toBe($honoured);
})->with([
    'job' => ['job', true],
    'stream' => ['stream', false],
    'an unknown string' => ['bogus', true],
    'another case' => ['Stream', true],
    'null' => [null, true],
]);

it('keeps the rest of a published redis array when it moves read_timeout', function () use ($migrated) {
    expect($migrated(['redis' => ['uri' => 'redis://x', 'read_timeout' => 5]])['redis'])
        ->toBe(['uri' => 'redis://x', 'read_timeout_seconds' => 5]);
});

it('changes nothing and records nothing when no old key is present', function () use ($migrated) {
    $torque = ['slots_per_worker' => 7, 'redis' => ['read_timeout_seconds' => 30], 'honour_job_limits' => false];

    expect($migrated($torque))->toBe($torque);
});

it('does not touch the block_for of a queue connection', function () {
    $config = new Repository([
        'torque' => ['block_for' => 750],
        'queue' => ['connections' => ['torque' => ['block_for' => 100]]],
    ]);

    LegacyConfig::migrate($config);

    expect($config->get('queue.connections.torque.block_for'))->toBe(100)
        ->and($config->get('torque.poll_interval_ms'))->toBe(750);
});

it('is idempotent and keeps what an earlier pass recorded', function () use ($migrated, $publishedOldKeys) {
    $once = $migrated($publishedOldKeys);

    expect($once['_legacy_keys'])->toHaveCount(6)
        ->and($migrated($once))->toBe($once);
});

it('adds to what an earlier pass recorded when a later one finds another old key', function () use ($migrated) {
    $result = $migrated(['_legacy_keys' => ['block_for' => 'poll_interval_ms'], 'job_limits' => 'job']);

    expect($result['_legacy_keys'])->toBe(['block_for' => 'poll_interval_ms', 'job_limits' => 'honour_job_limits']);
});

it('records nothing for a booted app without old keys of its own', function () {
    expect(config('torque._legacy_keys'))->toBeNull();
});

describe('through a real application and config:cache', function () use ($publishedOldKeys) {
    beforeEach(function () {
        $this->runDir = sys_get_temp_dir().'/torque-legacy-'.bin2hex(random_bytes(4));

        mkdir($this->runDir.'/bootstrap/cache', recursive: true);
        mkdir($this->runDir.'/config');
        mkdir($this->runDir.'/storage/framework/views', recursive: true);

        $this->publish = function (array $torque): void {
            file_put_contents($this->runDir.'/config/torque.php', '<?php return '.var_export($torque, true).';');
        };

        $this->run = function (string $action): void {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/legacy-config-app.php', $this->runDir, $action], timeout: 30);

            $process->mustRun();
        };

        $this->seen = fn (): array => json_decode((string) file_get_contents($this->runDir.'/config.json'), true, flags: JSON_THROW_ON_ERROR);
    });

    afterEach(function () {
        (new Filesystem)->deleteDirectory($this->runDir);
    });

    it('moves published keys and keeps the record across the cache', function () use ($publishedOldKeys) {
        ($this->publish)($publishedOldKeys);

        ($this->run)('cache');

        expect($this->runDir.'/bootstrap/cache/config.php')->toBeFile();

        // The published file is gone, so what follows can only come from the cache.
        unlink($this->runDir.'/config/torque.php');

        ($this->run)('dump');

        $seen = ($this->seen)();

        expect($seen['cached'])->toBeTrue()
            ->and($seen['torque']['slots_per_worker'])->toBe(1)
            ->and($seen['torque']['poll_interval_ms'])->toBe(750)
            ->and($seen['torque']['redis']['read_timeout_seconds'])->toBe(12.5)
            ->and($seen['torque']['takeover_ready_timeout_seconds'])->toBe(45)
            ->and($seen['torque']['job_events'])->toBe(['enabled' => false])
            ->and($seen['torque']['honour_job_limits'])->toBeFalse()
            ->and($seen['torque'])->not->toHaveKey('coroutines_per_worker')
            ->and($seen['torque'])->not->toHaveKey('block_for')
            ->and($seen['torque'])->not->toHaveKey('job_limits')
            ->and($seen['torque'])->not->toHaveKey('job_streams')
            ->and($seen['torque'])->not->toHaveKey('takeover_ready_timeout')
            ->and($seen['torque']['redis'])->not->toHaveKey('read_timeout')
            ->and($seen['torque']['_legacy_keys'])->toBe(LegacyConfig::RENAMES)
            ->and(LegacyConfig::warning($seen['torque']))->toContain('coroutines_per_worker -> slots_per_worker');
    });

    it('moves published keys without a cache as well', function () use ($publishedOldKeys) {
        ($this->publish)($publishedOldKeys);

        ($this->run)('dump');

        $seen = ($this->seen)();

        expect($seen['cached'])->toBeFalse()
            ->and($seen['torque']['slots_per_worker'])->toBe(1)
            ->and($seen['torque']['_legacy_keys'])->toBe(LegacyConfig::RENAMES);
    });

    it('records nothing when nothing is published, cached or not', function (bool $cache) {
        if ($cache) {
            ($this->run)('cache');
        }

        ($this->run)('dump');

        $seen = ($this->seen)();

        expect($seen['cached'])->toBe($cache)
            ->and($seen['torque'])->not->toHaveKey('_legacy_keys')
            ->and(LegacyConfig::warning($seen['torque']))->toBeNull();
    })->with(['not cached' => false, 'cached' => true]);

    it('records only what a partial published file holds', function () {
        ($this->publish)(['coroutines_per_worker' => 1, 'workers' => 2]);

        ($this->run)('cache');
        ($this->run)('dump');

        expect(($this->seen)()['torque']['_legacy_keys'])->toBe(['coroutines_per_worker' => 'slots_per_worker']);
    });
});

describe('the boot warning of the master', function () {
    beforeEach(function () {
        $this->redis = torqueRedis();
        $this->prefix = 'torque-test-legacy-'.bin2hex(random_bytes(4)).':';

        // A stored version below the minimum refuses the start before it forks.
        $this->redis->execute('SET', $this->prefix.'version', '1.3.9');

        $this->logsOfStart = function (array $extra): array {
            $logged = [];

            $master = new MasterProcess(
                ['redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix], 'streams' => ['default' => []], ...$extra],
                function (string $message) use (&$logged): void {
                    $logged[] = $message;
                },
            );

            expect($master->start())->toBe(1);

            return $logged;
        };
    });

    afterEach(function () {
        $this->redis->execute('DEL', $this->prefix.'version');
    });

    it('lists the recorded keys and names 3.0', function () {
        $logged = implode("\n", ($this->logsOfStart)(['_legacy_keys' => [
            'coroutines_per_worker' => 'slots_per_worker',
            'redis.read_timeout' => 'redis.read_timeout_seconds',
        ]]));

        expect($logged)
            ->toContain('WARN Renamed config keys in use')
            ->toContain('coroutines_per_worker -> slots_per_worker, redis.read_timeout -> redis.read_timeout_seconds')
            ->toContain('Torque 3.0 makes this a boot failure');
    });

    it('stays silent without recorded keys', function (array $extra) {
        expect(implode("\n", ($this->logsOfStart)($extra)))->not->toContain('Renamed config keys');
    })->with(['absent' => [[]], 'empty' => [['_legacy_keys' => []]]]);
});
