<?php

declare(strict_types=1);

namespace Webpatser\Torque\Queue;

use Fledge\Async\Redis\RedisClient;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Laravel queue driver backed by Redis Streams + AMPHP.
 *
 * Every Redis command goes through {@see RedisClient::execute()} because
 * amphp/redis v2 has no typed stream helpers.
 */
class StreamQueue extends Queue implements QueueContract
{
    private readonly RedisClient $redis;

    private readonly string $consumerId;

    public function __construct(
        private readonly string $redisUri,
        private readonly string $default = 'default',
        private readonly int $retryAfter = 90,
        private readonly int $blockFor = 2000,
        private readonly string $prefix = 'torque:',
        private readonly string $consumerGroup = 'torque',
        private readonly bool $cluster = false,
        private readonly string $serializer = 'json',
    ) {
        $this->redis = createRedisClient($this->redisUri);
        $this->consumerId = gethostname() . '-' . getmypid();
    }

    // -------------------------------------------------------------------------
    //  Serialization
    // -------------------------------------------------------------------------

    /**
     * Encode a job payload array using the configured serializer.
     *
     * 'json'     : JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR.
     * 'igbinary' : igbinary_serialize (requires ext-igbinary).
     */
    private function encodePayload(array $payload): string
    {
        if ($this->serializer === 'igbinary') {
            return igbinary_serialize($payload);
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Re-encode an already-serialized payload string into the configured wire format.
     *
     * Laravel's Queue::createPayload always emits JSON, so when the configured
     * serializer is igbinary we transcode here, just before XADD, to keep the
     * wire format consistent. No-op for json mode.
     */
    private function transcodeIncomingPayload(string $payload): string
    {
        if ($this->serializer !== 'igbinary') {
            return $payload;
        }

        if ($payload === '' || ($payload[0] ?? '') === "\x00") {
            // Already non-JSON (likely already igbinary from a re-push). Leave it.
            return $payload;
        }

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return igbinary_serialize($decoded);
    }

    /**
     * Decode a raw payload string by sniffing the format header.
     *
     * Static so that {@see \Webpatser\Torque\Worker\WorkerProcess} can call it
     * without holding a StreamQueue instance.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the format cannot be recognized.
     * @throws \JsonException When JSON decoding fails.
     */
    public static function decodePayload(string $raw): array
    {
        if ($raw === '') {
            throw new \InvalidArgumentException('Unrecognized payload format');
        }

        $first = $raw[0];

        if ($first === '{' || $first === '[') {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        if (str_starts_with($raw, "\x00\x00\x00\x02")) {
            $decoded = igbinary_unserialize($raw);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('igbinary payload did not decode to an array');
            }

            return $decoded;
        }

        throw new \InvalidArgumentException('Unrecognized payload format');
    }

    /**
     * Cheap pre-flight check: does this raw payload look like a payload we can decode?
     *
     * Used by {@see \Webpatser\Torque\Worker\WorkerProcess} as a replacement for
     * the old json_validate() gate, so igbinary blobs are not dropped as corrupt.
     */
    public static function isValidPayload(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }

        try {
            self::decodePayload($raw);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    //  Push
    // -------------------------------------------------------------------------

    /**
     * Push a new job onto the queue.
     *
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return string The stream message ID.
     */
    #[\NoDiscard]
    #[\Override]
    public function push($job, $data = '', $queue = null): string
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn (string $payload, ?string $queue) => $this->pushRaw($payload, $queue),
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload  JSON-encoded job payload.
     * @param  string|null  $queue
     * @param  array  $options
     * @return string The stream message ID.
     */
    #[\NoDiscard]
    #[\Override]
    public function pushRaw($payload, $queue = null, array $options = []): string
    {
        // Laravel's Queue::createPayload always emits JSON. When the configured
        // serializer is igbinary we transcode here so the on-the-wire format
        // matches the configured serializer.
        $payload = $this->transcodeIncomingPayload($payload);

        $messageId = $this->redis->execute(
            'XADD',
            $this->getStreamKey($queue),
            '*',
            'payload',
            $payload,
        );

        // Record "queued" event to per-job stream.
        try {
            $decoded = self::decodePayload($payload);

            if (isset($decoded['uuid'])) {
                app(\Webpatser\Torque\Stream\JobStreamRecorder::class)->onQueued(
                    $decoded['uuid'],
                    $this->getQueue($queue),
                    $decoded['displayName'] ?? $decoded['job'] ?? 'Unknown',
                );
            }
        } catch (\Throwable) {
            // Never break job dispatch for stream recording.
        }

        return (string) $messageId;
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  DateTimeInterface|DateInterval|int  $delay
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return string The payload UUID.
     */
    #[\NoDiscard]
    #[\Override]
    public function later($delay, $job, $data = '', $queue = null): string
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            fn (string $payload, ?string $queue, $delay) => $this->laterRaw($delay, $payload, $queue),
        );
    }

    /**
     * Push a raw payload onto the delayed sorted set.
     */
    private function laterRaw(DateTimeInterface|DateInterval|int $delay, string $payload, ?string $queue = null): string
    {
        // Same transcode rationale as pushRaw: createPayload emits JSON.
        $payload = $this->transcodeIncomingPayload($payload);

        $score = $this->availableAt($delay);

        $this->redis->execute(
            'ZADD',
            $this->getStreamKey($queue) . ':delayed',
            (string) $score,
            $payload,
        );

        try {
            $decoded = self::decodePayload($payload);
        } catch (\Throwable) {
            return '';
        }

        return $decoded['uuid'] ?? $decoded['id'] ?? '';
    }

    // -------------------------------------------------------------------------
    //  Pop
    // -------------------------------------------------------------------------

    /**
     * Pop the next job off the queue.
     *
     * Uses XREADGROUP with a blocking timeout. Returns null when no message is
     * available within the block window.
     *
     * @param  string|null  $queue
     * @return StreamJob|null
     */
    #[\Override]
    public function pop($queue = null): ?StreamJob
    {
        $streamKey = $this->getStreamKey($queue);

        $this->ensureConsumerGroup($streamKey, $this->consumerGroup);

        $response = $this->redis->execute(
            'XREADGROUP',
            'GROUP',
            $this->consumerGroup,
            $this->consumerId,
            'COUNT',
            '1',
            'BLOCK',
            (string) $this->blockFor,
            'STREAMS',
            $streamKey,
            '>',
        );

        // XREADGROUP returns null when the block timeout expires with no message.
        if ($response === null) {
            return null;
        }

        // Response shape: [ [streamKey, [ [messageId, [field, value, ...]] ] ] ]
        $streamData = $response[0] ?? null;
        if ($streamData === null) {
            return null;
        }

        $messages = $streamData[1] ?? [];
        if ($messages === []) {
            return null;
        }

        $message = $messages[0];
        $messageId = (string) $message[0];
        $fields = $message[1];

        // Fields come as a flat list: ['payload', '{json}', ...]
        $payload = null;
        for ($i = 0, $count = count($fields); $i < $count; $i += 2) {
            if ((string) $fields[$i] === 'payload') {
                $payload = (string) $fields[$i + 1];
                break;
            }
        }

        if ($payload === null) {
            // Corrupt message — acknowledge and skip.
            $this->deleteAndAcknowledge($this->getQueue($queue), $messageId);
            return null;
        }

        return new StreamJob(
            container: $this->container,
            streamQueue: $this,
            rawBody: $payload,
            messageId: $messageId,
            connectionName: $this->getConnectionName(),
            queue: $this->getQueue($queue),
        );
    }

    // -------------------------------------------------------------------------
    //  Size / inspection
    // -------------------------------------------------------------------------

    /**
     * Get the size of the queue (total messages in the stream).
     *
     * @param  string|null  $queue
     */
    #[\Override]
    public function size($queue = null): int
    {
        return (int) $this->redis->execute('XLEN', $this->getStreamKey($queue));
    }

    /**
     * Get the number of pending (claimed but unacknowledged) messages.
     */
    public function pendingSize($queue = null): int
    {
        $result = $this->redis->execute(
            'XPENDING',
            $this->getStreamKey($queue),
            $this->consumerGroup,
        );

        // XPENDING summary: [totalPending, smallestId, largestId, [[consumer, count], ...]]
        return (int) ($result[0] ?? 0);
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize($queue = null): int
    {
        return (int) $this->redis->execute(
            'ZCARD',
            $this->getStreamKey($queue) . ':delayed',
        );
    }

    /**
     * Get the number of reserved (pending) jobs.
     *
     * In Redis Streams, the Pending Entries List (PEL) is the reservation
     * mechanism — a message is "reserved" from the moment it is read by a
     * consumer until it is ACKed.
     */
    public function reservedSize($queue = null): int
    {
        return $this->pendingSize($queue);
    }

    /**
     * Get the creation timestamp (in seconds) of the oldest pending message.
     *
     * Returns null when the PEL is empty.
     */
    public function creationTimeOfOldestPendingJob($queue = null): ?float
    {
        $result = $this->redis->execute(
            'XPENDING',
            $this->getStreamKey($queue),
            $this->consumerGroup,
            '-',
            '+',
            '1',
        );

        // Result: [ [messageId, consumer, idleTime, deliveryCount] ]
        if ($result === null || $result === []) {
            return null;
        }

        $messageId = (string) $result[0][0];

        // Redis stream message IDs are formatted as {millisecondsTimestamp}-{sequence}.
        $timestampMs = (int) Str::before($messageId, '-');

        return $timestampMs / 1000;
    }

    // -------------------------------------------------------------------------
    //  Acknowledge / release
    // -------------------------------------------------------------------------

    /**
     * Acknowledge and delete a message from the stream.
     */
    public function deleteAndAcknowledge(string $queue, string $messageId): void
    {
        $streamKey = $this->prefix . $queue;

        $this->redis->execute('XACK', $streamKey, $this->consumerGroup, $messageId);
        $this->redis->execute('XDEL', $streamKey, $messageId);
    }

    /**
     * Release a job back onto the queue, optionally with a delay.
     *
     * The original message is ACKed and a new one is pushed with an
     * incremented attempt counter.
     */
    public function release(string $queue, StreamJob $job, int $delay = 0): void
    {
        $streamKey = $this->prefix . $queue;

        // Acknowledge the original message so it leaves the PEL.
        $this->redis->execute('XACK', $streamKey, $this->consumerGroup, $job->messageId);
        $this->redis->execute('XDEL', $streamKey, $job->messageId);

        // Rebuild payload with incremented attempts.
        $payload = self::decodePayload($job->getRawBody());
        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
        $newPayload = $this->encodePayload($payload);

        if ($delay > 0) {
            (void) $this->laterRaw($delay, $newPayload, $queue);
        } else {
            (void) $this->pushRaw($newPayload, $queue);
        }
    }

    // -------------------------------------------------------------------------
    //  Consumer group management
    // -------------------------------------------------------------------------

    /**
     * Ensure the consumer group exists on the given stream.
     *
     * Creates the stream with MKSTREAM if it does not exist. Catches the
     * BUSYGROUP error that Redis returns when the group already exists.
     */
    public function ensureConsumerGroup(string $stream, string $group): void
    {
        try {
            $this->redis->execute(
                'XGROUP',
                'CREATE',
                $stream,
                $group,
                '0',
                'MKSTREAM',
            );
        } catch (\Fledge\Async\Redis\RedisException $e) {
            // "BUSYGROUP Consumer Group name already exists" is expected.
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    // -------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the queue name, falling back to the default.
     */
    public function getQueue(?string $queue = null): string
    {
        return $queue ?? $this->default;
    }

    /**
     * Build the full Redis stream key for a given queue.
     *
     * When cluster mode is enabled, wraps the queue name in a Redis hash tag
     * so all related keys (stream, :delayed, :notify) land on the same slot.
     */
    public function getStreamKey(?string $queue = null): string
    {
        $queue = $this->getQueue($queue);

        if ($this->cluster && !str_contains($queue, '{')) {
            $queue = '{' . $queue . '}';
        }

        return $this->prefix . $queue;
    }

    /**
     * Get the underlying AMPHP Redis client.
     */
    public function getRedisClient(): RedisClient
    {
        return $this->redis;
    }

    /**
     * Get the consumer group name.
     */
    public function getConsumerGroup(): string
    {
        return $this->consumerGroup;
    }

    /**
     * Get the unique consumer ID for this worker process.
     */
    public function getConsumerId(): string
    {
        return $this->consumerId;
    }

    /**
     * Get the retry-after threshold in seconds.
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
