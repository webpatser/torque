<?php

declare(strict_types=1);

use Fledge\Async\Redis\RedisClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Webpatser\Torque\Tests\TestCase;

use function Fledge\Async\Redis\createRedisClient;

pest()->extend(TestCase::class)->in('Feature', 'Integration');

/**
 * The Redis instance the suite runs against (DB 15 by default).
 */
function torqueRedisUri(): string
{
    return env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
}

/**
 * Open a connection to the test Redis, skipping the test when it is down.
 *
 * Lives here rather than in a single test file so parallel workers, which
 * only load their own subset of test files, always have it available.
 */
function torqueRedis(): RedisClient
{
    try {
        $redis = createRedisClient(torqueRedisUri());
        $redis->execute('PING');

        return $redis;
    } catch (Throwable $e) {
        test()->markTestSkipped('Redis not available: '.$e->getMessage());
    }
}

/**
 * Spawn a fake Torque master that ignores the given signals.
 *
 * The process carries "torque:start" in argv from exec time so it passes
 * `MasterProcess::readPid()`'s identity check, and it deliberately does NOT
 * contain "artisan torque:start", so the pgrep orphan sweeps in
 * torque:start/torque:stop never target it.
 *
 * Shutdown-deadline tests need a master that stays alive through the signal
 * that asks it to stop; PHP CLI installs no handler for SIGUSR2 or SIGTERM,
 * so the plain helper would die on the first one. The child touches a marker
 * file once its dispositions are installed and this function waits for it,
 * because a signal landing during interpreter startup would still kill it and
 * turn the test into a flake.
 *
 * Lives here rather than in a single test file because more than one test
 * file uses it, and parallel workers only load their own subset of files.
 *
 * @param  list<int>  $ignoreSignals  Signals the fake master must survive.
 */
function spawnDeafTorqueMaster(int $seconds = 30, array $ignoreSignals = []): int
{
    $marker = tempnam(sys_get_temp_dir(), 'torque-deaf-master-');

    if ($marker === false) {
        throw new RuntimeException('Failed to create the readiness marker.');
    }

    unlink($marker);

    $ignores = implode('', array_map(
        static fn (int $signal): string => "pcntl_signal({$signal}, SIG_IGN); ",
        $ignoreSignals,
    ));

    $pipes = [];

    $proc = proc_open(
        [
            PHP_BINARY,
            '-r',
            $ignores.'cli_set_process_title("torque:start"); touch($argv[2]); sleep((int) $argv[1]);',
            (string) $seconds,
            $marker,
        ],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ],
        $pipes,
    );

    if (! is_resource($proc)) {
        throw new RuntimeException('Failed to spawn the fake Torque master.');
    }

    $status = proc_get_status($proc);
    $pid = (int) $status['pid'];

    $deadline = microtime(true) + 10;

    while (microtime(true) < $deadline) {
        clearstatcache(true, $marker);

        if (file_exists($marker)) {
            @unlink($marker);

            return $pid;
        }

        usleep(20_000);
    }

    posix_kill($pid, SIGKILL);
    pcntl_waitpid($pid, $ignored);

    throw new RuntimeException('Fake Torque master never reported ready.');
}

/**
 * A minimal authenticatable user for dashboard route tests.
 *
 * The dashboard middleware defaults to `['web', 'auth']`; authenticating with
 * this stub lets the auth middleware pass so the `viewTorque` gate is what
 * decides 200 vs 403 (rather than an auth redirect).
 */
function torqueTestUser(): Authenticatable
{
    return new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 1;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }
    };
}

/**
 * The raw Blade source of a dashboard screen.
 *
 * Some markup only renders with Redis-backed rows, so the chrome guards assert
 * on the template itself. Lives here (not in a test file) because parallel
 * workers only load the test files they own.
 */
function torqueDashboardView(string $name): string
{
    $path = __DIR__.'/../src/Dashboard/resources/views/dashboard/'.$name.'.blade.php';

    return (string) file_get_contents($path);
}
