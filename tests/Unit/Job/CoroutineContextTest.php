<?php

declare(strict_types=1);

use Webpatser\Torque\Job\CoroutineContext;

use function Amp\async;
use function Amp\delay;

beforeEach(function () {
    CoroutineContext::init();
});

it('stores and retrieves values in a Fiber', function () {
    $future = async(function () {
        CoroutineContext::set('key', 'value');
        expect(CoroutineContext::get('key'))->toBe('value');
        expect(CoroutineContext::has('key'))->toBeTrue();
    });

    $future->await();
});

it('isolates state between Fibers', function () {
    $future1 = async(function () {
        CoroutineContext::set('name', 'fiber-1');
        delay(0.01); // Yield to let fiber-2 run.
        expect(CoroutineContext::get('name'))->toBe('fiber-1');
    });

    $future2 = async(function () {
        CoroutineContext::set('name', 'fiber-2');
        delay(0.01);
        expect(CoroutineContext::get('name'))->toBe('fiber-2');
    });

    $future1->await();
    $future2->await();
});

it('returns default when key is missing', function () {
    $future = async(function () {
        expect(CoroutineContext::get('missing'))->toBeNull();
        expect(CoroutineContext::get('missing', 'fallback'))->toBe('fallback');
    });

    $future->await();
});

it('forgets individual keys', function () {
    $future = async(function () {
        CoroutineContext::set('a', 1);
        CoroutineContext::set('b', 2);
        CoroutineContext::forget('a');

        expect(CoroutineContext::has('a'))->toBeFalse();
        expect(CoroutineContext::has('b'))->toBeTrue();
    });

    $future->await();
});

it('flushes all state for a Fiber', function () {
    $future = async(function () {
        CoroutineContext::set('x', 1);
        CoroutineContext::set('y', 2);
        CoroutineContext::flush();

        expect(CoroutineContext::has('x'))->toBeFalse();
        expect(CoroutineContext::has('y'))->toBeFalse();
    });

    $future->await();
});

it('is a no-op outside a Fiber', function () {
    // These should not throw when called from the main context.
    CoroutineContext::set('key', 'value');
    expect(CoroutineContext::get('key'))->toBeNull();
    expect(CoroutineContext::has('key'))->toBeFalse();

    CoroutineContext::forget('key');
    CoroutineContext::flush();
});
