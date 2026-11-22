<?php

declare(strict_types=1);

/*
 * Points a fixture's own application at the Redis and key prefix its parent test
 * handed it, so nothing it records (job streams, jobs:recent, jobs:active, metrics,
 * dead letters) falls back to the config defaults of the developer's shared Redis.
 *
 * The provider builds the job stream recorder while it boots, and a Testbench app
 * boots a provider the moment it is registered, so call this before
 * Application::create(): torque.redis.uri and torque.redis.prefix then come from
 * TORQUE_REDIS_URI and TORQUE_PREFIX instead of their defaults.
 */
function torque_fixture_use_redis(string $uri, string $prefix): void
{
    foreach (['TORQUE_REDIS_URI' => $uri, 'TORQUE_PREFIX' => $prefix] as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}

/**
 * Fails the fixture loudly when its application still sees another Redis than
 * the one its parent test handed it. Call this after the providers are registered.
 */
function torque_fixture_assert_redis(string $uri, string $prefix): void
{
    if (config('torque.redis.uri') !== $uri || config('torque.redis.prefix') !== $prefix) {
        fwrite(STDERR, 'Fixture app is not on the test Redis: '.config('torque.redis.uri').' '.config('torque.redis.prefix')."\n");

        exit(97);
    }
}
