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
