<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Context\Events\ContextDehydrating;
use Illuminate\Log\Context\Repository;
use Webpatser\Torque\Job\AttemptContextRepository;
use Webpatser\Torque\Job\AttemptScope;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * Instance properties of a Context repository class that the routed subclass
 * neither routes nor knowingly shares.
 *
 * @param  class-string<Repository>  $class
 * @return list<string>
 */
function torque_unaccounted_context_state(string $class): array
{
    $properties = array_filter(
        (new ReflectionClass($class))->getProperties(),
        static fn (ReflectionProperty $property): bool => ! $property->isStatic(),
    );

    return array_values(array_diff(
        array_map(static fn (ReflectionProperty $property): string => $property->getName(), $properties),
        AttemptContextRepository::ROUTED,
        AttemptContextRepository::SHARED,
    ));
}

beforeEach(function () {
    $this->container = new Container;
    $this->events = new Dispatcher($this->container);
    $this->container->instance(DispatcherContract::class, $this->events);
    $this->container->scoped(Repository::class);
    $this->stock = $this->container->make(Repository::class)->add('booted', 'yes')->addHidden('secret', 'boot');
    $this->repository = AttemptContextRepository::install($this->container);
});

it('accounts for all the instance state of the framework\'s repository', function () {
    expect(torque_unaccounted_context_state(Repository::class))->toBe([]);
});

it('flags instance state the routed subclass does not account for', function () {
    $upstream = new class(new Dispatcher) extends Repository
    {
        protected $addedByUpstream = [];
    };

    expect(torque_unaccounted_context_state($upstream::class))->toBe(['addedByUpstream']);
});

it('knows every static property of the framework\'s repository is process-wide config', function () {
    $static = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(Repository::class))->getProperties(ReflectionProperty::IS_STATIC),
    );

    expect($static)->toEqualCanonicalizing(['handleUnserializeExceptionsUsing', 'macros']);
});

it('holds no routed state on the instance', function () {
    foreach (AttemptContextRepository::ROUTED as $name) {
        expect((new ReflectionProperty(Repository::class, $name))->isInitialized($this->repository))->toBeFalse();
    }
});

it('rebinds the container and moves boot-time Context to the ambient scope', function () {
    expect($this->container->make(Repository::class))->toBe($this->repository)
        ->and($this->repository->all())->toBe(['booted' => 'yes'])
        ->and($this->repository->allHidden())->toBe(['secret' => 'boot'])
        ->and(AttemptScope::ambient()->context['data'])->toBe(['booted' => 'yes']);

    $this->container->forgetScopedInstances();

    expect($this->container->make(Repository::class))->toBe($this->repository);
});

it('works through every state-changing method on the attempt\'s own scope', function () {
    $repository = $this->repository;

    $result = async(static function () use ($repository): array {
        AttemptScope::begin();

        $seen = ['starts_empty' => $repository->isEmpty()];

        $repository->add('key', 'value')->addHidden('hidden', 'value');
        $seen['get'] = $repository->get('key');
        $seen['get_default'] = $repository->get('missing', 'default');
        $seen['get_hidden'] = $repository->getHidden('hidden');
        $seen['has'] = $repository->has('key') && $repository->hasHidden('hidden');

        $repository->push('stack', 1, 2)->pushHidden('hidden_stack', 3, 4);
        $seen['pop'] = $repository->pop('stack');
        $seen['pop_hidden'] = $repository->popHidden('hidden_stack');
        $seen['stack'] = $repository->get('stack');
        $seen['contains'] = $repository->stackContains('stack', 1);

        $repository->increment('counter', 2)->decrement('counter');
        $seen['counter'] = $repository->get('counter');

        $seen['pull'] = $repository->pull('counter');
        $seen['pulled'] = $repository->has('counter');
        $seen['remember'] = $repository->remember('remembered', static fn (): string => 'made');

        $repository->forget('remembered')->forgetHidden('hidden_stack');

        $seen['scope'] = $repository->scope(
            static fn (): array => $repository->all(),
            ['scoped' => true],
        );
        $seen['after_scope'] = $repository->has('scoped');

        $seen['all'] = $repository->all();
        $seen['hidden'] = $repository->allHidden();

        $repository->flush();
        $seen['flushed'] = $repository->isEmpty();

        AttemptScope::end();

        return $seen;
    })->await();

    expect($result)->toBe([
        'starts_empty' => true,
        'get' => 'value',
        'get_default' => 'default',
        'get_hidden' => 'value',
        'has' => true,
        'pop' => 2,
        'pop_hidden' => 4,
        'stack' => [1],
        'contains' => true,
        'counter' => 1,
        'pull' => 1,
        'pulled' => false,
        'remember' => 'made',
        'scope' => ['key' => 'value', 'stack' => [1], 'scoped' => true],
        'after_scope' => false,
        'all' => ['key' => 'value', 'stack' => [1]],
        'hidden' => ['hidden' => 'value'],
        'flushed' => true,
    ]);

    expect($repository->all())->toBe(['booted' => 'yes']);
});

it('confines a hydrate to the attempt running it', function () {
    $repository = $this->repository;
    $payload = (new Repository($this->events))->add('from', 'payload')->dehydrate();

    $running = async(static function () use ($repository): array {
        AttemptScope::begin();
        $repository->add('mine', true);
        delay(0.02);
        $all = $repository->all();
        AttemptScope::end();

        return $all;
    });

    $starting = async(static function () use ($repository, $payload): array {
        AttemptScope::begin();
        $repository->hydrate($payload);
        $all = $repository->all();
        AttemptScope::end();

        return $all;
    });

    expect($starting->await())->toBe(['from' => 'payload'])
        ->and($running->await())->toBe(['mine' => true])
        ->and($repository->all())->toBe(['booted' => 'yes']);
});

it('dehydrates the attempt\'s values without letting listeners change them', function () {
    $repository = $this->repository;

    $this->events->listen(static function (ContextDehydrating $event): void {
        $event->context->add('added_by_listener', true);
    });

    [$dehydrated, $live] = async(static function () use ($repository): array {
        AttemptScope::begin();
        $repository->add('tenant', 'a')->addHidden('token', 'b');
        $result = [$repository->dehydrate(), $repository->all()];
        AttemptScope::end();

        return $result;
    })->await();

    expect(array_map(unserialize(...), $dehydrated['data']))->toBe(['tenant' => 'a', 'added_by_listener' => true])
        ->and(array_map(unserialize(...), $dehydrated['hidden']))->toBe(['token' => 'b'])
        ->and($live)->toBe(['tenant' => 'a']);
});

it('releases a finished attempt\'s scope, async() children included', function () {
    $repository = $this->repository;

    [$released, $afterwards] = async(static function () use ($repository): array {
        $scope = WeakReference::create(AttemptScope::begin());
        $repository->add('heavy', str_repeat('x', 1024));

        async(static fn () => $repository->get('heavy'))->await();
        delay(0);

        AttemptScope::end();
        gc_collect_cycles();

        return [$scope->get() === null, $repository->all()];
    })->await();

    expect($released)->toBeTrue()
        ->and($afterwards)->toBe(['booted' => 'yes']);
});

it('does not leak a scope to the next closure on a reused Fiber', function () {
    $repository = $this->repository;

    async(static function () use ($repository): void {
        AttemptScope::begin();
        $repository->add('tenant', 'a');
        async(static fn () => null)->await();
        AttemptScope::end();
    })->await();

    $next = async(static fn (): array => $repository->all())->await();

    expect($next)->toBe(['booted' => 'yes']);
});
