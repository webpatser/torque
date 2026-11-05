<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Closure;
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
 * So every {@see AttemptScope} holds a stock collection of its own, and this
 * class routes each call to the scope of the attempt the caller belongs to. The
 * framework's listener stays as it is: JobAttempted is dispatched from the
 * slot's Fiber, so its invokeWhen() reaches exactly that attempt's callbacks.
 *
 * Callers without a scope share the ambient collection, as code outside a job
 * always did: a command's CommandFinished listener still finds its callbacks
 * there. A worker normally ends in a hard exit, so ambient callbacks there
 * accumulate until the worker rotates and may never run.
 *
 * A child Fiber outliving its attempt keeps writing to that attempt's
 * collection. JobAttempted has been announced by then, so those late callbacks
 * do not run and go away with the Fiber.
 */
final class AttemptDeferredCallbacks extends DeferredCallbackCollection
{
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
        AttemptScope::propagateAcrossAsync();
        AttemptScope::ambient()->deferredCallbacks = new DeferredCallbackCollection;

        $collection = new self;

        $container->scoped(DeferredCallbackCollection::class, static fn (): self => $collection);

        return $collection;
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
        return AttemptScope::current()->deferredCallbacks;
    }
}
