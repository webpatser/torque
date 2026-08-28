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
