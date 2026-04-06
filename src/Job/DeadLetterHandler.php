<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Amp\Redis\RedisClient;

use function Amp\Redis\createRedisClient;

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

    public function __construct(
        private readonly string $redisUri,
        private readonly string $deadLetterStream = 'torque:stream:dead-letter',
        private readonly int $ttl = 604800, // 7 days
    ) {
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
        $this->redis->execute(
            'XADD',
            $this->deadLetterStream,
            '*',
            'payload', $payload,
            'original_queue', $queue,
            'exception_class', $exception::class,
            'exception_message', substr($exception->getMessage(), 0, 1000),
            'failed_at', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        );
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

        if (!is_array($entries) || $entries === []) {
            throw new \RuntimeException("Dead-letter entry [{$deadLetterId}] not found.");
        }

        $fields = $this->parseFields($entries[0][1]);

        $queue = $targetQueue ?? $fields['original_queue']
            ?? throw new \RuntimeException("Cannot determine target queue for [{$deadLetterId}].");

        if (!preg_match('/^[a-zA-Z0-9_\-.:]+$/', $queue)) {
            throw new \RuntimeException("Invalid queue name: [{$queue}].");
        }

        $this->redis->execute(
            'XADD',
            $queue,
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
     * List entries in the dead-letter stream.
     *
     * @param  int  $count  Maximum number of entries to return.
     * @param  string|null  $startId  Start reading from this ID (inclusive). Defaults to the beginning.
     * @return array<int, array{id: string, payload: string, original_queue: string, exception_class: string, exception_message: string, failed_at: string}>
     */
    #[\NoDiscard]
    public function list(int $count = 50, ?string $startId = null): array
    {
        $entries = $this->redis->execute(
            'XRANGE',
            $this->deadLetterStream,
            $startId ?? '-',
            '+',
            'COUNT',
            (string) $count,
        );

        if (!is_array($entries) || $entries === []) {
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
                'failed_at' => $fields['failed_at'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Trim entries older than the configured TTL from the dead-letter stream.
     *
     * Computes a minimum message ID based on `now - ttl` (in milliseconds) and
     * uses XTRIM MINID to evict all older entries.
     */
    public function trim(): void
    {
        $cutoffMs = (int) ((time() - $this->ttl) * 1000);

        // XTRIM with MINID removes all entries with IDs lower than the given value.
        // The ID format is {milliseconds}-{sequence}; using just the ms timestamp
        // trims everything older than the cutoff.
        $this->redis->execute('XTRIM', $this->deadLetterStream, 'MINID', (string) $cutoffMs);
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
