<?php

declare(strict_types=1);

use Webpatser\Torque\Support\WorkerId;

it('parses the canonical host-pid-nonce worker id', function () {
    $id = WorkerId::parse('web-01-5123-a1b2c3d4');

    expect($id->host)->toBe('web-01')
        ->and($id->pid)->toBe(5123)
        ->and($id->nonce)->toBe('a1b2c3d4');
});

it('parses a hostname without dashes', function () {
    $id = WorkerId::parse('98d645322a81-46-b77a6f6f');

    expect($id->host)->toBe('98d645322a81')
        ->and($id->pid)->toBe(46)
        ->and($id->nonce)->toBe('b77a6f6f');
});

it('parses a hostname with many dashes', function () {
    $id = WorkerId::parse('ip-10-0-12-9.eu-west-1-31337-deadbeef');

    expect($id->host)->toBe('ip-10-0-12-9.eu-west-1')
        ->and($id->pid)->toBe(31337)
        ->and($id->nonce)->toBe('deadbeef');
});

it('falls back to the legacy host-pid shape', function () {
    $id = WorkerId::parse('web-01-5123');

    expect($id->host)->toBe('web-01')
        ->and($id->pid)->toBe(5123)
        ->and($id->nonce)->toBeNull();
});

it('does not mistake an all-digit nonce-less tail pair for a nonce', function () {
    // `host-99-12345678`: the tail is 8 chars and hex-digit, so it parses as
    // a nonce with pid 99; this is the canonical shape and takes precedence.
    $id = WorkerId::parse('host-99-12345678');

    expect($id->pid)->toBe(99)
        ->and($id->nonce)->toBe('12345678');
});

it('returns a null pid when nothing numeric is present', function () {
    $id = WorkerId::parse('just-a-hostname');

    expect($id->host)->toBe('just-a-hostname')
        ->and($id->pid)->toBeNull()
        ->and($id->nonce)->toBeNull();
});
