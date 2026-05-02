<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;

// -------------------------------------------------------------------------
//  decodePayload (sniffing)
// -------------------------------------------------------------------------

it('decodes a JSON payload', function () {
    $raw = json_encode(['uuid' => 'abc-123', 'job' => 'App\\Jobs\\Foo', 'attempts' => 0]);

    $decoded = StreamQueue::decodePayload($raw);

    expect($decoded)->toBe(['uuid' => 'abc-123', 'job' => 'App\\Jobs\\Foo', 'attempts' => 0]);
});

it('decodes a JSON array payload', function () {
    $raw = json_encode([1, 2, 3]);

    $decoded = StreamQueue::decodePayload($raw);

    expect($decoded)->toBe([1, 2, 3]);
});

it('decodes an igbinary payload', function () {
    $raw = igbinary_serialize(['uuid' => 'xyz-789', 'attempts' => 1]);

    $decoded = StreamQueue::decodePayload($raw);

    expect($decoded)->toBe(['uuid' => 'xyz-789', 'attempts' => 1]);
})->skip(! extension_loaded('igbinary'), 'ext-igbinary not loaded');

it('throws on an unrecognized payload format', function () {
    StreamQueue::decodePayload("\x01\x02garbage");
})->throws(InvalidArgumentException::class, 'Unrecognized payload format');

it('throws on an empty payload', function () {
    StreamQueue::decodePayload('');
})->throws(InvalidArgumentException::class, 'Unrecognized payload format');

it('throws on malformed JSON that starts with a brace', function () {
    StreamQueue::decodePayload('{not-real-json');
})->throws(JsonException::class);

// -------------------------------------------------------------------------
//  isValidPayload
// -------------------------------------------------------------------------

it('considers a JSON object valid', function () {
    expect(StreamQueue::isValidPayload('{"a":1}'))->toBeTrue();
});

it('considers a JSON array valid', function () {
    expect(StreamQueue::isValidPayload('[1,2,3]'))->toBeTrue();
});

it('considers a real igbinary blob valid', function () {
    $blob = igbinary_serialize(['x' => 'y']);

    expect(StreamQueue::isValidPayload($blob))->toBeTrue();
})->skip(! extension_loaded('igbinary'), 'ext-igbinary not loaded');

it('considers garbage with leading nulls invalid', function () {
    expect(StreamQueue::isValidPayload("\x00\x00garbage"))->toBeFalse();
});

it('considers a non-JSON, non-igbinary string invalid', function () {
    expect(StreamQueue::isValidPayload('not json'))->toBeFalse();
});

it('considers an empty string invalid', function () {
    expect(StreamQueue::isValidPayload(''))->toBeFalse();
});

it('considers malformed JSON invalid', function () {
    expect(StreamQueue::isValidPayload('{not real json'))->toBeFalse();
});

// -------------------------------------------------------------------------
//  Encode roundtrip (via reflection so encodePayload stays private)
// -------------------------------------------------------------------------

it('roundtrips a payload through json mode', function () {
    $queue = new StreamQueue(
        redisUri: 'redis://127.0.0.1:6379',
        prefix: 'app:',
        serializer: 'json',
    );

    $original = ['uuid' => 'r-1', 'job' => 'App\\Jobs\\Demo', 'attempts' => 0, 'data' => ['a' => 1]];

    $encoded = invokePrivateEncode($queue, $original);

    expect($encoded[0])->toBe('{');
    expect(StreamQueue::decodePayload($encoded))->toBe($original);
});

it('roundtrips a payload through igbinary mode', function () {
    $queue = new StreamQueue(
        redisUri: 'redis://127.0.0.1:6379',
        prefix: 'app:',
        serializer: 'igbinary',
    );

    $original = ['uuid' => 'r-2', 'job' => 'App\\Jobs\\Demo', 'attempts' => 2, 'data' => ['nested' => [1, 2, 3]]];

    $encoded = invokePrivateEncode($queue, $original);

    expect(str_starts_with($encoded, "\x00\x00\x00\x02"))->toBeTrue();
    expect(StreamQueue::decodePayload($encoded))->toBe($original);
})->skip(! extension_loaded('igbinary'), 'ext-igbinary not loaded');

/**
 * Reach into the private encodePayload method without exposing it on the API.
 */
function invokePrivateEncode(StreamQueue $queue, array $payload): string
{
    $ref = new ReflectionMethod(StreamQueue::class, 'encodePayload');

    return (string) $ref->invoke($queue, $payload);
}
