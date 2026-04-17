<?php

declare(strict_types=1);

use Webpatser\Torque\Support\PayloadSanitizer;

describe('sanitizeMessage', function () {
    it('redacts passwords', function () {
        expect(PayloadSanitizer::sanitizeMessage('password="s3cret"'))
            ->toContain('password=***')
            ->not->toContain('s3cret');
    });

    it('redacts api keys with any casing/spacing', function () {
        expect(PayloadSanitizer::sanitizeMessage('API-Key: sk-live-abc123'))
            ->toContain('api_key=***')
            ->not->toContain('sk-live-abc123');
    });

    it('redacts generic tokens', function () {
        expect(PayloadSanitizer::sanitizeMessage('token=eyJhbGciOi.foo'))
            ->toContain('token=***')
            ->not->toContain('eyJhbGciOi.foo');
    });

    it('redacts bearer tokens inside an Authorization header', function () {
        expect(PayloadSanitizer::sanitizeMessage('Authorization: Bearer abc123.def456'))
            ->not->toContain('abc123.def456');
    });

    it('redacts a standalone bearer token', function () {
        expect(PayloadSanitizer::sanitizeMessage('sent header Bearer abc123.def456 to upstream'))
            ->toContain('Bearer ***')
            ->not->toContain('abc123.def456');
    });

    it('redacts uri-embedded credentials', function () {
        expect(PayloadSanitizer::sanitizeMessage('redis://user:secretPass@redis.local:6379'))
            ->toContain('://***:***@')
            ->not->toContain('secretPass');
    });

    it('truncates long messages with ellipsis', function () {
        $long = str_repeat('a', 600);
        $result = PayloadSanitizer::sanitizeMessage($long, 500);

        expect(mb_strlen($result))->toBe(501)
            ->and($result)->toEndWith('…');
    });

    it('does not truncate when maxLength is null', function () {
        $long = str_repeat('a', 600);
        $result = PayloadSanitizer::sanitizeMessage($long, null);

        expect(mb_strlen($result))->toBe(600);
    });
});

describe('sanitizePayload', function () {
    it('redacts values under secret-sounding keys', function () {
        $payload = PayloadSanitizer::sanitizePayload([
            'password' => 'hunter2',
            'api_key' => 'sk-live-123',
            'access_token' => 'xoxb-abc',
            'client_secret' => 'shh',
        ]);

        expect($payload['password'])->toBe('***')
            ->and($payload['api_key'])->toBe('***')
            ->and($payload['access_token'])->toBe('***')
            ->and($payload['client_secret'])->toBe('***');
    });

    it('recurses into nested arrays', function () {
        $payload = PayloadSanitizer::sanitizePayload([
            'connection' => [
                'host' => 'db.internal',
                'password' => 'hunter2',
            ],
        ]);

        expect($payload['connection']['host'])->toBe('db.internal')
            ->and($payload['connection']['password'])->toBe('***');
    });

    it('sanitizes string leaves with secret patterns', function () {
        $payload = PayloadSanitizer::sanitizePayload([
            'message' => 'connecting to redis://u:p@host:6379 with api_key=sk-xyz',
        ]);

        expect($payload['message'])
            ->toContain('://***:***@')
            ->toContain('api_key=***')
            ->not->toContain('sk-xyz');
    });

    it('preserves non-secret keys and values', function () {
        $payload = PayloadSanitizer::sanitizePayload([
            'user_id' => 42,
            'email' => 'foo@example.com',
        ]);

        expect($payload['user_id'])->toBe(42)
            ->and($payload['email'])->toBe('foo@example.com');
    });
});
