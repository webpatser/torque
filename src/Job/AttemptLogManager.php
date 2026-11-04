<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Facade;

/**
 * The framework's log manager for a worker running concurrent jobs in one process.
 *
 * Log::withContext() and Log::shareContext() live on the manager and on its cached
 * channels, one set for the whole process, so one job's context would end up on
 * every other job's records. The channel cache and the handlers stay shared here;
 * the shared context moves to the {@see AttemptScope} of the caller and every
 * channel becomes an {@see AttemptLogger}. It has to be every channel, not just the
 * default one: shareContext() writes to all of them.
 *
 * Log::build() channels are not cached. Stock replaces the previous one in its
 * cache on every call, and two attempts each own the channel they built.
 *
 * tests/Unit/Job/AttemptServicesTest.php fails when the framework adds instance
 * state this class does not account for.
 */
final class AttemptLogManager extends LogManager
{
    /** Properties of the framework's manager that live on the attempt's scope. */
    public const array ROUTED = ['sharedContext'];

    /** Properties of the framework's manager that all attempts share. */
    public const array SHARED = ['app', 'channels', 'customCreators', 'dateFormat', 'levels'];

    public function __construct($app)
    {
        parent::__construct($app);

        // @phpstan-ignore unset.possiblyHookedProperty (this class is final and the framework declares no hooks; the guard test covers a change)
        unset($this->sharedContext);
    }

    /**
     * Replace the framework's manager, with its channels and custom drivers. What
     * it holds as context becomes the ambient scope's, which attempts start from.
     *
     * Bound as an instance, so forgetScopedInstances() does not touch it. An
     * application's own manager is left alone: replacing it would lose whatever it
     * overrides.
     */
    public static function install(Container $container): ?self
    {
        AttemptScope::propagateAcrossAsync();

        $booted = $container->make('log');

        if ($booted instanceof self) {
            return $booted;
        }

        if ($booted::class !== LogManager::class) {
            fwrite(STDERR, '[torque:worker] WARN log context stays shared by concurrent jobs: the application bound its own log manager, '.$booted::class."\n");

            return null;
        }

        $manager = new self($booted->app);
        $manager->dateFormat = $booted->dateFormat;

        foreach ($booted->customCreators as $driver => $creator) {
            $manager->extend($driver, $creator);
        }

        $ambient = AttemptScope::ambient();
        $ambient->logSharedContext = $booted->sharedContext;
        $ambient->logContext = [];

        foreach ($booted->channels as $name => $channel) {
            $manager->channels[$name] = AttemptLogger::adopt($channel, $name, $booted->sharedContext);
        }

        $container->instance('log', $manager);

        Facade::clearResolvedInstance('log');

        return $manager;
    }

    /**
     * Every Log:: call passes through here, so a cached channel takes one
     * instanceof and nothing else.
     *
     * @param  string  $name
     * @param  array<string, mixed>|null  $config
     */
    protected function get($name, ?array $config = null)
    {
        $channel = parent::get($name, $config);

        if ($channel instanceof AttemptLogger) {
            return $channel;
        }

        if ($config !== null) {
            unset($this->channels[$name]);

            return $channel;
        }

        // Not cached means the emergency logger of a channel that failed to build.
        if (! $channel instanceof Logger || ! isset($this->channels[$name])) {
            return $channel;
        }

        return $this->channels[$name] = AttemptLogger::adopt($channel, $name, $this->sharedContext);
    }

    public function &__get(string $name): mixed
    {
        $scope = AttemptScope::current();

        $scope->logSharedContext ??= AttemptScope::ambient()->logSharedContext ?? [];

        return $scope->logSharedContext;
    }

    public function __set(string $name, mixed $value): void
    {
        AttemptScope::current()->logSharedContext = $value;
    }
}
