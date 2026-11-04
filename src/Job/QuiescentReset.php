<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Closure;
use Fledge\Async\DeferredFuture;
use Illuminate\Container\Container;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Worker;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Runs the stock worker's reset closure at the moments it is safe to in a worker
 * running concurrent jobs: before an attempt, with no other attempt in flight and
 * at least one finished since the last reset. At one slot that is every job after
 * the first, which gives the stock semantics with no special case. At N slots
 * under sustained load it may never happen, so scoped bindings the framework does
 * not route per attempt stay shared; {@see self::userScopedBindings()} feeds the
 * worker's boot WARN about them.
 *
 * Atomicity: {@see self::beforeAttempt()} takes the decision and raises the gate
 * with no suspension point in between, and a slot claims its message before it
 * calls in, so its count includes itself. A reset closure may suspend (an app can
 * rebind `queue.worker`, and a log driver or listener may do I/O). Any slot arriving
 * meanwhile sees the raised gate and waits for it before beginning its attempt.
 *
 * Attempts started with async() can leave a child Fiber running after the attempt
 * ended. Such a child is not in flight here, so a reset can happen under it.
 */
final class QuiescentReset
{
    /** Framework bindings outside the `Illuminate\` namespace. */
    private const array FRAMEWORK_BINDINGS = ['image'];

    private const string FRAMEWORK_MEMO_PREFIX = 'cache.__memoized:';

    private int $attemptsSinceReset = 0;

    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $running = null;

    /**
     * @param  (Closure(\Throwable): void)|null  $onFailure  Told about every failed reset; must not throw.
     */
    public function __construct(
        private readonly Container $container,
        private readonly ?Closure $reset,
        private readonly ?Closure $onFailure = null,
    ) {}

    /**
     * Use the reset closure of the application's stock worker.
     *
     * @param  (Closure(\Throwable): void)|null  $onFailure  Told about every failed reset; must not throw.
     */
    public static function for(Container $container, ?Closure $onFailure = null): self
    {
        return new self($container, self::stockReset($container), $onFailure);
    }

    /**
     * The closure the framework gave its worker. Kept as the framework's own
     * instead of a copy, so a framework upgrade changes what it resets here too.
     * Null when the application bound a worker without one.
     */
    private static function stockReset(Container $container): ?Closure
    {
        try {
            $worker = $container->make('queue.worker');
            $reset = (new ReflectionProperty(Worker::class, 'resetScope'))->getValue($worker);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[torque:worker] WARN no stock reset closure available, scoped bindings will not reset: {$e->getMessage()}\n");

            return null;
        }

        return is_callable($reset) ? $reset(...) : null;
    }

    public function attemptFinished(): void
    {
        $this->attemptsSinceReset++;
    }

    /**
     * Call right before beginning an attempt, outside any attempt's scope.
     *
     * @param  int  $inFlight  Messages claimed by this worker, including the caller's.
     */
    public function beforeAttempt(int $inFlight): void
    {
        if ($this->running !== null) {
            $this->running->getFuture()->await();

            return;
        }

        if ($inFlight !== 1 || $this->attemptsSinceReset === 0) {
            return;
        }

        $gate = $this->running = new DeferredFuture;

        try {
            if ($this->reset !== null) {
                ($this->reset)();
            }

            // Stock's closure drops a scoped Context repository, and Context
            // outside an attempt along with it. The routed one has no state to
            // drop, so the ambient scope goes here.
            AttemptScope::flushAmbient();

            // The routed binding is scoped, so its closure runs again and hands
            // back the one routed instance. The framework's resolving callback
            // fires with it and hydrates from __LARAVEL_CONTEXT, flushing the
            // caller's scope first. Resolving here makes the caller the ambient
            // scope, as a stock worker's reset leaves it, instead of an attempt.
            $this->container->make(Repository::class);

            $this->attemptsSinceReset = 0;
        } catch (\Throwable $e) {
            // Stays pending, so the next quiet moment tries again. An attempt
            // must not die for it, and neither must the slot.
            fwrite(STDERR, "[torque:worker] Quiescent reset failed: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");

            if ($this->onFailure !== null) {
                ($this->onFailure)($e);
            }
        } finally {
            $this->running = null;
            $gate->complete();
        }
    }

    /**
     * Scoped bindings of the application and its packages: what a slot shares
     * with the other slots until a reset, since only the framework's Context and
     * deferred callbacks are routed per attempt.
     *
     * Bindings of a deferred provider that has not loaded and classes marked
     * `#[Scoped]` that nothing has resolved yet cannot be known at boot.
     *
     * @return list<string>
     */
    public static function userScopedBindings(Container $container): array
    {
        /** @var list<string|Closure> $scoped */
        $scoped = (new ReflectionProperty(Container::class, 'scopedInstances'))->getValue($container);

        $names = [];

        foreach ($scoped as $binding) {
            foreach ($binding instanceof Closure ? self::returnTypes($binding) : [$binding] as $name) {
                if (! self::isFrameworkBinding($name)) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private static function isFrameworkBinding(string $name): bool
    {
        return str_starts_with($name, 'Illuminate\\')
            || str_starts_with($name, self::FRAMEWORK_MEMO_PREFIX)
            || in_array($name, self::FRAMEWORK_BINDINGS, true);
    }

    /**
     * @return list<string>
     */
    private static function returnTypes(Closure $closure): array
    {
        $type = (new ReflectionFunction($closure))->getReturnType();

        $types = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType
            ? $type->getTypes()
            : [$type];

        return array_values(array_map(
            static fn (ReflectionNamedType $named): string => $named->getName(),
            array_filter($types, static fn (mixed $named): bool => $named instanceof ReflectionNamedType),
        ));
    }
}
