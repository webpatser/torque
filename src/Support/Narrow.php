<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

/**
 * Narrows untyped values (Redis replies, decoded payloads, config) to the type
 * the caller needs.
 *
 * Scalars convert exactly as PHP's casts do: `Narrow::int('12abc')` is
 * `(int) '12abc'`, `Narrow::string(false)` is `''`. Null and anything that is
 * not a scalar give the default, so for scalars and null `Narrow::int($v, $d)`
 * equals `(int) ($v ?? $d)`. The string helpers also accept a Stringable.
 *
 * The Redis helpers read the RESP2 shapes the async client returns and skip
 * rows that do not have them, so a malformed reply reads as an empty one.
 *
 * @internal
 */
final class Narrow
{
    public static function int(mixed $value, int $default = 0): int
    {
        return is_scalar($value) ? (int) $value : $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_scalar($value) ? (float) $value : $default;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : $default;
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        return is_scalar($value) ? (bool) $value : $default;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return is_scalar($value) ? (int) $value : null;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }

    /**
     * The values of an array as a list, an empty list for anything else.
     *
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_is_list($value) ? $value : array_values($value);
    }

    /**
     * An array with string keys (a decoded JSON object, a config section), an
     * empty array for anything else. The keys are not checked: integer keys
     * pass through as they are.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A flat field/value reply (`[field, value, ...]`, as from HGETALL, XINFO
     * or a stream entry) as a map. The first of a repeated field wins and a
     * trailing field without a value is dropped. A reply that is already a map
     * comes back as it is.
     *
     * @return array<string, mixed>
     */
    public static function pairs(mixed $reply): array
    {
        if (is_array($reply) && ! array_is_list($reply)) {
            return self::map($reply);
        }

        $flat = self::list($reply);
        $map = [];

        for ($i = 0, $count = count($flat); $i + 1 < $count; $i += 2) {
            $field = self::string($flat[$i]);

            if (! array_key_exists($field, $map)) {
                $map[$field] = $flat[$i + 1];
            }
        }

        return $map;
    }

    /**
     * Stream entries (`[[id, [field, value, ...]], ...]`, as from XRANGE,
     * XREVRANGE or XCLAIM). An entry whose fields are nil, as XREADGROUP reports
     * one deleted while pending, keeps its id with no fields.
     *
     * @return list<array{id: string, fields: array<string, mixed>}>
     */
    public static function entries(mixed $reply): array
    {
        $entries = [];

        foreach (self::list($reply) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry[0] ?? null;

            if (! is_scalar($id)) {
                continue;
            }

            $entries[] = ['id' => (string) $id, 'fields' => self::pairs($entry[1] ?? null)];
        }

        return $entries;
    }

    /**
     * An XREAD or XREADGROUP reply (`[[stream key, entries], ...]`, nil on a
     * timeout) as entries by stream key.
     *
     * @return array<string, list<array{id: string, fields: array<string, mixed>}>>
     */
    public static function streams(mixed $reply): array
    {
        $streams = [];

        foreach (self::list($reply) as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $key = $stream[0] ?? null;

            if (! is_scalar($key)) {
                continue;
            }

            $streams[(string) $key] = self::entries($stream[1] ?? null);
        }

        return $streams;
    }

    /**
     * The extended XPENDING reply (`[[id, consumer, idle ms, deliveries], ...]`).
     *
     * @return list<array{id: string, consumer: string, idle: int, deliveries: int}>
     */
    public static function pending(mixed $reply): array
    {
        $rows = [];

        foreach (self::list($reply) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row[0] ?? null;

            if (! is_scalar($id)) {
                continue;
            }

            $rows[] = [
                'id' => (string) $id,
                'consumer' => self::string($row[1] ?? null),
                'idle' => self::int($row[2] ?? null),
                'deliveries' => self::int($row[3] ?? null),
            ];
        }

        return $rows;
    }
}
