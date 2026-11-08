<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Facade;
use ReflectionFunction;
use ReflectionProperty;
use Webpatser\Torque\Support\Narrow;

/**
 * The framework's auth manager for a worker running concurrent jobs in one process.
 *
 * The manager caches one guard per name for the whole process and a guard holds its
 * user, so Auth::setUser() in one job would be Auth::user() in every other. The
 * cache of guards moves to the {@see AttemptScope} of the caller, along with the
 * user resolver, and every attempt gets its own guard objects.
 *
 * An attempt's guard is a clone of the one the ambient scope holds, which any guard
 * supports without knowing what it keeps: whatever state a custom guard has in its
 * own properties is the attempt's from there on. The ambient guard is built once
 * per name, outside any attempt, which matters for two reasons. The framework's
 * drivers register a callback on the container's `request` binding each time one is
 * built, so a build per attempt would pile up callbacks and the guards they hold for
 * as long as the worker lives. And what was set during boot, like a system user, is
 * on it, so it shows in every attempt as in stock.
 *
 * A clone is shallow. A session guard also keeps its user id in the session, a
 * shared service, so it gets a copy of that too; a custom guard keeping its state in
 * an object it holds still shares that object.
 *
 * tests/Unit/Job/AttemptServicesTest.php fails when the framework adds instance
 * state this class does not account for.
 */
final class AttemptAuthManager extends AuthManager
{
    /** Properties of the framework's manager that live on the attempt's scope. */
    public const array ROUTED = ['guards', 'userResolver'];

    /** Properties of the framework's manager that all attempts share. */
    public const array SHARED = ['app', 'customCreators', 'customProviderCreators'];

    public function __construct($app)
    {
        parent::__construct($app);

        $ambient = AttemptScope::ambient();
        $ambient->authUserResolver = $this->userResolver;
        $ambient->authGuards = [];

        // @phpstan-ignore unset.possiblyHookedProperty, unset.possiblyHookedProperty (this class is final and the framework declares no hooks; the guard test covers a change)
        unset($this->guards, $this->userResolver);
    }

    /**
     * Replace the framework's manager, with its custom drivers and providers. Its
     * guards and user resolver become the ambient scope's, which attempts start
     * from.
     *
     * Bound as an instance, so forgetScopedInstances() does not touch it. The
     * `auth.driver` singleton behind the Guard contract would hold the first guard
     * anyone asked for, so it stops being one. An application's own manager is left
     * alone: replacing it would lose whatever it overrides.
     */
    public static function install(Container $container): ?self
    {
        AttemptScope::propagateAcrossAsync();

        $booted = $container->make('auth');

        if ($booted instanceof self) {
            return $booted;
        }

        if ($booted::class !== AuthManager::class) {
            fwrite(STDERR, '[torque:worker] WARN guards stay shared by concurrent jobs: the application bound its own auth manager, '.$booted::class."\n");

            return null;
        }

        $manager = new self($booted->app);
        $manager->customProviderCreators = $booted->customProviderCreators;

        foreach ($booted->customCreators as $driver => $creator) {
            if ($creator instanceof Closure) {
                $manager->extend($driver, $creator);
            }
        }

        $ambient = AttemptScope::ambient();
        $ambient->authGuards = Narrow::map($booted->guards);

        // The default resolver is a closure over the framework's manager; one the
        // application set is the application's.
        if ((new ReflectionFunction($booted->userResolver))->getClosureThis() === $booted) {
            $ambient->authUserResolver = $booted->userResolver->bindTo($manager, self::class);
        } else {
            $ambient->authUserResolver = $booted->userResolver;
        }

        $container->instance('auth', $manager);
        $container->bind('auth.driver', static function (Container $app): mixed {
            return $app->make('auth')->guard();
        });

        Facade::clearResolvedInstance('auth');

        return $manager;
    }

    /**
     * @param  string  $name
     * @return Guard|StatefulGuard
     */
    protected function resolve($name)
    {
        $ambient = AttemptScope::ambient();

        // Built in the ambient scope: a custom creator reading the attempt's
        // Context would otherwise pass it on to every other attempt.
        $guard = $ambient->authGuards[$name] ?? null;

        if (! $guard instanceof Guard) {
            $guard = $ambient->authGuards[$name] = AttemptScope::within($ambient, fn (): Guard => parent::resolve($name));
        }

        return AttemptScope::current() === $ambient ? $guard : self::forAttempt($guard);
    }

    /**
     * @param  Guard|StatefulGuard  $guard
     * @return Guard|StatefulGuard
     */
    private static function forAttempt(object $guard): object
    {
        $copy = clone $guard;

        if ($copy instanceof SessionGuard) {
            (new ReflectionProperty(SessionGuard::class, 'session'))->setValue($copy, clone $copy->getSession());
        }

        return $copy;
    }

    /**
     * The inherited guard() reads through `??=`, which asks this first.
     */
    public function __isset(string $name): bool
    {
        return in_array($name, self::ROUTED, true);
    }

    public function &__get(string $name): mixed
    {
        $scope = AttemptScope::current();

        if ($name === 'guards') {
            return $scope->authGuards;
        }

        $scope->authUserResolver ??= AttemptScope::ambient()->authUserResolver;

        return $scope->authUserResolver;
    }

    public function __set(string $name, mixed $value): void
    {
        $scope = AttemptScope::current();

        if ($name === 'guards') {
            $scope->authGuards = Narrow::map($value);
        } else {
            $scope->authUserResolver = $value instanceof Closure ? $value : null;
        }
    }
}
