<?php

declare(strict_types=1);

use Webpatser\Torque\Control\ControlChannel;

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-control-'.bin2hex(random_bytes(4)).':';
    $this->now = 1_800_000_000;
    $this->channel = new ControlChannel($this->redis, $this->prefix, 60, fn (): int => $this->now);
    $this->master = 'web-1-4321-0a1b2c3d';
    $this->listKey = $this->prefix.'control:'.$this->master;
    $this->ackIds = [];

    $this->command = fn (array $overrides = []): array => [
        'id' => str_repeat('ab', 16),
        'action' => 'stop',
        'issued_at' => $this->now,
        'expires_at' => $this->now + 60,
        'issuer' => 'cli@web-2',
        ...$overrides,
    ];
});

afterEach(function () {
    $this->redis->execute('DEL', $this->listKey);

    foreach (array_unique($this->ackIds) as $id) {
        $this->redis->execute('DEL', $this->prefix.'control:ack:'.$id);
    }
});

it('sends a command that the master receives as accepted', function () {
    $sent = $this->channel->send($this->master, 'drain', 'cli@web-2');

    expect($sent)->toMatchArray(['action' => 'drain', 'issued_at' => $this->now, 'expires_at' => $this->now + 60, 'issuer' => 'cli@web-2'])
        ->and($sent['id'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($this->redis->execute('TTL', $this->listKey))->toBe(ControlChannel::LIST_TTL_SECONDS);

    expect($this->channel->receive($this->master))->toBe([
        ['accepted' => true, 'reason' => null, 'id' => $sent['id'], 'command' => $sent],
    ])->and($this->channel->receive($this->master))->toBe([]);
});

it('uses the configured command ttl', function () {
    $channel = new ControlChannel($this->redis, $this->prefix, 5, fn (): int => $this->now);

    expect($channel->send($this->master, 'stop', 'cli')['expires_at'])->toBe($this->now + 5);
});

it('receives at most ten commands per call in order', function () {
    $ids = [];

    for ($i = 0; $i < 12; $i++) {
        $ids[] = $this->channel->send($this->master, 'stop', 'cli')['id'];
    }

    expect(array_column($this->channel->receive($this->master), 'id'))->toBe(array_slice($ids, 0, 10))
        ->and(array_column($this->channel->receive($this->master), 'id'))->toBe(array_slice($ids, 10));
});

// An array is a set of overrides on a valid command (`__unset` drops a field);
// a string is pushed as it is.
it('rejects a raw entry with a reason and never throws', function (string|array $input, string $reason, ?string $id) {
    if (is_array($input)) {
        $unset = $input['__unset'] ?? null;
        unset($input['__unset']);
        $command = ($this->command)($input);

        if ($unset !== null) {
            unset($command[$unset]);
        }

        $input = json_encode($command, JSON_THROW_ON_ERROR);
    }

    $this->redis->execute('RPUSH', $this->listKey, $input);

    expect($this->channel->receive($this->master))->toBe([
        ['accepted' => false, 'reason' => $reason, 'id' => $id, 'command' => null],
    ]);
})->with([
    'not json' => ['{nope', ControlChannel::REASON_MALFORMED, null],
    'a json list' => ['["stop"]', ControlChannel::REASON_MALFORMED, null],
    'a json scalar' => ['"stop"', ControlChannel::REASON_MALFORMED, null],
    'empty object' => ['{}', ControlChannel::REASON_MISSING_FIELD, null],
    'too large' => [['issuer' => str_repeat('x', 2000)], ControlChannel::REASON_MALFORMED, null],
    'extra argument' => [['queue' => 'default'], ControlChannel::REASON_UNEXPECTED_FIELDS, str_repeat('ab', 16)],
    'missing field' => [['__unset' => 'issuer'], ControlChannel::REASON_MISSING_FIELD, str_repeat('ab', 16)],
    'bad id' => [['id' => '../../x'], ControlChannel::REASON_INVALID_FIELD, null],
    'uppercase id' => [['id' => str_repeat('AB', 16)], ControlChannel::REASON_INVALID_FIELD, null],
    'unknown action' => [['action' => 'exec'], ControlChannel::REASON_UNKNOWN_ACTION, str_repeat('ab', 16)],
    'action nested too deep' => [['action' => ['stop']], ControlChannel::REASON_MALFORMED, null],
    'action not a string' => [['action' => 1], ControlChannel::REASON_INVALID_FIELD, str_repeat('ab', 16)],
    'issued_at as string' => [['issued_at' => '1800000000'], ControlChannel::REASON_INVALID_FIELD, str_repeat('ab', 16)],
    'expires before issued' => [['expires_at' => 1_799_999_999], ControlChannel::REASON_INVALID_FIELD, str_repeat('ab', 16)],
    'lifetime too long' => [['expires_at' => 1_800_003_601], ControlChannel::REASON_INVALID_FIELD, str_repeat('ab', 16)],
    'issuer with newline' => [['issuer' => "cli\nINFO fake"], ControlChannel::REASON_INVALID_FIELD, str_repeat('ab', 16)],
    'empty issuer' => [['issuer' => ''], ControlChannel::REASON_INVALID_FIELD, str_repeat('ab', 16)],
]);

it('rejects an expired command but keeps it for the log', function () {
    $sent = $this->channel->send($this->master, 'stop', 'cli');
    $this->now += 60;

    expect($this->channel->receive($this->master))->toBe([
        ['accepted' => false, 'reason' => ControlChannel::REASON_EXPIRED, 'id' => $sent['id'], 'command' => $sent],
    ]);
});

it('checks expiry against the clock', function () {
    $command = ($this->command)();

    expect($this->channel->isExpired($command))->toBeFalse();

    $this->now += 59;
    expect($this->channel->isExpired($command))->toBeFalse();

    $this->now += 1;
    expect($this->channel->isExpired($command))->toBeTrue();
});

it('round trips an ack', function () {
    $id = str_repeat('cd', 16);
    $this->ackIds[] = $id;

    expect($this->channel->readAck($id))->toBeNull()
        ->and($this->channel->ack($id, $this->master, 'drain', true))->toBeTrue()
        ->and($this->channel->readAck($id))->toBe(['master' => $this->master, 'action' => 'drain', 'accepted' => true, 'at' => $this->now])
        ->and($this->redis->execute('TTL', $this->prefix.'control:ack:'.$id))->toBe(ControlChannel::ACK_TTL_SECONDS);
});

it('acks an unknown action as unknown', function () {
    $id = str_repeat('ef', 16);
    $this->ackIds[] = $id;

    $this->channel->ack($id, $this->master, 'exec', false);

    expect($this->channel->readAck($id))->toMatchArray(['action' => 'unknown', 'accepted' => false]);
});

it('writes no ack for an invalid id', function () {
    expect($this->channel->ack('../x', $this->master, 'stop', true))->toBeFalse()
        ->and($this->channel->ack(str_repeat('ab', 16), 'not a master', 'stop', true))->toBeFalse()
        ->and($this->channel->readAck('../x'))->toBeNull();
});

it('reads a tampered ack as missing', function (string $raw) {
    $id = str_repeat('12', 16);
    $this->ackIds[] = $id;
    $this->redis->execute('SET', $this->prefix.'control:ack:'.$id, $raw);

    expect($this->channel->readAck($id))->toBeNull();
})->with([
    'not json' => ['{nope'],
    'bad master' => ['{"master":"x","action":"stop","accepted":true,"at":1}'],
    'bad action' => ['{"master":"web-1-4321-0a1b2c3d","action":"exec","accepted":true,"at":1}'],
    'accepted as string' => ['{"master":"web-1-4321-0a1b2c3d","action":"stop","accepted":"yes","at":1}'],
]);

it('refuses to send an invalid command', function (string $master, string $action, string $issuer) {
    $this->channel->send($master, $action, $issuer);
})->with([
    'bad master id' => ['web-1', 'stop', 'cli'],
    'unknown action' => ['web-1-4321-0a1b2c3d', 'exec', 'cli'],
    'bad issuer' => ['web-1-4321-0a1b2c3d', 'stop', "cli\n"],
])->throws(InvalidArgumentException::class);
