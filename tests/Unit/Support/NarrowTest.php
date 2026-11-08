<?php

declare(strict_types=1);

use Webpatser\Torque\Support\Narrow;

$scalars = [
    'int' => [42],
    'negative int' => [-7],
    'numeric string' => ['12'],
    'leading-numeric string' => ['12abc'],
    'non-numeric string' => ['abc'],
    'empty string' => [''],
    'float string' => ['3.75'],
    'exponent string' => ['1e3'],
    'float' => [3.75],
    'negative float' => [-2.5],
    'true' => [true],
    'false' => [false],
    'zero string' => ['0'],
];

it('narrows scalars to int exactly as (int) does', function (mixed $value) {
    expect(Narrow::int($value, 99))->toBe((int) $value)
        ->and(Narrow::nullableInt($value))->toBe((int) $value);
})->with($scalars);

it('narrows scalars to float exactly as (float) does', function (mixed $value) {
    expect(Narrow::float($value, 99.0))->toBe((float) $value);
})->with($scalars);

it('narrows scalars to string exactly as (string) does', function (mixed $value) {
    expect(Narrow::string($value, 'default'))->toBe((string) $value)
        ->and(Narrow::nullableString($value))->toBe((string) $value);
})->with($scalars);

it('narrows scalars to bool exactly as (bool) does', function (mixed $value) {
    expect(Narrow::bool($value, true))->toBe((bool) $value);
})->with($scalars);

it('gives the cast of null when no default is passed', function () {
    expect(Narrow::int(null))->toBe((int) null)
        ->and(Narrow::float(null))->toBe((float) null)
        ->and(Narrow::string(null))->toBe((string) null)
        ->and(Narrow::bool(null))->toBe((bool) null);
});

it('gives the default for null and non-scalars', function (mixed $value) {
    expect(Narrow::int($value, 5))->toBe(5)
        ->and(Narrow::float($value, 1.5))->toBe(1.5)
        ->and(Narrow::string($value, 'x'))->toBe('x')
        ->and(Narrow::bool($value, true))->toBeTrue()
        ->and(Narrow::nullableInt($value))->toBeNull()
        ->and(Narrow::nullableString($value))->toBeNull();
})->with([
    'null' => [null],
    'empty array' => [[]],
    'array' => [[1, 2]],
    'object' => [new stdClass],
]);

it('casts a Stringable to string', function () {
    $value = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'stringed';
        }
    };

    expect(Narrow::string($value))->toBe('stringed')
        ->and(Narrow::nullableString($value))->toBe('stringed')
        ->and(Narrow::int($value, 3))->toBe(3);
});

it('narrows arrays to a list and a map', function () {
    expect(Narrow::list([1, 'a']))->toBe([1, 'a'])
        ->and(Narrow::list(['x' => 1, 'y' => 2]))->toBe([1, 2])
        ->and(Narrow::list('nope'))->toBe([])
        ->and(Narrow::list(null))->toBe([])
        ->and(Narrow::map(['a' => 1]))->toBe(['a' => 1])
        ->and(Narrow::map(7))->toBe([]);
});

it('reads a flat field reply as a map', function () {
    expect(Narrow::pairs(['name', 'w1', 'pending', '3', 'idle', 120]))
        ->toBe(['name' => 'w1', 'pending' => '3', 'idle' => 120]);
});

it('keeps the first of a repeated field and drops a dangling one', function () {
    expect(Narrow::pairs(['payload', 'first', 'payload', 'second', 'orphan']))
        ->toBe(['payload' => 'first']);
});

it('passes a map reply through and reads anything else as empty', function () {
    expect(Narrow::pairs(['a' => '1']))->toBe(['a' => '1'])
        ->and(Narrow::pairs(null))->toBe([])
        ->and(Narrow::pairs('x'))->toBe([]);
});

it('reads stream entries and keeps an entry without fields', function () {
    $reply = [
        ['1-0', ['payload', '{"a":1}']],
        ['2-0', null],
        'garbage',
        [null, ['payload', 'x']],
    ];

    expect(Narrow::entries($reply))->toBe([
        ['id' => '1-0', 'fields' => ['payload' => '{"a":1}']],
        ['id' => '2-0', 'fields' => []],
    ])->and(Narrow::entries(null))->toBe([]);
});

it('reads an XREADGROUP reply by stream key', function () {
    $reply = [
        ['torque:default', [['1-0', ['payload', 'p1']]]],
        ['torque:high', []],
    ];

    expect(Narrow::streams($reply))->toBe([
        'torque:default' => [['id' => '1-0', 'fields' => ['payload' => 'p1']]],
        'torque:high' => [],
    ])->and(Narrow::streams(null))->toBe([]);
});

it('reads an extended XPENDING reply', function () {
    $reply = [
        ['1-0', 'host-1-abcd1234', 1500, 2],
        ['2-0', 'host-2-abcd1234', '30', '1'],
        'garbage',
    ];

    expect(Narrow::pending($reply))->toBe([
        ['id' => '1-0', 'consumer' => 'host-1-abcd1234', 'idle' => 1500, 'deliveries' => 2],
        ['id' => '2-0', 'consumer' => 'host-2-abcd1234', 'idle' => 30, 'deliveries' => 1],
    ])->and(Narrow::pending(null))->toBe([]);
});
