<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Facade;

/**
 * The framework's Context repository for a worker running concurrent jobs in one
 * process.
 *
 * The framework keeps one scoped repository, hydrates it on JobProcessing and
 * copies it into every payload dispatched. With N slots sharing it, one job's
 * tenant travels into another job's children, and every job starting wipes the
 * Context of the jobs already running.
 *
 * So this subclass drops the properties holding Context and PHP falls through to
 * the magic methods below, which reach the {@see AttemptScope} of the caller.
 * Every inherited method then works on its own attempt's state unchanged,
 * including the ones taking a reference, like the array_pop() in pop(). Only the
 * event dispatcher stays on the instance, as a service shared by all attempts.
 *
 * tests/Unit/Job/AttemptContextRepositoryTest.php fails when the framework adds
 * instance state this class does not account for.
 */
final class AttemptContextRepository extends Repository
{
    /** Properties of the framework's repository that live on the attempt's scope. */
    public const array ROUTED = ['data', 'hidden'];

    /** Properties of the framework's repository that all attempts share. */
    public const array SHARED = ['events'];

    public function __construct(Dispatcher $events)
    {
        parent::__construct($events);

        // @phpstan-ignore unset.possiblyHookedProperty, unset.possiblyHookedProperty (this class is final and the framework declares no hooks; the guard test covers a change)
        unset($this->data, $this->hidden);
    }

    /**
     * Bind a routing repository into the container, with any Context added
     * during boot as the ambient scope's.
     *
     * The binding stays scoped, as the framework registers it, and resolves to
     * this one instance, so forgetScopedInstances() cannot cost an attempt in
     * flight its Context. The facade may have cached the framework's instance,
     * which is the one its queue listeners would keep reaching through it.
     */
    public static function install(Container $container): self
    {
        AttemptScope::propagateAcrossAsync();

        $booted = $container->bound(Repository::class) ? $container->make(Repository::class) : null;
        $context = ['data' => $booted?->all() ?? [], 'hidden' => $booted?->allHidden() ?? []];

        $repository = new self($container->make(Dispatcher::class));

        $container->scoped(Repository::class, static fn (): self => $repository);

        // Resolve now: ContextServiceProvider's resolving callback hydrates from
        // the environment, and this way it lands on the ambient scope.
        $container->make(Repository::class);

        AttemptScope::ambient()->context = $context;

        Facade::clearResolvedInstance(Repository::class);

        return $repository;
    }

    /**
     * Dehydrate through a plain repository. The inherited method builds a copy
     * with `new static` for ContextDehydrating listeners to change; a copy of
     * this class would have no state of its own, and those changes would land in
     * the attempt's live Context.
     *
     * @return array<mixed>|null
     */
    public function dehydrate(): ?array
    {
        return (new Repository($this->events))
            ->add($this->all())
            ->addHidden($this->allHidden())
            ->dehydrate();
    }

    public function &__get(string $name): mixed
    {
        return AttemptScope::current()->context[$name];
    }

    public function __set(string $name, mixed $value): void
    {
        AttemptScope::current()->context[$name] = $value;
    }
}
