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
     * Nesting depth walked inside a PHP-serialized string before giving up and
     * redacting the whole value.
     */
    private const SERIALIZED_MAX_DEPTH = 64;

    /**
     * Patterns that match "key=value" or "key: value" style secrets inside a
     * free-form string. Each entry is [pattern, replacement].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const MESSAGE_PATTERNS = [
        // URI creds and Bearer/Basic credentials must run before the generic
        // `key=value` patterns, otherwise "Authorization: Bearer xxx" would
        // match the authorization rule first and leak the token that trails
        // the space.
        ['/:\/\/[^:\/\s]+:[^@\s]+@/', '://'.self::REDACTION.':'.self::REDACTION.'@'],
        ['/(?<![A-Za-z])bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer '.self::REDACTION],
        ['/(authorization[\'"]?\s*[:=]\s*[\'"]?)basic\s+[A-Za-z0-9\-._~+\/]+=*/i', '$1Basic '.self::REDACTION],
        ['/password[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'password='.self::REDACTION],
        ['/passwd[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'passwd='.self::REDACTION],
        ['/api[_\-]?key[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'api_key='.self::REDACTION],
        ['/secret[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'secret='.self::REDACTION],
        ['/token[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'token='.self::REDACTION],
        ['/authorization[\'"]?\s*[:=]\s*[\'"]?[^\s\'",]+/i', 'authorization='.self::REDACTION],
    ];

    /**
     * A "Basic <base64>" credential outside an Authorization header. Only
     * redacted when the blob decodes to `user:pass`, so prose such as
     * "basic usage" is left alone.
     */
    private const STANDALONE_BASIC_PATTERN = '/(?<![A-Za-z])basic\s+([A-Za-z0-9+\/]{4,}={0,2})(?![A-Za-z0-9+\/=])/i';

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

        $message = (string) preg_replace_callback(
            self::STANDALONE_BASIC_PATTERN,
            static function (array $match): string {
                $decoded = base64_decode($match[1], true);

                return $decoded !== false && str_contains($decoded, ':')
                    ? 'Basic '.self::REDACTION
                    : $match[0];
            },
            $message,
        );

        if ($maxLength !== null && mb_strlen($message) > $maxLength) {
            return mb_substr($message, 0, $maxLength).'…';
        }

        return $message;
    }

    /**
     * Recursively redact secret-keyed values and sanitize string leaves in an
     * array payload.
     *
     * String leaves holding PHP-serialized data (a queued job's `data.command`)
     * are walked property by property, see {@see sanitizeSerialized()}.
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
                $payload[$key] = self::sanitizeString($value, 0);
            }
        }

        return $payload;
    }

    /**
     * Redact secrets inside a PHP-serialized string.
     *
     * Every property or array key whose name looks secret (public `name`,
     * protected `\0*\0name` and private `\0Class\0name` alike) gets its value
     * replaced by `s:3:"***";`, and every other string leaf runs through
     * {@see sanitizeMessage()}. All `s:N` lengths are recomputed, so the
     * result is still well-formed serialized data. Input that cannot be
     * parsed is not shown at all.
     */
    public static function sanitizeSerialized(string $serialized): string
    {
        return self::sanitizeSerializedAt($serialized, 0);
    }

    /**
     * Sanitize a job's lifecycle events (`['type' => ..., 'data' => [...]]`)
     * before they reach a view or a Livewire snapshot. An embedded JSON
     * `payload` is decoded, sanitized and re-encoded so it stays valid JSON.
     *
     * @param  array<array-key, mixed>  $events
     * @return array<array-key, mixed>
     */
    public static function sanitizeEvents(array $events): array
    {
        foreach ($events as $index => $event) {
            if (! is_array($event) || ! isset($event['data']) || ! is_array($event['data'])) {
                continue;
            }

            $data = $event['data'];
            $rawPayload = $data['payload'] ?? null;
            unset($data['payload']);

            $data = self::sanitizePayload($data);

            if (is_string($rawPayload)) {
                $decoded = json_validate($rawPayload) ? json_decode($rawPayload, true) : null;

                $data['payload'] = is_array($decoded)
                    ? (string) json_encode(self::sanitizePayload($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : self::sanitizeMessage($rawPayload, null);
            }

            $events[$index]['data'] = $data;
        }

        return $events;
    }

    private static function sanitizeString(string $value, int $depth): string
    {
        if (self::looksSerialized($value)) {
            return self::sanitizeSerializedAt($value, $depth);
        }

        return self::sanitizeMessage($value, null);
    }

    private static function looksSerialized(string $value): bool
    {
        return preg_match('/\A(?:a:\d+:\{|[OC]:\d+:")/', $value) === 1;
    }

    private static function sanitizeSerializedAt(string $serialized, int $depth): string
    {
        $pos = 0;

        try {
            $sanitized = self::walkSerializedValue($serialized, $pos, $depth);

            if ($pos === strlen($serialized)) {
                return $sanitized;
            }
        } catch (\UnexpectedValueException) {
            // Fall through: fail closed below.
        }

        // Truncated, corrupted or too deeply nested. A partial parse cannot
        // tell keys from values reliably, so show nothing beyond the class.
        $class = preg_match('/\A[OC]:\d+:"([A-Za-z0-9_\\\\]+)"/', $serialized, $match) === 1 ? $match[1] : 'value';

        return '['.self::REDACTION.' unparseable serialized '.$class.' redacted]';
    }

    /**
     * Read one serialized value starting at `$pos`, advance `$pos` past it and
     * return its sanitized re-serialization.
     *
     * @throws \UnexpectedValueException on malformed input
     */
    private static function walkSerializedValue(string $s, int &$pos, int $depth): string
    {
        if ($depth > self::SERIALIZED_MAX_DEPTH) {
            throw new \UnexpectedValueException('Serialized data nested too deeply.');
        }

        $type = $s[$pos] ?? '';

        switch ($type) {
            case 'N':
                self::expect($s, $pos, 'N;');

                return 'N;';

            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                if (($s[$pos + 1] ?? '') !== ':') {
                    throw new \UnexpectedValueException('Malformed scalar.');
                }

                $end = strpos($s, ';', $pos);

                if ($end === false) {
                    throw new \UnexpectedValueException('Unterminated scalar.');
                }

                $token = substr($s, $pos, $end - $pos + 1);
                $pos = $end + 1;

                return $token;

            case 's':
                $value = self::readQuoted($s, $pos, 's');
                self::expect($s, $pos, ';');

                return self::serializeString(self::sanitizeString($value, $depth + 1));

            case 'E':
                $value = self::readQuoted($s, $pos, 'E');
                self::expect($s, $pos, ';');

                return 'E:'.strlen($value).':"'.$value.'";';

            case 'a':
                self::expect($s, $pos, 'a:');
                $count = self::readInt($s, $pos, ':');
                self::expect($s, $pos, '{');
                $body = self::walkSerializedPairs($s, $pos, $count, $depth);
                self::expect($s, $pos, '}');

                return 'a:'.$count.':{'.$body.'}';

            case 'O':
                $class = self::readQuoted($s, $pos, 'O');
                self::expect($s, $pos, ':');
                $count = self::readInt($s, $pos, ':');
                self::expect($s, $pos, '{');
                $body = self::walkSerializedPairs($s, $pos, $count, $depth);
                self::expect($s, $pos, '}');

                return 'O:'.strlen($class).':"'.$class.'":'.$count.':{'.$body.'}';

            case 'C':
                // Serializable::serialize() output: opaque bytes, usually
                // serialized data itself.
                $class = self::readQuoted($s, $pos, 'C');
                self::expect($s, $pos, ':');
                $length = self::readInt($s, $pos, ':');
                self::expect($s, $pos, '{');

                if ($pos + $length > strlen($s)) {
                    throw new \UnexpectedValueException('Truncated custom object.');
                }

                $inner = self::sanitizeString(substr($s, $pos, $length), $depth + 1);
                $pos += $length;
                self::expect($s, $pos, '}');

                return 'C:'.strlen($class).':"'.$class.'":'.strlen($inner).':{'.$inner.'}';

            default:
                throw new \UnexpectedValueException('Unsupported serialized type.');
        }
    }

    /**
     * Walk `$count` key/value pairs of an array or object body.
     *
     * @throws \UnexpectedValueException on malformed input
     */
    private static function walkSerializedPairs(string $s, int &$pos, int $count, int $depth): string
    {
        $body = '';

        for ($i = 0; $i < $count; $i++) {
            $keyType = $s[$pos] ?? '';

            if ($keyType === 'i') {
                $body .= self::walkSerializedValue($s, $pos, $depth + 1);
                $body .= self::walkSerializedValue($s, $pos, $depth + 1);

                continue;
            }

            if ($keyType !== 's') {
                throw new \UnexpectedValueException('Unsupported key type.');
            }

            $name = self::readQuoted($s, $pos, 's');
            self::expect($s, $pos, ';');
            $body .= self::serializeString($name);

            $value = self::walkSerializedValue($s, $pos, $depth + 1);

            $body .= self::isSecretKey(self::propertyName($name))
                ? self::serializeString(self::REDACTION)
                : $value;
        }

        return $body;
    }

    /**
     * Read `<type>:<len>:"<bytes>"` and return the bytes.
     *
     * @throws \UnexpectedValueException on malformed input
     */
    private static function readQuoted(string $s, int &$pos, string $type): string
    {
        self::expect($s, $pos, $type.':');
        $length = self::readInt($s, $pos, ':');
        self::expect($s, $pos, '"');

        if ($pos + $length + 1 > strlen($s) || $s[$pos + $length] !== '"') {
            throw new \UnexpectedValueException('String length mismatch.');
        }

        $value = substr($s, $pos, $length);
        $pos += $length + 1;

        return $value;
    }

    /**
     * Read an unsigned integer terminated by `$terminator` and consume both.
     *
     * @throws \UnexpectedValueException on malformed input
     */
    private static function readInt(string $s, int &$pos, string $terminator): int
    {
        $end = strpos($s, $terminator, $pos);

        if ($end === false) {
            throw new \UnexpectedValueException('Unterminated integer.');
        }

        $digits = substr($s, $pos, $end - $pos);

        if ($digits === '' || ! ctype_digit($digits)) {
            throw new \UnexpectedValueException('Malformed integer.');
        }

        $pos = $end + 1;

        return (int) $digits;
    }

    /**
     * @throws \UnexpectedValueException when `$s` does not continue with `$literal`
     */
    private static function expect(string $s, int &$pos, string $literal): void
    {
        if (substr($s, $pos, strlen($literal)) !== $literal) {
            throw new \UnexpectedValueException('Unexpected token.');
        }

        $pos += strlen($literal);
    }

    private static function serializeString(string $value): string
    {
        return 's:'.strlen($value).':"'.$value.'";';
    }

    /**
     * Strip the visibility prefix from a serialized property name: protected
     * properties are stored as `\0*\0name`, private ones as `\0Class\0name`.
     */
    private static function propertyName(string $name): string
    {
        if (! str_starts_with($name, "\0")) {
            return $name;
        }

        $separator = strpos($name, "\0", 1);

        return $separator === false ? $name : substr($name, $separator + 1);
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
