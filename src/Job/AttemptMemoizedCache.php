<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Illuminate\Cache\MemoizedStore;
use Illuminate\Contracts\Container\Container;
use Webpatser\Torque\Support\Narrow;

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

        $stores = Narrow::map($container->make('config')->get('cache.stores', []));

        foreach (array_keys($stores) as $store) {
            $container->bind(self::BINDING_PREFIX.$store, static function (Container $app) use ($store): mixed {
                $scope = AttemptScope::current();

                if (! isset($scope->memoizedCaches[$store])) {
                    $cache = $app->make('cache');

                    $scope->memoizedCaches[$store] = $cache->repository(
                        // store() is typed as the Repository contract while MemoizedStore's
                        // docblock asks for the concrete class. Passed as the framework's own
                        // memo() passes it, so a contract-only store behaves as it always did.
                        // @phpstan-ignore argument.type
                        new MemoizedStore($store, $cache->store($store)),
                        ['events' => false],
                    );
                }

                return $scope->memoizedCaches[$store];
            });
        }
    }
}
