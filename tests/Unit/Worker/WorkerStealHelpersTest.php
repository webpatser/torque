<?php

declare(strict_types=1);

use Webpatser\Torque\Worker\WorkerProcess;

/*
 * Redis below 7 answers XAUTOCLAIM with nil for an entry trimmed from the stream,
 * which a current server cannot produce, so these reply shapes are written out by
 * hand. tests/Integration/WorkerStealTest.php covers the live replies.
 */

it('parses a claimed message and the next cursor', function () {
    $claim = WorkerProcess::parseClaimReply(['5-0', [['1-0', ['payload', '{}']]], []], 'stream');

    expect($claim)->toBe([
        'cursor' => '5-0',
        'messages' => [['stream' => 'stream', 'id' => '1-0', 'payload' => '{}']],
        'trimmed' => [],
    ]);
});

it('tolerates nil and empty claim entries', function (array $entry, array $trimmed) {
    $claim = WorkerProcess::parseClaimReply(['0-0', [$entry]], 'stream');

    expect($claim['messages'])->toBe([])
        ->and($claim['trimmed'])->toBe($trimmed);
})->with([
    'id with nil fields' => [['1-0', null], ['1-0']],
    'id with no fields' => [['1-0', []], ['1-0']],
    'id alone' => [['1-0'], ['1-0']],
    'empty entry' => [[], []],
]);

it('tolerates a claim reply holding a bare nil entry', function () {
    expect(WorkerProcess::parseClaimReply(['0-0', [null]], 'stream'))
        ->toBe(['cursor' => '0-0', 'messages' => [], 'trimmed' => []]);
});

it('treats anything but an array as an empty claim', function (mixed $reply) {
    expect(WorkerProcess::parseClaimReply($reply, 'stream'))
        ->toBe(['cursor' => '0-0', 'messages' => [], 'trimmed' => []]);
})->with([[null], ['OK'], [[]]]);

it('lists the entries of a pending read that lost their fields', function () {
    $reply = [['stream', [['1-0', null], ['2-0', ['payload', '{}']], ['3-0', []]]], null];

    expect(WorkerProcess::trimmedPendingEntries($reply))->toBe([
        ['stream' => 'stream', 'id' => '1-0'],
        ['stream' => 'stream', 'id' => '3-0'],
    ]);
});

it('removes only a hash tag wrapping the whole queue name, in cluster mode only', function (string $queue, bool $cluster, string $expected) {
    expect(WorkerProcess::bareQueueName($queue, $cluster))->toBe($expected);
})->with([
    'tagged, cluster' => ['{q}', true, 'q'],
    'plain, cluster' => ['q', true, 'q'],
    'partial tag, cluster' => ['foo{bar}', true, 'foo{bar}'],
    'tagged, no cluster' => ['{q}', false, '{q}'],
]);

it('resolves a stream key to the bare queue name', function () {
    expect(WorkerProcess::queueNameFromStreamKey('torque:{q}', 'torque:', true))->toBe('q')
        ->and(WorkerProcess::queueNameFromStreamKey('torque:q', 'torque:', false))->toBe('q')
        ->and(WorkerProcess::queueNameFromStreamKey('torque:{q}', 'torque:', false))->toBe('{q}');
});

it('keys stream settings by the bare name, preferring an entry already under it', function () {
    $streams = ['{a}' => ['max_retries' => 1], 'b' => ['max_retries' => 2], '{b}' => ['max_retries' => 3]];

    expect(WorkerProcess::bareStreamConfigs($streams, true))->toBe([
        'a' => ['max_retries' => 1],
        'b' => ['max_retries' => 2],
    ])->and(WorkerProcess::bareStreamConfigs($streams, false))->toBe($streams);
});
