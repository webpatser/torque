<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Illuminate\Cache\MemoizedStore;
use Illuminate\Contracts\Container\Container;

/**
 * Gives Cache::memo() one memo per attempt in a worker running concurrent jobs in
 * one process.
 *
 * The framework binds a scoped repository around a {@see MemoizedStore} per store,
 * the first time someone asks for it, so slots would read each other's memoized
 * values. CacheManager::memo() only does that when nothing is bound under its key,
 * so binding the keys up front is enough: the framework's memo() and store classes
 * run unchanged, and each attempt has its own store instead of a routed one. It goes
 * away with the attempt, so no reset has to refresh it.
 *
 * A store added to the configuration after the worker booted is unknown here and
 * gets the framework's shared memo.
 */
final class AttemptMemoizedCache
{
    public const string BINDING_PREFIX = 'cache.__memoized:';

    public static function install(Container $container): void
    {
        AttemptScope::propagateAcrossAsync();

        AttemptScope::ambient()->memoizedCaches = [];

        /** @var array<string, mixed> $stores */
        $stores = $container->make('config')->get('cache.stores', []);

        foreach (array_keys($stores) as $store) {
            $container->bind(self::BINDING_PREFIX.$store, static function ($app) use ($store): mixed {
                $scope = AttemptScope::current();

                return $scope->memoizedCaches[$store] ??= $app['cache']->repository(
                    new MemoizedStore($store, $app['cache']->store($store)),
                    ['events' => false],
                );
            });
        }
    }
}
