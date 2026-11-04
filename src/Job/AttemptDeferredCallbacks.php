<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Closure;
use Fledge\Async\FiberContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Defer\DeferredCallbackCollection;

/**
 * `defer()` storage for a worker running concurrent jobs in one process.
 *
 * The framework keeps one scoped collection, and its JobAttempted listener
 * calls invokeWhen() on it. That only works for a worker running one job at a
 * time. With N slots sharing the collection, one job's failure would drop
 * another job's callbacks, its success would run them early, and a callback
 * that suspends could run twice, since invokeWhen() removes a callback only
 * after it returns.
 *
 * So every attempt gets a stock collection of its own, and this class routes
 * each call to the collection of the attempt the caller belongs to. The
 * framework's listener stays as it is: JobAttempted is dispatched from the
 * slot's Fiber, so its invokeWhen() reaches exactly that attempt's callbacks.
 *
 * Ownership lives in {@see CoroutineContext}, which is per Fiber. A job's own
 * child Fibers have no link to the slot's Fiber, so a {@see FiberContext} hook
 * carries the owner across async(), which every concurrency helper goes
 * through (Concurrency's fiber driver, FiberDB, Pipeline, disperse).
 *
 * Callers without an owner share one ambient collection, as code outside a job
 * always did: a command's CommandFinished listener still finds its callbacks
 * there. That includes code a job starts without async(), such as a raw Fiber
 * or an event loop timer, since nothing links those to the attempt. A worker
 * normally ends in a hard exit, so ambient callbacks there accumulate until the
 * worker rotates and may never run.
 *
 * A child Fiber outliving its attempt keeps writing to that attempt's
 * collection. JobAttempted has been announced by then, so those late callbacks
 * do not run and go away with the Fiber.
 */
final class AttemptDeferredCallbacks extends DeferredCallbackCollection
{
    private const string CONTEXT_KEY = 'torque.deferred_callbacks';

    private const string HOOK = 'torque.deferred_callbacks';

    private readonly DeferredCallbackCollection $ambient;

    public function __construct()
    {
        $this->ambient = new DeferredCallbackCollection;

        CoroutineContext::init();
    }

    /**
     * Bind a routing collection into the container and start carrying the
     * owning attempt across async().
     *
     * The binding stays scoped, as the framework registers it, and resolves to
     * this one instance. forgetScopedInstances() only forgets the resolved
     * instance, so it cannot cost an attempt in flight its callbacks.
     */
    public static function install(Container $container): self
    {
        $collection = new self;

        $container->scoped(DeferredCallbackCollection::class, static fn (): self => $collection);

        FiberContext::register(
            self::HOOK,
            static fn (): ?DeferredCallbackCollection => CoroutineContext::get(self::CONTEXT_KEY),
            static function (mixed $owner): ?Closure {
                if (! $owner instanceof DeferredCallbackCollection) {
                    return null;
                }

                CoroutineContext::set(self::CONTEXT_KEY, $owner);

                // Revolt reuses callback Fibers, so the next closure on this
                // one must not inherit the owner.
                return static fn () => CoroutineContext::forget(self::CONTEXT_KEY);
            },
        );

        return $collection;
    }

    /**
     * Give the current Fiber's attempt its own, empty collection.
     */
    public function beginAttempt(): void
    {
        CoroutineContext::set(self::CONTEXT_KEY, new DeferredCallbackCollection);
    }

    /**
     * Release the current Fiber's attempt. Callbacks that did not run go with it.
     */
    public function endAttempt(): void
    {
        CoroutineContext::forget(self::CONTEXT_KEY);
    }

    public function first()
    {
        return $this->current()->first();
    }

    public function invokeWhen(?Closure $when = null): void
    {
        $this->current()->invokeWhen($when);
    }

    public function forget(string $name): void
    {
        $this->current()->forget($name);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->current()->offsetExists($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->current()->offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->current()->offsetSet($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->current()->offsetUnset($offset);
    }

    public function count(): int
    {
        return $this->current()->count();
    }

    private function current(): DeferredCallbackCollection
    {
        return CoroutineContext::get(self::CONTEXT_KEY) ?? $this->ambient;
    }
}
