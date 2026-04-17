<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

/**
 * Redacts secrets from strings and arrays before they are displayed in the
 * dashboard, emailed, or written to logs.
 *
 * A jobs payload or exception message can easily carry credentials that would
 * otherwise be rendered verbatim to any user with dashboard access.
 */
final class PayloadSanitizer
{
    private const REDACTION = '***';

    /**
     * Patterns that match "key=value" or "key: value" style secrets inside a
     * free-form string. Each entry is [pattern, replacement].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const MESSAGE_PATTERNS = [
        // URI creds and Bearer tokens must run before the generic `key=value`
        // patterns, otherwise "Authorization: Bearer xxx" would match the
        // authorization rule first and leak the token that trails the space.
        ['/:\/\/[^:\/\s]+:[^@\s]+@/', '://'.self::REDACTION.':'.self::REDACTION.'@'],
        ['/(?<![A-Za-z])bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer '.self::REDACTION],
        ['/password[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'password='.self::REDACTION],
        ['/passwd[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'passwd='.self::REDACTION],
        ['/api[_\-]?key[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'api_key='.self::REDACTION],
        ['/secret[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'secret='.self::REDACTION],
        ['/token[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'token='.self::REDACTION],
        ['/authorization[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'authorization='.self::REDACTION],
    ];

    /**
     * Keys whose values should be fully redacted in an array payload.
     * Matching is case-insensitive and substring-based.
     *
     * @var array<int, string>
     */
    private const SECRET_KEY_NEEDLES = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'bearer',
        'private_key',
        'access_key',
        'client_secret',
    ];

    /**
     * Redact known secret patterns from a free-form string and optionally
     * truncate the result.
     */
    public static function sanitizeMessage(string $message, ?int $maxLength = 500): string
    {
        foreach (self::MESSAGE_PATTERNS as [$pattern, $replacement]) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }

        if ($maxLength !== null && mb_strlen($message) > $maxLength) {
            return mb_substr($message, 0, $maxLength).'…';
        }

        return $message;
    }

    /**
     * Recursively redact secret-keyed values and sanitize string leaves in an
     * array payload.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function sanitizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $payload[$key] = self::REDACTION;
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::sanitizePayload($value);
                continue;
            }

            if (is_string($value)) {
                $payload[$key] = self::sanitizeMessage($value, null);
            }
        }

        return $payload;
    }

    private static function isSecretKey(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::SECRET_KEY_NEEDLES as $secret) {
            if (str_contains($needle, $secret)) {
                return true;
            }
        }

        return false;
    }
}
