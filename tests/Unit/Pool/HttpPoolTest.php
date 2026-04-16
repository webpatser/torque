<?php

declare(strict_types=1);

use Webpatser\Torque\Pool\HttpPool;

it('exposes maxConcurrent via asymmetric visibility', function () {
    $pool = new HttpPool(maxConcurrent: 10);

    expect($pool->maxConcurrent)->toBe(10);
});

it('uses default maxConcurrent of 15', function () {
    $pool = new HttpPool();

    expect($pool->maxConcurrent)->toBe(15);
});

it('limits concurrency via the semaphore', function () {
    $pool = new HttpPool(maxConcurrent: 2);

    // We verify the concurrency limit by using the use() method with a
    // controllable callback. The semaphore inside HttpPool gates all
    // request() and use() calls, so we can observe the limiting behavior
    // the same way ConnectionPoolTest does.

    $order = [];
    $suspension1 = new \Fledge\Async\DeferredFuture();
    $suspension2 = new \Fledge\Async\DeferredFuture();

    // Occupy both slots.
    $future1 = \Fledge\Async\async(function () use ($pool, &$order, $suspension1) {
        $pool->use(function () use (&$order, $suspension1) {
            $order[] = 'slot-1-acquired';
            $suspension1->getFuture()->await();
            $order[] = 'slot-1-released';
        });
    });

    $future2 = \Fledge\Async\async(function () use ($pool, &$order, $suspension2) {
        $pool->use(function () use (&$order, $suspension2) {
            $order[] = 'slot-2-acquired';
            $suspension2->getFuture()->await();
            $order[] = 'slot-2-released';
        });
    });

    // Let both fibers acquire their slots.
    \Fledge\Async\delay(0.01);

    // Third request should be blocked because both slots are taken.
    $thirdAcquired = false;
    $future3 = \Fledge\Async\async(function () use ($pool, &$order, &$thirdAcquired) {
        $pool->use(function () use (&$order, &$thirdAcquired) {
            $thirdAcquired = true;
            $order[] = 'slot-3-acquired';
        });
    });

    // Give the event loop a tick — third should still be waiting.
    \Fledge\Async\delay(0.01);
    expect($thirdAcquired)->toBeFalse();

    // Free one slot.
    $suspension1->complete(null);
    \Fledge\Async\delay(0.01);

    // Now the third request should have acquired a slot.
    expect($thirdAcquired)->toBeTrue();

    // Clean up remaining fibers.
    $suspension2->complete(null);
    \Fledge\Async\Future\await([$future1, $future2, $future3]);

    expect($order)->toBe([
        'slot-1-acquired',
        'slot-2-acquired',
        'slot-1-released',
        'slot-3-acquired',
        'slot-2-released',
    ]);
});

it('use() releases semaphore slot when callback throws', function () {
    $pool = new HttpPool(maxConcurrent: 1);

    try {
        $pool->use(function () {
            throw new RuntimeException('deliberate failure');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    // The slot should be freed, so another use() call should succeed.
    $result = $pool->use(fn () => 'recovered');

    expect($result)->toBe('recovered');
});
