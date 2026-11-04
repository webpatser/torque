<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Psr\Log\NullLogger;
use Webpatser\Torque\Job\AttemptAuthManager;
use Webpatser\Torque\Job\AttemptLogger;
use Webpatser\Torque\Job\AttemptLogManager;

/**
 * Instance properties of a class that a routed subclass neither routes nor
 * knowingly shares.
 *
 * @param  class-string  $class
 * @param  list<string>  $routed
 * @param  list<string>  $shared
 * @return list<string>
 */
function torque_unaccounted_service_state(string $class, array $routed, array $shared): array
{
    $properties = array_filter(
        (new ReflectionClass($class))->getProperties(),
        static fn (ReflectionProperty $property): bool => ! $property->isStatic(),
    );

    return array_values(array_diff(
        array_map(static fn (ReflectionProperty $property): string => $property->getName(), $properties),
        $routed,
        $shared,
    ));
}

dataset('routed services', [
    'log manager' => [LogManager::class, AttemptLogManager::ROUTED, AttemptLogManager::SHARED],
    'logger' => [Logger::class, AttemptLogger::ROUTED, AttemptLogger::SHARED],
    'auth manager' => [AuthManager::class, AttemptAuthManager::ROUTED, AttemptAuthManager::SHARED],
]);

it('accounts for all the instance state of the framework\'s class', function (string $class, array $routed, array $shared) {
    expect(torque_unaccounted_service_state($class, $routed, $shared))->toBe([]);
})->with('routed services');

it('knows the framework\'s class has no static state', function (string $class) {
    expect((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC))->toBe([]);
})->with('routed services');

it('flags instance state the routed subclass does not account for', function (object $upstream, array $routed, array $shared) {
    expect(torque_unaccounted_service_state($upstream::class, $routed, $shared))->toBe(['addedByUpstream']);
})->with([
    'log manager' => [
        new class(null) extends LogManager
        {
            protected $addedByUpstream = [];
        },
        AttemptLogManager::ROUTED,
        AttemptLogManager::SHARED,
    ],
    'logger' => [
        new class(new NullLogger) extends Logger
        {
            protected $addedByUpstream = [];
        },
        AttemptLogger::ROUTED,
        AttemptLogger::SHARED,
    ],
    'auth manager' => [
        new class(null) extends AuthManager
        {
            protected $addedByUpstream = [];
        },
        AttemptAuthManager::ROUTED,
        AttemptAuthManager::SHARED,
    ],
]);
