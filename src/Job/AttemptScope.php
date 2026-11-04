<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Closure;
use Fledge\Async\FiberContext;
use Illuminate\Support\Defer\DeferredCallbackCollection;

/**
 * Everything that belongs to one job attempt in a worker running concurrent jobs
 * in one process. Routed services ({@see AttemptContextRepository},
 * {@see AttemptDeferredCallbacks}) keep their state here instead of on the shared
 * instance the container hands to every slot.
 *
 * The scope of the current Fiber lives in {@see CoroutineContext}. A job's own
 * child Fibers have no link to the slot's Fiber, so one {@see FiberContext} hook
 * carries the scope across async(), which every concurrency helper goes through
 * (Concurrency's fiber driver, FiberDB, Pipeline, disperse). Parent and children
 * hold the same object: they are one attempt.
 *
 * Callers without a scope share the ambient one. That is code outside any job,
 * such as the reader loop's Looping listeners, and also code a job starts without
 * async(), such as a raw Fiber or an event loop timer, since nothing links those
 * to the attempt.
 *
 * The slot's reference goes away in end() and a child's when it finishes, so a
 * scope lives as long as its attempt and any child outliving it. Such a child
 * keeps writing to its own scope, which then goes away with the Fiber.
 */
final class AttemptScope
{
    private const string CONTEXT_KEY = 'torque.attempt_scope';

    private const string HOOK = 'torque.attempt_scope';

    private static ?self $ambient = null;

    /**
     * The properties of {@see AttemptContextRepository}, keyed by property name.
     *
     * @var array<string, mixed>
     */
    public array $context = ['data' => [], 'hidden' => []];

    public DeferredCallbackCollection $deferredCallbacks;

    public function __construct()
    {
        $this->deferredCallbacks = new DeferredCallbackCollection;
    }

    /**
     * Start carrying the current scope across async().
     */
    public static function propagateAcrossAsync(): void
    {
        CoroutineContext::init();

        FiberContext::register(
            self::HOOK,
            static fn (): ?self => CoroutineContext::get(self::CONTEXT_KEY),
            static function (mixed $scope): ?Closure {
                if (! $scope instanceof self) {
                    return null;
                }

                CoroutineContext::set(self::CONTEXT_KEY, $scope);

                // Revolt reuses callback Fibers, so the next closure on this
                // one must not inherit the scope.
                return static fn () => CoroutineContext::forget(self::CONTEXT_KEY);
            },
        );
    }

    /**
     * Give the current Fiber's attempt its own, empty scope.
     */
    public static function begin(): self
    {
        $scope = new self;

        CoroutineContext::set(self::CONTEXT_KEY, $scope);

        return $scope;
    }

    /**
     * Release the current Fiber's attempt.
     */
    public static function end(): void
    {
        CoroutineContext::forget(self::CONTEXT_KEY);
    }

    /**
     * Run a callback in the given attempt's scope from a Fiber outside that
     * attempt, such as a signal callback, then give the Fiber its own scope back.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function within(self $scope, Closure $callback): mixed
    {
        $previous = CoroutineContext::get(self::CONTEXT_KEY);

        CoroutineContext::set(self::CONTEXT_KEY, $scope);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                CoroutineContext::forget(self::CONTEXT_KEY);
            } else {
                CoroutineContext::set(self::CONTEXT_KEY, $previous);
            }
        }
    }

    /**
     * The scope of the attempt the caller belongs to, or the ambient one.
     */
    public static function current(): self
    {
        return CoroutineContext::get(self::CONTEXT_KEY) ?? self::ambient();
    }

    public static function ambient(): self
    {
        return self::$ambient ??= new self;
    }

    /**
     * Drop what was written outside any attempt. Without this, it would stay
     * until the worker rotates, since no job's hydrate reaches the ambient scope.
     */
    public static function flushAmbient(): void
    {
        self::$ambient = null;
    }
}
