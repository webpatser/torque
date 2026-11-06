<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Fledge\Async\Redis\RedisClient;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Worker\WorkerProcess;

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
     * @param  bool  $cluster  Hash tag retry targets, matching StreamQueue::getStreamKey().
     */
    public function __construct(
        private readonly string $redisUri,
        private readonly int $ttl = 604800, // 7 days
        private readonly string $prefix = 'torque:',
        private readonly array $allowedQueues = [],
        private readonly int $maxEntries = 100000,
        private readonly bool $cluster = false,
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
     * @param  string|null  $reason  Short machine-readable reason, e.g. `redelivery_limit`, stored as in {@see quarantine()}.
     */
    public function handle(string $queue, string $payload, string $messageId, \Throwable $exception, ?string $reason = null): void
    {
        $this->write($queue, $payload, [
            'exception_class', $exception::class,
            'exception_message', substr($exception->getMessage(), 0, 1000),
            'exception_trace', substr($exception->getTraceAsString(), 0, 5000),
            ...($reason === null ? [] : ['reason', substr($reason, 0, 200), 'original_message_id', $messageId]),
        ]);
    }

    /**
     * Park an entry that could not be processed (not a job failure) in the
     * dead-letter stream, byte for byte and never decoded.
     *
     * Used for payloads the worker refuses to unserialize, such as leftover
     * igbinary entries on a JSON-configured install (`serializer_mismatch`).
     * The reason is stored as `reason` and as the exception message, so the
     * dashboard and `retry()` treat it like any other dead-letter entry.
     *
     * @param  string  $queue  The queue the entry was consumed from.
     * @param  string  $payload  The raw, undecoded payload.
     * @param  string  $messageId  The original stream message ID.
     * @param  string  $reason  Short machine-readable reason, e.g. `serializer_mismatch`.
     */
    public function quarantine(string $queue, string $payload, string $messageId, string $reason): void
    {
        $this->write($queue, $payload, [
            'exception_class', 'quarantined',
            'exception_message', substr($reason, 0, 1000),
            'exception_trace', '',
            'reason', substr($reason, 0, 200),
            'original_message_id', $messageId,
        ]);
    }

    /**
     * @param  list<string>  $fields  Flat field/value pairs appended to the entry.
     */
    private function write(string $queue, string $payload, array $fields): void
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

        $args = array_merge(
            $args,
            ['*', 'payload', $payload, 'original_queue', $queue],
            $fields,
            ['failed_at', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c')],
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

        // Entries written before 1.1.2 hold the tagged name in cluster mode.
        $queue = WorkerProcess::bareQueueName($queue, $this->cluster);

        // Structural check: keep Redis key space sane even when no explicit
        // whitelist is configured (e.g. when the handler is instantiated
        // outside the service provider).
        if (preg_match('/^[a-zA-Z0-9_\-.:]+$/', $queue) !== 1) {
            throw new \RuntimeException("Invalid queue name: [{$queue}].");
        }

        // Whitelist check: when provided, the target must match a configured
        // Torque stream so retries cannot push jobs into arbitrary streams
        // (including the dead-letter stream itself).
        if ($this->allowedQueues !== [] && ! in_array($queue, array_map(fn (string $allowed): string => WorkerProcess::bareQueueName($allowed, $this->cluster), $this->allowedQueues), true)) {
            throw new \RuntimeException("Queue [{$queue}] is not a configured Torque stream.");
        }

        // The structural check above leaves no braces in the name, so this is
        // StreamQueue::getStreamKey() for it.
        $streamKey = $this->prefix.($this->cluster ? '{'.$queue.'}' : $queue);

        // Built before anything is written, so a job class that no longer
        // exists throws here and the dead-letter entry stays where it is.
        [$payload, $uuid] = $this->freshPayload($fields['payload'] ?? '');

        $this->redis->execute(
            'XADD',
            $streamKey,
            '*',
            'payload', $payload,
        );

        $this->redis->execute('XDEL', $this->deadLetterStream, $deadLetterId);

        if ($uuid !== null) {
            $this->forgetExceptionCount($uuid);

            // As stock queue:retry does: the job keeps its uuid, a unique column
            // in failed_jobs, so a stale row would reject the next failure's
            // insert and keep the old exception.
            try {
                $this->forgetFailedJob($uuid);
            } catch (\Throwable) {
                // A database outage must not undo a retry that already happened.
            }
        }
    }

    /**
     * Remove the `failed_jobs` row of a job, by uuid.
     *
     * Only the uuid-keyed database provider can be matched without guessing:
     * the plain database provider keys on an auto-increment id, and the
     * dead-letter id (a stream id) is unrelated to either. Any other failer
     * is left alone.
     *
     * @return bool Whether a row was removed.
     */
    public function forgetFailedJob(string $uuid): bool
    {
        if ($uuid === '') {
            return false;
        }

        try {
            /** @var mixed $failer Any failer may be bound; only the uuid provider is handled. */
            $failer = Container::getInstance()->make('queue.failer');
        } catch (\Throwable) {
            return false;
        }

        return $failer instanceof DatabaseUuidFailedJobProvider && $failer->forget($uuid);
    }

    /**
     * The payload as a new job: attempts back to 0 and `retryUntil` refreshed,
     * as stock `queue:retry` does, and the release counters gone, in the format
     * the entry came in.
     *
     * An entry that cannot be decoded (a serializer mismatch, a corrupt body)
     * is returned byte for byte, as before.
     *
     * @return array{0: string, 1: string|null} The payload and the job's uuid.
     *
     * @throws \RuntimeException When the job class can no longer be loaded.
     */
    private function freshPayload(string $raw): array
    {
        try {
            $payload = StreamQueue::decodePayload($raw);
        } catch (\Throwable) {
            return [$raw, null];
        }

        $payload['attempts'] = 0;
        unset($payload['torque_releases'], $payload['torque_release_deadline']);

        if (isset($payload['data']['command']) && is_string($payload['data']['command'])) {
            try {
                $instance = $this->commandInstance($payload['data']['command']);
            } catch (ModelNotFoundException) {
                $instance = null;
            }

            if ($instance instanceof \__PHP_Incomplete_Class) {
                throw new \RuntimeException('Cannot retry: the job class no longer exists.');
            }

            if (is_object($instance) && method_exists($instance, 'retryUntil')) {
                $retryUntil = $instance->retryUntil();

                $payload['retryUntil'] = $retryUntil instanceof \DateTimeInterface
                    ? $retryUntil->getTimestamp()
                    : $retryUntil;
            }
        }

        $encoded = $raw[0] === '{' || $raw[0] === '['
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : (igbinary_serialize($payload) ?? throw new \RuntimeException('igbinary_serialize() failed to encode the job payload.'));

        return [$encoded, is_string($payload['uuid'] ?? null) ? $payload['uuid'] : null];
    }

    /**
     * Unserialize the job command, decrypting it first when it is encrypted.
     */
    private function commandInstance(string $command): mixed
    {
        if (! str_starts_with($command, 'O:')) {
            $command = Container::getInstance()->make(Encrypter::class)->decrypt($command);
        }

        return unserialize($command);
    }

    /**
     * A retried job starts with a clean `maxExceptions` count; a used-up one
     * would fail it on its first exception. Same key as the worker's counter.
     */
    private function forgetExceptionCount(string $uuid): void
    {
        try {
            Container::getInstance()->make(CacheRepository::class)->forget('job-exceptions:'.$uuid);
        } catch (\Throwable) {
            // A cache outage must not undo a retry that already happened.
        }
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
     * Remove one dead-lettered entry and report whether it existed.
     *
     * Touches only the dead-letter stream, never a live queue stream.
     *
     * @return array{id: string, uuid: string|null}|null The removed entry's id and job uuid, or null when the id is unknown.
     */
    public function forget(string $deadLetterId): ?array
    {
        $entries = $this->redis->execute('XRANGE', $this->deadLetterStream, $deadLetterId, $deadLetterId);

        if (! is_array($entries) || $entries === []) {
            return null;
        }

        $fields = $this->parseFields($entries[0][1]);

        $this->redis->execute('XDEL', $this->deadLetterStream, $deadLetterId);

        return ['id' => (string) $entries[0][0], 'uuid' => self::payloadUuid($fields['payload'] ?? '')];
    }

    /**
     * The job uuid of a stored payload, or null when it cannot be decoded.
     */
    private static function payloadUuid(string $payload): ?string
    {
        try {
            $uuid = StreamQueue::decodePayload($payload)['uuid'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * Remove every dead-lettered entry, or only those from one queue.
     *
     * Walks the stream in pages so a large stream is never held in memory:
     * payloads are decoded for their uuid and dropped, only ids and uuids are
     * kept. The queue is compared by bare name, so `{q}` and `q` match each
     * other in cluster mode, as for {@see retry()}.
     *
     * @param  (\Closure(list<string>): void)|null  $beforeDelete  Called with each page's uuids before the page is deleted, so a crash mid-walk
     *                                                             never leaves a `failed_jobs` row behind for an entry that is already gone.
     * @return list<array{id: string, uuid: string|null}> The removed entries.
     */
    public function forgetAll(?string $queue = null, ?\Closure $beforeDelete = null): array
    {
        $wanted = $queue === null ? null : WorkerProcess::bareQueueName($queue, $this->cluster);
        $removed = [];
        $from = '-';

        while (true) {
            $page = $this->redis->execute('XRANGE', $this->deadLetterStream, $from, '+', 'COUNT', '500');

            if (! is_array($page) || $page === []) {
                break;
            }

            $ids = [];
            $uuids = [];

            foreach ($page as $entry) {
                $fields = $this->parseFields($entry[1]);

                if ($wanted !== null && WorkerProcess::bareQueueName($fields['original_queue'] ?? '', $this->cluster) !== $wanted) {
                    continue;
                }

                $uuid = self::payloadUuid($fields['payload'] ?? '');

                $ids[] = (string) $entry[0];
                $removed[] = ['id' => (string) $entry[0], 'uuid' => $uuid];

                if ($uuid !== null) {
                    $uuids[] = $uuid;
                }
            }

            if ($ids !== []) {
                if ($beforeDelete !== null && $uuids !== []) {
                    $beforeDelete($uuids);
                }

                $this->redis->execute('XDEL', $this->deadLetterStream, ...$ids);
            }

            if (count($page) < 500) {
                break;
            }

            $from = '('.$page[array_key_last($page)][0];
        }

        return $removed;
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

    /**
     * List entries in the dead-letter stream, newest first.
     *
     * @param  int  $count  Maximum number of entries to return.
     * @param  int|null  $sinceMs  Millisecond epoch; drop entries older than it.
     * @return list<array{id: string, payload: string, original_queue: string, exception_class: string, exception_message: string, exception_trace: string, failed_at: string}>
     */
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
     * @return list<array{id: string, payload: string, original_queue: string, exception_class: string, exception_message: string, exception_trace: string, failed_at: string}>
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
