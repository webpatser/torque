<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Fledge\Async\Redis\RedisClient;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Handles jobs that have exhausted all retries.
 *
 * Failed jobs are moved to a dedicated dead-letter Redis Stream where they can
 * be inspected, retried, or purged. Each entry stores the original payload,
 * source queue, exception details, and failure timestamp.
 */
final class DeadLetterHandler
{
    private readonly RedisClient $redis;

    private readonly string $deadLetterStream;

    /**
     * @param  array<int, string>  $allowedQueues  Whitelist of queue names permitted as retry targets.
     * @param  int  $maxEntries  Hard cap on stream length, enforced at write time. 0 = uncapped.
     */
    public function __construct(
        private readonly string $redisUri,
        private readonly int $ttl = 604800, // 7 days
        private readonly string $prefix = 'torque:',
        private readonly array $allowedQueues = [],
        private readonly int $maxEntries = 100000,
    ) {
        $this->deadLetterStream = $this->prefix.'dead-letter';
        $this->redis = createRedisClient($this->redisUri);
    }

    /**
     * Move a permanently failed job to the dead-letter stream.
     *
     * @param  string  $queue  The original queue the job was consumed from.
     * @param  string  $payload  The raw JSON payload of the failed job.
     * @param  string  $messageId  The original stream message ID.
     * @param  \Throwable  $exception  The exception that caused the final failure.
     */
    public function handle(string $queue, string $payload, string $messageId, \Throwable $exception): void
    {
        // Cap the stream at write time. Approximate trimming (MAXLEN ~) keeps
        // XADD O(1) by only evicting whole macro nodes, so the real length
        // hovers just above the cap instead of being exact. Without this a
        // failure storm grows the stream without bound (scrpr 2026-08-27:
        // 3.9M entries, 22 GB, Redis OOM).
        $args = [$this->deadLetterStream];

        if ($this->maxEntries > 0) {
            array_push($args, 'MAXLEN', '~', (string) $this->maxEntries);
        }

        array_push(
            $args,
            '*',
            'payload', $payload,
            'original_queue', $queue,
            'exception_class', $exception::class,
            'exception_message', substr($exception->getMessage(), 0, 1000),
            'exception_trace', substr($exception->getTraceAsString(), 0, 5000),
            'failed_at', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        );

        $this->redis->execute('XADD', ...$args);
    }

    /**
     * Retry a dead-lettered job by moving it back to its original (or a specified) queue.
     *
     * Reads the entry from the dead-letter stream, pushes the payload into the
     * target stream via XADD, then removes it from the dead-letter stream.
     *
     * @param  string  $deadLetterId  The message ID in the dead-letter stream.
     * @param  string|null  $targetQueue  Override queue; uses the original queue if null.
     *
     * @throws \RuntimeException When the dead-letter entry is not found.
     */
    public function retry(string $deadLetterId, ?string $targetQueue = null): void
    {
        $entries = $this->redis->execute('XRANGE', $this->deadLetterStream, $deadLetterId, $deadLetterId);

        if (! is_array($entries) || $entries === []) {
            throw new \RuntimeException("Dead-letter entry [{$deadLetterId}] not found.");
        }

        $fields = $this->parseFields($entries[0][1]);

        $queue = $targetQueue ?? $fields['original_queue']
            ?? throw new \RuntimeException("Cannot determine target queue for [{$deadLetterId}].");

        // Structural check: keep Redis key space sane even when no explicit
        // whitelist is configured (e.g. when the handler is instantiated
        // outside the service provider).
        if (preg_match('/^[a-zA-Z0-9_\-.:]+$/', $queue) !== 1) {
            throw new \RuntimeException("Invalid queue name: [{$queue}].");
        }

        // Whitelist check: when provided, the target must match a configured
        // Torque stream so retries cannot push jobs into arbitrary streams
        // (including the dead-letter stream itself).
        if ($this->allowedQueues !== [] && ! in_array($queue, $this->allowedQueues, true)) {
            throw new \RuntimeException("Queue [{$queue}] is not a configured Torque stream.");
        }

        $streamKey = $this->prefix.$queue;

        $this->redis->execute(
            'XADD',
            $streamKey,
            '*',
            'payload', $fields['payload'] ?? '',
        );

        $this->redis->execute('XDEL', $this->deadLetterStream, $deadLetterId);
    }

    /**
     * Permanently remove a dead-lettered entry.
     *
     * @param  string  $deadLetterId  The message ID in the dead-letter stream.
     */
    public function purge(string $deadLetterId): void
    {
        $this->redis->execute('XDEL', $this->deadLetterStream, $deadLetterId);
    }

    /**
     * Count the total number of entries in the dead-letter stream.
     */
    #[\NoDiscard]
    public function count(): int
    {
        $result = $this->redis->execute('XLEN', $this->deadLetterStream);

        return is_int($result) ? $result : 0;
    }

    /**
     * List entries in the dead-letter stream, newest first.
     *
     * @param  int  $count  Maximum number of entries to return.
     * @param  int|null  $sinceMs  Millisecond epoch; drop entries older than it.
     * @return array<int, array{id: string, payload: string, original_queue: string, exception_class: string, exception_message: string, exception_trace: string, failed_at: string}>
     */
    /**
     * Number of entries no older than a millisecond epoch.
     *
     * `XLEN` cannot take a range, so this is an `XRANGE` with the ids only.
     * Callers that want the whole stream should keep using {@see count()},
     * which stays O(1).
     */
    #[\NoDiscard]
    public function countSince(int $sinceMs): int
    {
        $entries = $this->redis->execute(
            'XRANGE',
            $this->deadLetterStream,
            self::lowId($sinceMs),
            '+',
        );

        return is_array($entries) ? count($entries) : 0;
    }

    /**
     * The low bound of an `XRANGE`: a millisecond epoch as a stream id, or the
     * beginning of the stream when no window was asked for.
     */
    private static function lowId(?int $sinceMs): string
    {
        return $sinceMs === null ? '-' : max(0, $sinceMs).'-0';
    }

    #[\NoDiscard]
    public function list(int $count = 50, ?int $sinceMs = null): array
    {
        // Stream ids are `{millisecond epoch}-{seq}`, so a time window is just
        // a low id: no scanning, no post-filtering.
        $entries = $this->redis->execute(
            'XREVRANGE',
            $this->deadLetterStream,
            '+',
            self::lowId($sinceMs),
            'COUNT',
            (string) $count,
        );

        if (! is_array($entries) || $entries === []) {
            return [];
        }

        $result = [];

        foreach ($entries as $entry) {
            $id = (string) $entry[0];
            $fields = $this->parseFields($entry[1]);

            $result[] = [
                'id' => $id,
                'payload' => $fields['payload'] ?? '',
                'original_queue' => $fields['original_queue'] ?? '',
                'exception_class' => $fields['exception_class'] ?? '',
                'exception_message' => $fields['exception_message'] ?? '',
                'exception_trace' => $fields['exception_trace'] ?? '',
                'failed_at' => $fields['failed_at'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * List entries before a given ID (cursor-based pagination), newest first.
     *
     * @param  string  $beforeId  The exclusive upper bound message ID.
     * @param  int  $count  Maximum number of entries to return.
     * @param  int|null  $sinceMs  Millisecond epoch; drop entries older than it.
     * @return array<int, array{id: string, payload: string, original_queue: string, exception_class: string, exception_message: string, exception_trace: string, failed_at: string}>
     */
    #[\NoDiscard]
    public function listBefore(string $beforeId, int $count = 50, ?int $sinceMs = null): array
    {
        // Fetch one extra so we can exclude the cursor entry itself,
        // then take only $count entries that are strictly older.
        $entries = $this->redis->execute(
            'XREVRANGE',
            $this->deadLetterStream,
            $beforeId,
            self::lowId($sinceMs),
            'COUNT',
            (string) ($count + 1),
        );

        if (! is_array($entries) || $entries === []) {
            return [];
        }

        $result = [];

        foreach ($entries as $entry) {
            $id = (string) $entry[0];

            // Exclude the cursor entry itself (strict "before").
            if ($id === $beforeId) {
                continue;
            }

            $fields = $this->parseFields($entry[1]);

            $result[] = [
                'id' => $id,
                'payload' => $fields['payload'] ?? '',
                'original_queue' => $fields['original_queue'] ?? '',
                'exception_class' => $fields['exception_class'] ?? '',
                'exception_message' => $fields['exception_message'] ?? '',
                'exception_trace' => $fields['exception_trace'] ?? '',
                'failed_at' => $fields['failed_at'] ?? '',
            ];

            if (count($result) >= $count) {
                break;
            }
        }

        return $result;
    }

    /**
     * Apply the full dead-letter retention policy: TTL first, then the cap.
     *
     * Computes a minimum message ID based on `now - ttl` (in milliseconds) and
     * uses XTRIM MINID to evict all older entries, then enforces
     * `max_entries` with an approximate XTRIM MAXLEN so a burst that arrived
     * inside the TTL window still cannot outgrow the cap.
     */
    public function trim(): void
    {
        $cutoffMs = (int) ((time() - $this->ttl) * 1000);

        // XTRIM with MINID removes all entries with IDs lower than the given value.
        // The ID format is {milliseconds}-{sequence}; using just the ms timestamp
        // trims everything older than the cutoff.
        $this->redis->execute('XTRIM', $this->deadLetterStream, 'MINID', (string) $cutoffMs);

        if ($this->maxEntries > 0) {
            $this->redis->execute('XTRIM', $this->deadLetterStream, 'MAXLEN', '~', (string) $this->maxEntries);
        }
    }

    /**
     * The Redis key of the dead-letter stream.
     */
    #[\NoDiscard]
    public function streamKey(): string
    {
        return $this->deadLetterStream;
    }

    /**
     * Parse a flat field list from XRANGE into an associative array.
     *
     * Redis returns fields as `[key, value, key, value, ...]`.
     *
     * @param  array<int, string>  $fields
     * @return array<string, string>
     */
    private function parseFields(array $fields): array
    {
        $parsed = [];

        for ($i = 0, $count = count($fields); $i < $count; $i += 2) {
            $parsed[(string) $fields[$i]] = (string) $fields[$i + 1];
        }

        return $parsed;
    }
}
