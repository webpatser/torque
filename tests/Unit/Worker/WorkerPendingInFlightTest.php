<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

/**
 * Every slot of a worker shares one consumer name, so this consumer's PEL
 * lists the messages other slots are still running. The pending read must
 * skip those, or a second slot runs the same job concurrently (and, with
 * crash counting on, counts the live attempt as a crash).
 */
it('skips pending messages a slot of this worker already owns', function () {
    $messages = [
        ['stream' => 'torque:default', 'id' => '1-0', 'payload' => 'a'],
        ['stream' => 'torque:default', 'id' => '2-0', 'payload' => 'b'],
    ];

    expect(WorkerProcess::firstUnclaimed($messages, ['torque:default|1-0' => true]))
        ->toBe($messages[1]);
});

it('returns the first pending message when nothing is in flight', function () {
    $messages = [['stream' => 'torque:default', 'id' => '1-0', 'payload' => 'a']];

    expect(WorkerProcess::firstUnclaimed($messages, []))->toBe($messages[0]);
});

it('returns null when every pending message is owned', function () {
    $messages = [['stream' => 'torque:default', 'id' => '1-0', 'payload' => 'a']];

    expect(WorkerProcess::firstUnclaimed($messages, ['torque:default|1-0' => true]))->toBeNull()
        ->and(WorkerProcess::firstUnclaimed([], []))->toBeNull();
});

it('keys ownership by stream and id together', function () {
    $messages = [['stream' => 'torque:emails', 'id' => '1-0', 'payload' => 'a']];

    expect(WorkerProcess::firstUnclaimed($messages, ['torque:default|1-0' => true]))
        ->toBe($messages[0]);
});

/**
 * Slot B can finish message M (XACK, then drop it from inFlight) while fiber
 * A's pending-read reply, built before the XACK, still lists M. A must not
 * hand the acked job to another slot.
 */
it('skips a message that finished while the pending read was awaiting Redis', function () {
    $messages = [
        ['stream' => 'torque:default', 'id' => '1-0', 'payload' => 'a'],
        ['stream' => 'torque:default', 'id' => '2-0', 'payload' => 'b'],
    ];

    // Before the await M (1-0) is in flight; after it, slot B has released it.
    $inFlightBefore = ['torque:default|1-0' => true];
    $inFlightAfter = [];
    $finishedDuringRead = ['torque:default|1-0' => true];

    expect($inFlightBefore)->toHaveKey('torque:default|1-0')
        ->and(WorkerProcess::firstUnclaimed($messages, WorkerProcess::takenKeys($inFlightAfter, [], [])))
        ->toBe($messages[0])
        ->and(WorkerProcess::firstUnclaimed($messages, WorkerProcess::takenKeys($inFlightAfter, $finishedDuringRead, [])))
        ->toBe($messages[1]);
});

it('treats in-flight, finished-during-read and prefetched messages as taken', function () {
    $taken = WorkerProcess::takenKeys(
        ['torque:default|1-0' => true],
        ['torque:default|2-0' => true],
        [['stream' => 'torque:emails', 'id' => '3-0', 'payload' => 'c']],
    );

    expect($taken)->toBe([
        'torque:default|1-0' => true,
        'torque:default|2-0' => true,
        'torque:emails|3-0' => true,
    ]);
});
