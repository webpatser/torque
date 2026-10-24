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

    it('redacts basic credentials inside an Authorization header', function () {
        $credentials = base64_encode('user:hunter2');
        $message = 'Authorization: Basic '.$credentials;

        expect($message)->toContain($credentials);

        expect(PayloadSanitizer::sanitizeMessage($message))
            ->toContain('***')
            ->not->toContain($credentials);
    });

    it('redacts basic credentials in a json header bag', function () {
        $credentials = base64_encode('user:hunter2');

        expect(PayloadSanitizer::sanitizeMessage('{"Authorization":"Basic '.$credentials.'"}'))
            ->not->toContain($credentials);
    });

    it('redacts a standalone basic credential', function () {
        $credentials = base64_encode('user:hunter2');

        expect(PayloadSanitizer::sanitizeMessage('upstream rejected Basic '.$credentials.' with 401'))
            ->toBe('upstream rejected Basic *** with 401');
    });

    it('leaves the word basic in prose alone', function () {
        expect(PayloadSanitizer::sanitizeMessage('basic usage of the client failed'))
            ->toBe('basic usage of the client failed');
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

    it('redacts secret properties inside a serialized data.command', function () {
        $command = serialize((object) ['password' => 'hunter2', 'email' => 'foo@example.com']);

        expect($command)->toContain('s:8:"password";s:7:"hunter2";');

        $payload = PayloadSanitizer::sanitizePayload([
            'displayName' => 'App\\Jobs\\SyncAccount',
            'data' => ['commandName' => 'App\\Jobs\\SyncAccount', 'command' => $command],
        ]);

        $sanitized = $payload['data']['command'];

        expect($sanitized)
            ->toContain('s:8:"password";s:3:"***";')
            ->toContain('s:5:"email";s:15:"foo@example.com";')
            ->not->toContain('hunter2')
            ->and(unserialize($sanitized, ['allowed_classes' => [stdClass::class]])->password)->toBe('***');
    });

    it('redacts protected and private serialized properties with correct lengths', function () {
        $command = 'O:20:"App\\Jobs\\SyncAccount":3:{'
            ."s:11:\"\0*\0password\";s:7:\"hunter2\";"
            ."s:30:\"\0App\\Jobs\\SyncAccount\0apiToken\";s:10:\"tok-abc123\";"
            ."s:8:\"\0*\0tries\";i:3;"
            .'}';

        expect(unserialize($command, ['allowed_classes' => false]))->not->toBeFalse();

        $sanitized = PayloadSanitizer::sanitizeSerialized($command);

        expect($sanitized)
            ->toContain("s:11:\"\0*\0password\";s:3:\"***\";")
            ->toContain("s:30:\"\0App\\Jobs\\SyncAccount\0apiToken\";s:3:\"***\";")
            ->toContain("s:8:\"\0*\0tries\";i:3;")
            ->not->toContain('hunter2')
            ->not->toContain('tok-abc123')
            ->and(unserialize($sanitized, ['allowed_classes' => false]))->not->toBeFalse();
    });

    it('does not mistake a secret-sounding string value for a key', function () {
        $sanitized = PayloadSanitizer::sanitizeSerialized(serialize(['type' => 'password', 'token' => 'abc-secret-value']));

        expect($sanitized)
            ->toContain('s:4:"type";s:8:"password";')
            ->toContain('s:5:"token";s:3:"***";')
            ->not->toContain('abc-secret-value')
            ->and(unserialize($sanitized))->toBe(['type' => 'password', 'token' => '***']);
    });

    it('sanitizes serialized jobs nested as strings (chained) and string leaves', function () {
        $chained = serialize((object) ['client_secret' => 'nested-shh']);
        $command = serialize([
            'chained' => [$chained],
            'dsn' => 'redis://user:hunter2@redis.local:6379',
        ]);

        $sanitized = PayloadSanitizer::sanitizeSerialized($command);
        $decoded = unserialize($sanitized, ['allowed_classes' => false]);

        expect($sanitized)->not->toContain('nested-shh')->not->toContain('hunter2')
            ->and($decoded)->toBeArray()
            ->and($decoded['dsn'])->toBe('redis://***:***@redis.local:6379')
            ->and(unserialize($decoded['chained'][0], ['allowed_classes' => [stdClass::class]])->client_secret)->toBe('***');
    });

    it('fails closed on truncated serialized data', function () {
        $truncated = substr(serialize((object) ['password' => 'hunter2', 'x' => str_repeat('y', 50)]), 0, 70);

        expect($truncated)->toContain('hunter2');

        expect(PayloadSanitizer::sanitizeSerialized($truncated))
            ->not->toContain('hunter2')
            ->toContain('stdClass');
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

describe('sanitizeEvents', function () {
    it('redacts exception messages and keeps an embedded json payload valid', function () {
        $credentials = base64_encode('user:hunter2');
        $payload = json_encode([
            'uuid' => 'abc',
            'data' => ['command' => serialize((object) ['password' => 'hunter2'])],
        ]);

        $events = PayloadSanitizer::sanitizeEvents([
            ['id' => '1-0', 'type' => 'queued', 'data' => ['queue' => 'default', 'payload' => $payload]],
            ['id' => '2-0', 'type' => 'failed', 'data' => ['exception_message' => 'Authorization: Basic '.$credentials]],
        ]);

        $decoded = json_decode($events[0]['data']['payload'], true);

        expect($events[0]['data']['queue'])->toBe('default')
            ->and($events[0]['data']['payload'])->not->toContain('hunter2')
            ->and($decoded['data']['command'])->toContain('s:8:"password";s:3:"***";')
            ->and($events[1]['data']['exception_message'])->not->toContain($credentials);
    });
});
