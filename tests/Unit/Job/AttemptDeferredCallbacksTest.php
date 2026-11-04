<?php

declare(strict_types=1);

use Fledge\Async\FiberContext;
use Illuminate\Container\Container;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Webpatser\Torque\Job\AttemptDeferredCallbacks;

use function Fledge\Async\async;
use function Fledge\Async\delay;

beforeEach(function () {
    $this->container = new Container;
    $this->container->scoped(DeferredCallbackCollection::class);
    $this->collection = AttemptDeferredCallbacks::install($this->container);
});

afterEach(function () {
    FiberContext::forget('torque.deferred_callbacks');
});

it('keeps each attempt\'s callbacks to itself', function () {
    $ran = [];
    $collection = $this->collection;

    $attempt = static function (string $name, float $finishAfter) use ($collection, &$ran): int {
        $collection->beginAttempt();
        $collection[] = new DeferredCallback(static function () use ($name, &$ran): void {
            $ran[] = $name;
        });

        delay($finishAfter);

        $count = count($collection);
        $collection->invokeWhen();
        $collection->endAttempt();

        return $count;
    };

    $first = async($attempt, 'first', 0.01);
    $second = async($attempt, 'second', 0.05);

    expect($first->await())->toBe(1)
        ->and($ran)->toBe(['first'])
        ->and($second->await())->toBe(1)
        ->and($ran)->toBe(['first', 'second']);
});

it('applies the caller\'s truth test to that attempt\'s callbacks only', function () {
    $ran = [];
    $collection = $this->collection;

    $attempt = static function (string $name, bool $successful, float $finishAfter) use ($collection, &$ran): void {
        $collection->beginAttempt();

        foreach ([false, true] as $always) {
            $collection[] = new DeferredCallback(static function () use ($name, $always, &$ran): void {
                $ran[] = $name.($always ? ':always' : ':plain');
            }, always: $always);
        }

        delay($finishAfter);

        $collection->invokeWhen(static fn (DeferredCallback $callback): bool => $successful || $callback->always);
        $collection->endAttempt();
    };

    // The failed attempt ends while the passing one has its callbacks registered.
    $failed = async($attempt, 'failed', false, 0.01);
    $passed = async($attempt, 'passed', true, 0.05);

    $failed->await();
    $passed->await();

    expect($ran)->toBe(['failed:always', 'passed:plain', 'passed:always']);
});

it('credits a child Fiber\'s callback to the attempt that spawned it, at any depth', function () {
    $ran = [];
    $collection = $this->collection;

    $add = static function (string $name) use ($collection, &$ran): void {
        $collection[] = new DeferredCallback(static function () use ($name, &$ran): void {
            $ran[] = $name;
        });
    };

    $counts = async(static function () use ($collection, $add): array {
        $collection->beginAttempt();

        $add('own');
        async(static fn () => $add('child'))->await();
        async(static fn () => async(static fn () => $add('grandchild'))->await())->await();

        $counts = [count($collection)];
        $collection->endAttempt();
        $counts[] = count($collection);

        return $counts;
    })->await();

    expect($counts)->toBe([3, 0])
        ->and($ran)->toBe([]);
});

it('does not leak an owner to the next closure on a reused Fiber', function () {
    $collection = $this->collection;

    async(static function () use ($collection): void {
        $collection->beginAttempt();
        async(static fn () => null)->await();
        $collection->endAttempt();
    })->await();

    // Child closures finished without suspending, so Revolt reuses their Fiber.
    async(static function () use ($collection): void {
        $collection[] = new DeferredCallback(static fn () => null);
    })->await();

    expect(count($collection))->toBe(1);
});

it('keeps code outside any attempt on the ambient collection', function () {
    $ran = false;
    $collection = $this->collection;

    $collection[] = new DeferredCallback(static function () use (&$ran): void {
        $ran = true;
    });

    $inAttempt = async(static function () use ($collection): int {
        $collection->beginAttempt();
        $count = count($collection);
        $collection->endAttempt();

        return $count;
    })->await();

    expect($inAttempt)->toBe(0)
        ->and(count($collection))->toBe(1);

    $collection->invoke();

    expect($ran)->toBeTrue()
        ->and(count($collection))->toBe(0);
});

it('routes every collection operation to the current attempt', function () {
    $collection = $this->collection;

    async(static function () use ($collection): void {
        $collection->beginAttempt();

        $callback = new DeferredCallback(static fn () => null, 'named');
        $collection[] = $callback;
        $collection['key'] = new DeferredCallback(static fn () => null, 'other');

        expect($collection->first())->toBe($callback)
            ->and(isset($collection[0]))->toBeTrue()
            ->and($collection[0])->toBe($callback);

        $collection->forget('named');

        expect(count($collection))->toBe(1);

        unset($collection[0]);

        expect(count($collection))->toBe(0);

        $collection->endAttempt();
    })->await();

    expect(count($collection))->toBe(0);
});

it('survives forgetScopedInstances with an attempt in flight', function () {
    $container = $this->container;
    $collection = $this->collection;

    $count = async(static function () use ($container, $collection): int {
        $collection->beginAttempt();
        $collection[] = new DeferredCallback(static fn () => null);

        $container->forgetScopedInstances();

        expect($container->make(DeferredCallbackCollection::class))->toBe($collection);

        $count = count($container->make(DeferredCallbackCollection::class));
        $collection->endAttempt();

        return $count;
    })->await();

    expect($count)->toBe(1);
});
