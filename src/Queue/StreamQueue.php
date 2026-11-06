<?php

declare(strict_types=1);

namespace Webpatser\Torque\Queue;

use DateInterval;
use DateTimeInterface;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Queue\Queue;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Webpatser\Torque\Redis\StreamHousekeeper;
use Webpatser\Torque\Stream\JobStreamRecorder;
use Webpatser\Torque\Stream\JobTagExtractor;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\Redis\createRedisClient;
use function Illuminate\Support\enum_value;

/**
 * Laravel queue driver backed by Redis Streams + AMPHP.
 *
 * Every Redis command goes through {@see RedisClient::execute()} because
 * amphp/redis v2 has no typed stream helpers.
 */
class StreamQueue extends Queue implements ClearableQueue, QueueContract
{
    private readonly RedisClient $redis;

    private readonly string $consumerId;

    /**
     * Key namespaces under the prefix that are never queues.
     *
     * @var list<string>
     */
    private const array RESERVED_NAMESPACES = ['paused', 'version', 'worker', 'metrics', 'job', 'jobs', 'dead-letter', 'cb'];

    /**
     * Lua script for an atomic release.
     *
     * Writes the new copy first, to the delayed set or the stream, and only then
     * drops the original. A failing redis.call() aborts the script, so the
     * original then stays in the stream and the PEL for reclaim. The write must
     * be the first command: under Redis OOM with noeviction XACK and XDEL are
     * still allowed while XADD and ZADD are refused.
     *
     * The copy gets a new id, so its redelivery count starts at 0. Dropping the
     * old id's count costs no round trip, so it happens whatever strict_attempts says.
     *
     * KEYS[1] = stream key
     * KEYS[2] = delayed sorted set key
     * KEYS[3] = redelivery counter hash key
     * ARGV[1] = consumer group
     * ARGV[2] = id of the entry being released
     * ARGV[3] = new payload
     * ARGV[4] = delayed score, empty to add to the stream at once
     */
    private const LUA_RELEASE = <<<'LUA'
if ARGV[4] ~= '' then
    redis.call('ZADD', KEYS[2], ARGV[4], ARGV[3])
else
    redis.call('XADD', KEYS[1], '*', 'payload', ARGV[3])
end
redis.call('XACK', KEYS[1], ARGV[1], ARGV[2])
redis.call('XDEL', KEYS[1], ARGV[2])
redis.call('HDEL', KEYS[3], ARGV[2])
return 1
LUA;

    /**
     * Lua script counting one redelivery, so the hash cannot exist without a TTL.
     *
     * KEYS[1] = redelivery counter hash key
     * ARGV[1] = entry id
     * ARGV[2] = TTL in seconds
     */
    private const LUA_COUNT_REDELIVERY = <<<'LUA'
local count = redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
redis.call('EXPIRE', KEYS[1], ARGV[2])
return count
LUA;

    /**
     * Lua script handing back entries a stopping worker read and never started.
     *
     * Each becomes a new entry with the same fields, so attempts stay as they are
     * and the next read is a first delivery. A count from an earlier delivery moves
     * with it. An entry that left this consumer's PEL was stolen and is being run
     * elsewhere, so it is left alone. The write comes first, as in LUA_RELEASE.
     *
     * KEYS[1] = stream key
     * KEYS[2] = redelivery counter hash key
     * ARGV[1] = consumer group
     * ARGV[2] = consumer name
     * ARGV[3...] = entry ids
     */
    private const LUA_RETURN_UNSTARTED = <<<'LUA'
local returned = 0
for i = 3, #ARGV do
    local id = ARGV[i]
    if #redis.call('XPENDING', KEYS[1], ARGV[1], id, id, 1, ARGV[2]) > 0 then
        local entry = redis.call('XRANGE', KEYS[1], id, id)[1]
        if entry then
            local new = redis.call('XADD', KEYS[1], '*', unpack(entry[2]))
            local count = redis.call('HGET', KEYS[2], id)
            if count then
                redis.call('HSET', KEYS[2], new, count)
                redis.call('HDEL', KEYS[2], id)
            end
            returned = returned + 1
        end
        redis.call('XACK', KEYS[1], ARGV[1], id)
        redis.call('XDEL', KEYS[1], id)
    end
end
return returned
LUA;

    /**
     * Lua script deleting a consumer only when it has no pending entries, as one
     * step: DELCONSUMER discards the consumer's PEL, so a delivery landing between
     * a separate check and the delete would be lost for good. Returns 1 if deleted.
     * A delivery after this recreates the consumer, and the entry stays claimable.
     *
     * KEYS[1] = stream key
     * ARGV[1] = consumer group
     * ARGV[2] = consumer name
     */
    public const string LUA_DELETE_IDLE_CONSUMER = <<<'LUA'
if #redis.call('XPENDING', KEYS[1], ARGV[1], '-', '+', 1, ARGV[2]) > 0 then
    return 0
end
redis.call('XGROUP', 'DELCONSUMER', KEYS[1], ARGV[1], ARGV[2])
return 1
LUA;

    /** Entries one LUA_RETURN_UNSTARTED call moves, so a long list cannot stall Redis. */
    private const int RETURN_UNSTARTED_BATCH = 500;

    /** Appended to a stream key: the hash of redelivery counts, by entry id. */
    public const string REDELIVERIES_SUFFIX = ':redeliveries';

    /**
     * Seconds the hash outlives the last redelivery counted on the queue. Far above
     * any retry_after, so it cannot run out between the deliveries of a crash loop.
     * Whatever a crash or a worker without strict_attempts left behind goes with it.
     */
    private const int REDELIVERIES_TTL = 604800;

    /**
     * Entries this process counted a redelivery for and has not let go of yet,
     * keyed `{stream key}|{id}`. Only these can have a field: an entry delivered
     * for the first time never has one, so acknowledging it needs no HDEL, and
     * without strict_attempts this stays empty.
     *
     * @var array<string, true>
     */
    private array $countedRedeliveries = [];

    /**
     * Lua script reading the depths of one queue in a single round trip.
     *
     * KEYS[1] = stream key, KEYS[2] = delayed sorted set key
     * ARGV[1] = consumer group, ARGV[2] = '1' to also return the oldest waiting id
     *
     * Returns {stream length, group pending count, delayed count, oldest waiting id}.
     * A missing stream or group yields zeros, never an error.
     */
    private const LUA_DEPTHS = <<<'LUA'
local pending = 0
local last = '0-0'
local groups = redis.pcall('XINFO', 'GROUPS', KEYS[1])
if type(groups) == 'table' and groups.err == nil then
    for _, g in ipairs(groups) do
        local f = {}
        for i = 1, #g, 2 do
            f[g[i]] = g[i + 1]
        end
        if f['name'] == ARGV[1] then
            pending = tonumber(f['pending']) or 0
            last = f['last-delivered-id'] or '0-0'
        end
    end
end
local oldest = ''
if ARGV[2] == '1' then
    local first = redis.call('XRANGE', KEYS[1], '(' .. last, '+', 'COUNT', 1)
    if first[1] then
        oldest = first[1][1]
    end
end
return {redis.call('XLEN', KEYS[1]), pending, redis.call('ZCARD', KEYS[2]), oldest}
LUA;

    /** Seconds a job may keep releasing itself when `torque.release_limit_seconds` is not set. */
    private const int DEFAULT_RELEASE_LIMIT_SECONDS = 86400;

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
        $this->consumerId = gethostname().'-'.getmypid();
    }

    // -------------------------------------------------------------------------
    //  Serialization
    // -------------------------------------------------------------------------

    /**
     * Encode a job payload array using the configured serializer.
     *
     * 'json'     : JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR.
     * 'igbinary' : igbinary_serialize (requires ext-igbinary).
     *
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        if ($this->serializer === 'igbinary') {
            return igbinary_serialize($payload)
                ?? throw new \RuntimeException('igbinary_serialize() failed to encode the job payload.');
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

        return igbinary_serialize($decoded)
            ?? throw new \RuntimeException('igbinary_serialize() failed to transcode the job payload.');
    }

    /**
     * Decode a raw payload string by sniffing the format header.
     *
     * Static so that {@see WorkerProcess} can call it
     * without holding a StreamQueue instance.
     *
     * igbinary is only decoded when the configured serializer is `igbinary`:
     * a stream entry that merely carries the igbinary header must not reach
     * `igbinary_unserialize()` on a JSON-only install. `$serializer` defaults
     * to `torque.serializer`.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the format cannot be recognized.
     * @throws \JsonException When JSON decoding fails.
     */
    public static function decodePayload(string $raw, ?string $serializer = null): array
    {
        if ($raw === '') {
            throw new \InvalidArgumentException('Unrecognized payload format');
        }

        $first = $raw[0];

        if ($first === '{' || $first === '[') {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        if (self::hasIgbinaryArrayHeader($raw) && self::igbinaryEnabled($serializer)) {
            $decoded = igbinary_unserialize($raw);

            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('igbinary payload did not decode to an array');
            }

            return $decoded;
        }

        throw new \InvalidArgumentException('Unrecognized payload format');
    }

    /**
     * Cheap pre-flight check: does this raw payload look like a payload we can decode?
     *
     * Used by {@see WorkerProcess} as a replacement for
     * the old json_validate() gate, so igbinary blobs are not dropped as corrupt.
     *
     * JSON is validated without building the structure; igbinary is accepted
     * by header alone (never unserialized here) and only when the configured
     * serializer is `igbinary`.
     */
    public static function isValidPayload(string $raw, ?string $serializer = null): bool
    {
        if ($raw === '') {
            return false;
        }

        if ($raw[0] === '{' || $raw[0] === '[') {
            return json_validate($raw, 512);
        }

        return self::hasIgbinaryArrayHeader($raw) && self::igbinaryEnabled($serializer);
    }

    /**
     * Is this an igbinary blob that the configured serializer refuses to decode?
     *
     * True for leftover entries from a previous `igbinary` run on a JSON
     * install. Header sniff only, the body is never unserialized.
     */
    public static function isSerializerMismatch(string $raw, ?string $serializer = null): bool
    {
        return self::hasIgbinaryArrayHeader($raw) && ! self::igbinaryEnabled($serializer);
    }

    /**
     * Does the blob start with the igbinary v2 header followed by an array type tag?
     *
     * Job payloads are always arrays (igbinary_type_array8/16/32 = 0x14..0x16).
     */
    private static function hasIgbinaryArrayHeader(string $raw): bool
    {
        if (! str_starts_with($raw, "\x00\x00\x00\x02") || strlen($raw) < 6) {
            return false;
        }

        $type = ord($raw[4]);

        return $type >= 0x14 && $type <= 0x16;
    }

    /**
     * Is igbinary decoding allowed for this call?
     */
    private static function igbinaryEnabled(?string $serializer): bool
    {
        $serializer ??= self::configuredSerializer();

        return $serializer === 'igbinary' && function_exists('igbinary_unserialize');
    }

    /**
     * The wire serializer from `torque.serializer`, `json` when unavailable.
     */
    private static function configuredSerializer(): string
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return 'json';
        }

        return (string) $container->make('config')->get('torque.serializer', 'json');
    }

    // -------------------------------------------------------------------------
    //  Push
    // -------------------------------------------------------------------------

    /**
     * Add the job's tags to its payload, the one place the real object is at hand.
     *
     * An encrypted job gets none: plaintext model ids would defeat the encryption.
     * After the parent, so a tags() that changes the job cannot reach the serialized
     * command. A job with no tags gets no field.
     *
     * @param  object  $job
     * @param  string  $queue
     * @return array<string, mixed>
     */
    #[\Override]
    protected function createObjectPayload($job, $queue)
    {
        $payload = parent::createObjectPayload($job, $queue);

        if ($this->jobShouldBeEncrypted($job)) {
            return $payload;
        }

        $tags = JobTagExtractor::fromJob($job);

        if ($tags !== []) {
            $payload[JobTagExtractor::PAYLOAD_KEY] = $tags;
        }

        return $payload;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  \UnitEnum|string|null  $queue
     * @return string|null The stream message ID, or null when the job dispatches
     *                     after commit: the framework then hands it to the
     *                     transactions manager, which enqueues it once the open
     *                     transaction commits (dropped on rollback), or at once
     *                     when none is open, and discards the id either way.
     */
    #[\Override]
    public function push($job, $data = '', $queue = null): ?string
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            // Laravel's own RedisQueue passes the raw (possibly enum) queue
            // here too; only enqueueUsing()'s PHPDoc still says string|null.
            $queue, // @phpstan-ignore argument.type
            null,
            fn (string $payload, \UnitEnum|string|null $queue) => $this->pushRaw($payload, $queue),
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload  JSON-encoded job payload.
     * @param  \UnitEnum|string|null  $queue
     * @param  array<string, mixed>  $options
     * @return string The stream message ID.
     */
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

        $this->recordQueued($payload, $queue);

        return (string) $messageId;
    }

    /**
     * Record the "queued" event to the per-job stream.
     */
    private function recordQueued(string $payload, \UnitEnum|string|null $queue): void
    {
        try {
            $decoded = self::decodePayload($payload, $this->serializer);

            if (isset($decoded['uuid'])) {
                app(JobStreamRecorder::class)->onQueued(
                    $decoded['uuid'],
                    $this->getQueue($queue),
                    $decoded['displayName'] ?? $decoded['job'] ?? 'Unknown',
                    JobTagExtractor::fromPayload($decoded),
                );
            }
        } catch (\Throwable) {
            // Never break job dispatch for stream recording.
        }
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  DateTimeInterface|DateInterval|int  $delay
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  \UnitEnum|string|null  $queue
     * @return string|null The payload UUID, or null when the job dispatches
     *                     after commit: the framework then hands it to the
     *                     transactions manager, which enqueues it once the open
     *                     transaction commits (dropped on rollback), or at once
     *                     when none is open, and discards the id either way.
     */
    #[\Override]
    public function later($delay, $job, $data = '', $queue = null): ?string
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            // Raw (possibly enum) queue, as Laravel's RedisQueue passes it;
            // see push().
            $queue, // @phpstan-ignore argument.type
            $delay,
            fn (string $payload, \UnitEnum|string|null $queue, $delay) => $this->laterRaw($delay, $payload, $queue),
        );
    }

    /**
     * Push a raw payload onto the delayed sorted set.
     */
    private function laterRaw(DateTimeInterface|DateInterval|int $delay, string $payload, \UnitEnum|string|null $queue = null): string
    {
        // Same transcode rationale as pushRaw: createPayload emits JSON.
        $payload = $this->transcodeIncomingPayload($payload);

        $score = $this->availableAt($delay);

        $this->redis->execute(
            'ZADD',
            $this->getStreamKey($queue).':delayed',
            (string) $score,
            $payload,
        );

        try {
            $decoded = self::decodePayload($payload, $this->serializer);
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
     * @param  \UnitEnum|string|null  $queue
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
        $payload = self::extractPayload($message[1]);

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
     * Get the size of the queue: waiting plus delayed plus reserved jobs.
     *
     * Same meaning as the stock Redis queue, where size() adds the ready list,
     * the delayed set and the reserved set.
     *
     * @param  \UnitEnum|string|null  $queue
     */
    #[\Override]
    public function size($queue = null): int
    {
        $depths = $this->depths($queue);

        return $depths['waiting'] + $depths['delayed'] + $depths['reserved'];
    }

    /**
     * Waiting, delayed and reserved counts of one queue in a single round trip.
     *
     * Waiting is XLEN minus the group's pending count, clamped at 0: acked
     * entries are deleted, so every entry left in the stream is either waiting
     * or in flight, but clear() can trim in-flight entries whose PEL records
     * stay, which would push the difference below zero. A missing stream or
     * group counts as 0 instead of raising NOGROUP. With `$withOldest` the id
     * of the first entry after the group's last-delivered id comes back too:
     * the oldest waiting job, never an in-flight one.
     *
     * @param  \UnitEnum|string|null  $queue
     * @return array{waiting: int, delayed: int, reserved: int, oldest: string|null}
     */
    public function depths($queue = null, bool $withOldest = false): array
    {
        $streamKey = $this->getStreamKey($queue);

        /** @var array{0: int|string, 1: int|string, 2: int|string, 3?: string|null} $result */
        $result = $this->redis->execute(
            'EVAL',
            self::LUA_DEPTHS,
            '2',
            $streamKey,
            $streamKey.':delayed',
            $this->consumerGroup,
            $withOldest ? '1' : '0',
        );

        $reserved = (int) $result[1];
        $oldest = (string) ($result[3] ?? '');

        return [
            'waiting' => max(0, (int) $result[0] - $reserved),
            'delayed' => (int) $result[2],
            'reserved' => $reserved,
            'oldest' => $oldest === '' ? null : $oldest,
        ];
    }

    /**
     * The consumers of this queue's group, with their pending count and idle time.
     *
     * A queue without a stream or group has no consumers.
     *
     * @param  \UnitEnum|string|null  $queue
     * @return list<array{name: string, pending: int, idle: int}> Idle time in milliseconds.
     */
    public function consumers($queue = null): array
    {
        try {
            /** @var list<list<mixed>>|null $rows */
            $rows = $this->redis->execute('XINFO', 'CONSUMERS', $this->getStreamKey($queue), $this->consumerGroup);
        } catch (RedisException $e) {
            if (str_contains($e->getMessage(), 'NOGROUP') || str_contains($e->getMessage(), 'no such key')) {
                return [];
            }

            throw $e;
        }

        return array_map(function (array $row): array {
            $fields = [];

            for ($i = 0, $count = count($row); $i + 1 < $count; $i += 2) {
                $fields[(string) $row[$i]] = $row[$i + 1];
            }

            return [
                'name' => (string) ($fields['name'] ?? ''),
                'pending' => (int) ($fields['pending'] ?? 0),
                'idle' => (int) ($fields['idle'] ?? 0),
            ];
        }, $rows ?? []);
    }

    /**
     * Delete every waiting and delayed job of a queue.
     *
     * The stream is emptied with XTRIM MAXLEN 0, never DEL: DEL would destroy
     * the consumer group and a running worker would then fail on its next read.
     * Entries a worker holds in the PEL are trimmed from the stream as well
     * (so they count here) but their PEL records stay until the worker acks.
     * The delayed set is emptied atomically with ZREMRANGEBYRANK. The redelivery
     * counts go too: their entries are gone.
     *
     * @param  \UnitEnum|string|null  $queue
     * @return int Stream entries plus delayed jobs removed.
     */
    #[\Override]
    public function clear($queue = null): int
    {
        $streamKey = $this->getStreamKey($queue);

        $trimmed = (int) $this->redis->execute('XTRIM', $streamKey, 'MAXLEN', '0');
        $delayed = (int) $this->redis->execute('ZREMRANGEBYRANK', $streamKey.':delayed', '0', '-1');
        $this->redis->execute('DEL', $streamKey.self::REDELIVERIES_SUFFIX);

        return $trimmed + $delayed;
    }

    /**
     * Get the number of waiting jobs: ready to be read, not delayed, not in flight.
     *
     * Stock semantics. The group's pending entries list is {@see reservedSize()}.
     */
    public function pendingSize($queue = null): int
    {
        return $this->depths($queue)['waiting'];
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize($queue = null): int
    {
        return (int) $this->redis->execute(
            'ZCARD',
            $this->getStreamKey($queue).':delayed',
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
        return $this->depths($queue)['reserved'];
    }

    /**
     * Get the creation timestamp (in seconds) of the oldest waiting job.
     *
     * Read from the first entry after the group's last-delivered id, so a job
     * in flight never counts. Returns null when nothing waits.
     */
    public function creationTimeOfOldestPendingJob($queue = null): ?float
    {
        $messageId = $this->depths($queue, true)['oldest'];

        if ($messageId === null) {
            return null;
        }

        // Redis stream message IDs are formatted as {millisecondsTimestamp}-{sequence}.
        return (int) Str::before($messageId, '-') / 1000;
    }

    /**
     * Get all pending (waiting) jobs across every queue.
     *
     * Stream entries that are not in the consumer group's Pending Entries List
     * (PEL) — i.e. jobs that have been XADDed but not yet claimed by any
     * consumer.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allPendingJobs(): Collection
    {
        return $this->allQueueNames()->flatMap(
            fn (string $name) => $this->pendingJobsForQueue($name),
        );
    }

    /**
     * Get all reserved (in-flight) jobs across every queue.
     *
     * Stream entries currently in the consumer group's PEL — jobs that have
     * been claimed by a consumer but not yet acknowledged. The XPENDING
     * delivery count is exposed as the inspected job's `attempts`.
     *
     * Capped at 1000 entries per queue to bound memory; the per-queue
     * {@see reservedSize()} is still authoritative for totals.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allReservedJobs(): Collection
    {
        return $this->allQueueNames()->flatMap(
            fn (string $name) => $this->reservedJobsForQueue($name),
        );
    }

    /**
     * Get all delayed jobs across every queue.
     *
     * Entries waiting in the per-queue `:delayed` sorted set, scored by the
     * unix timestamp at which they become eligible.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allDelayedJobs(): Collection
    {
        return $this->allQueueNames()->flatMap(
            fn (string $name) => $this->delayedJobsForQueue($name),
        );
    }

    /**
     * Enumerate the configured queue names by scanning the prefix.
     *
     * Includes queues that exist only as a `:delayed` sorted set (no stream
     * yet) and filters out Torque's own bookkeeping namespaces (the `paused`
     * flag, worker and aggregate metrics, per-job event streams, the
     * dead-letter stream and circuit-breaker state). Auxiliary suffixes
     * after the queue name are stripped so a single queue with both a
     * stream and a `:delayed` set is reported once.
     *
     * @return Collection<int, string>
     */
    private function allQueueNames(): Collection
    {
        return (new Collection($this->scanPrefixedKeys()))
            ->map(fn (string $key) => Str::after($key, $this->prefix))
            ->map(fn (string $name) => str_contains($name, ':') ? Str::before($name, ':') : $name)
            ->reject(fn (string $name) => $name === '' || in_array($name, self::RESERVED_NAMESPACES, true))
            ->map(fn (string $name) => $this->cluster ? trim($name, '{}') : $name)
            ->unique()
            ->values();
    }

    /**
     * Every key under the prefix, gathered with incremental SCAN.
     *
     * Never KEYS: the dashboard calls this on every load, and KEYS blocks the
     * whole server for the duration of the sweep on a large keyspace. SCAN may
     * return a key more than once; callers de-duplicate.
     *
     * @return list<string>
     */
    private function scanPrefixedKeys(): array
    {
        // Glob metacharacters in the prefix must match literally.
        $pattern = preg_replace('/[\\\\*?\[\]]/', '\\\\$0', $this->prefix).'*';

        $keys = [];
        $cursor = '0';

        do {
            /** @var array{0: string|int, 1?: list<string>}|null $result */
            $result = $this->redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500');

            if (! is_array($result)) {
                break;
            }

            $cursor = (string) $result[0];

            foreach ($result[1] ?? [] as $key) {
                $keys[] = (string) $key;
            }
        } while ($cursor !== '0');

        return $keys;
    }

    /**
     * Inspect pending (not yet claimed) entries for a single queue.
     *
     * @return Collection<int, InspectedJob>
     */
    private function pendingJobsForQueue(string $queue): Collection
    {
        $streamKey = $this->getStreamKey($queue);

        /** @var list<array{0: string, 1: list<string>}>|null $entries */
        $entries = $this->redis->execute('XRANGE', $streamKey, '-', '+');

        if (empty($entries)) {
            return new Collection;
        }

        $pendingIds = $this->pelMessageIds($streamKey, count($entries));

        return (new Collection($entries))
            ->reject(fn (array $entry) => isset($pendingIds[(string) $entry[0]]))
            ->map(function (array $entry) {
                $payload = self::extractPayload($entry[1]);

                return $payload === null ? null : InspectedJob::fromPayload($payload);
            })
            ->filter()
            ->values();
    }

    /**
     * Inspect reserved (in-flight) entries for a single queue.
     *
     * @return Collection<int, InspectedJob>
     */
    private function reservedJobsForQueue(string $queue): Collection
    {
        $streamKey = $this->getStreamKey($queue);

        $pending = $this->fetchPendingEntries($streamKey, 1000);

        return (new Collection($pending))
            ->map(function (array $entry) use ($streamKey) {
                $messageId = (string) $entry[0];
                $deliveryCount = (int) $entry[3];

                /** @var list<array{0: string, 1: list<string>}>|null $rows */
                $rows = $this->redis->execute('XRANGE', $streamKey, $messageId, $messageId);

                if (empty($rows)) {
                    return null;
                }

                $payload = self::extractPayload($rows[0][1]);

                return $payload === null ? null : InspectedJob::fromPayload($payload, $deliveryCount);
            })
            ->filter()
            ->values();
    }

    /**
     * Fetch up to $count entries from the consumer group's PEL.
     *
     * Tolerates the NOGROUP error that Redis returns when no consumer has
     * ever read from the stream (so the group has not been auto-created).
     * In that case the PEL is by definition empty, so we return an empty list.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function fetchPendingEntries(string $streamKey, int $count): array
    {
        try {
            /** @var list<array{0: string, 1: string, 2: int, 3: int}>|null $pending */
            $pending = $this->redis->execute(
                'XPENDING',
                $streamKey,
                $this->consumerGroup,
                '-',
                '+',
                (string) $count,
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'NOGROUP')) {
                return [];
            }

            throw $e;
        }

        return $pending ?: [];
    }

    /**
     * Build a fast lookup of PEL message IDs for the given stream.
     *
     * @return array<string, int>
     */
    private function pelMessageIds(string $streamKey, int $count): array
    {
        $pending = $this->fetchPendingEntries($streamKey, $count);

        return array_flip(array_map(fn (array $row) => (string) $row[0], $pending));
    }

    /**
     * Inspect delayed entries for a single queue.
     *
     * @return Collection<int, InspectedJob>
     */
    private function delayedJobsForQueue(string $queue): Collection
    {
        $delayedKey = $this->getStreamKey($queue).':delayed';

        /** @var list<string>|null $payloads */
        $payloads = $this->redis->execute('ZRANGE', $delayedKey, '0', '-1') ?: [];

        return (new Collection($payloads))
            ->map(fn (string $payload) => InspectedJob::fromPayload($payload));
    }

    /**
     * Extract the `payload` field value from a stream entry's flat field list.
     *
     * Returns null when the field is missing (corrupt or non-torque entry).
     *
     * @param  array<int, mixed>  $fields  Flat list: ['payload', '{json}', ...]
     */
    private static function extractPayload(array $fields): ?string
    {
        for ($i = 0, $count = count($fields); $i < $count; $i += 2) {
            if ((string) $fields[$i] === 'payload') {
                return (string) $fields[$i + 1];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    //  Acknowledge / release
    // -------------------------------------------------------------------------

    /**
     * Acknowledge and delete a message from the stream.
     *
     * An entry this process counted a redelivery for also loses its field. Failing
     * that, or crashing first, leaves a field nothing can read: stream ids are never
     * reused, and {@see StreamHousekeeper} and the hash's TTL remove it.
     */
    public function deleteAndAcknowledge(string $queue, string $messageId): void
    {
        $streamKey = $this->getStreamKey($queue);

        $this->redis->execute('XACK', $streamKey, $this->consumerGroup, $messageId);
        $this->redis->execute('XDEL', $streamKey, $messageId);

        $counted = $streamKey.'|'.$messageId;

        if (isset($this->countedRedeliveries[$counted])) {
            unset($this->countedRedeliveries[$counted]);

            try {
                $this->redis->execute('HDEL', $streamKey.self::REDELIVERIES_SUFFIX, $messageId);
            } catch (\Throwable) {
                // The job is done; this must not turn it into a failed one.
            }
        }
    }

    /**
     * Count one more delivery of an entry after its first, returning the total.
     * Only for workers under strict_attempts.
     */
    public function countRedelivery(string $queue, string $messageId): int
    {
        $streamKey = $this->getStreamKey($queue);

        $count = (int) $this->redis->execute(
            'EVAL',
            self::LUA_COUNT_REDELIVERY,
            '1',
            $streamKey.self::REDELIVERIES_SUFFIX,
            $messageId,
            (string) self::REDELIVERIES_TTL,
        );

        $this->countedRedeliveries[$streamKey.'|'.$messageId] = true;

        return $count;
    }

    /**
     * Hand back entries a stopping worker read and never started, returning how
     * many went back. Not a release: no attempt happened, so attempts stay as they are.
     *
     * @param  list<string>  $ids
     */
    public function returnUnstarted(string $streamKey, string $consumer, array $ids): int
    {
        $returned = 0;

        foreach (array_chunk($ids, self::RETURN_UNSTARTED_BATCH) as $batch) {
            $returned += (int) $this->redis->execute(
                'EVAL',
                self::LUA_RETURN_UNSTARTED,
                '2',
                $streamKey,
                $streamKey.self::REDELIVERIES_SUFFIX,
                $this->consumerGroup,
                $consumer,
                ...$batch,
            );
        }

        return $returned;
    }

    /**
     * Release a job back onto the queue, optionally with a delay.
     *
     * A new message with an incremented attempt counter replaces the original
     * in one script. When that write fails the error reaches the caller and the
     * original stays in the stream and the PEL, so it is redelivered.
     */
    public function release(string $queue, StreamJob $job, int $delay = 0): void
    {
        $releases = $job->releases();
        $deadline = $job->releaseDeadline();

        // Only the first release starts the clock, so releasing again cannot extend it.
        if ($releases === 0) {
            $limit = $this->releaseLimitSeconds($queue);
            $deadline = $limit > 0 ? $this->availableAt(max(0, $delay)) + $limit : null;
        }

        $this->requeue($queue, $job, $delay, $releases + 1, $deadline);
    }

    /**
     * Put a job back on the queue for another try after a failed attempt.
     *
     * The same write as a release, with the release counters as they were.
     */
    public function retryAfterFailure(string $queue, StreamJob $job, int $delay = 0): void
    {
        $this->requeue($queue, $job, $delay, $job->releases(), $job->releaseDeadline());
    }

    /**
     * The seconds a job may keep releasing itself on this queue, 0 for no limit.
     *
     * Nothing here enforces it: this only decides the deadline a first release
     * writes. The worker enforces it, so a stock `queue:work` ignores the deadline.
     */
    public function releaseLimitSeconds(string $queue): int
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return self::DEFAULT_RELEASE_LIMIT_SECONDS;
        }

        $config = $container->make('config');
        $streams = (array) $config->get('torque.streams', []);

        $limit = $streams[$queue]['release_limit_seconds']
            ?? $config->get('torque.release_limit_seconds')
            ?? self::DEFAULT_RELEASE_LIMIT_SECONDS;

        return max(0, (int) $limit);
    }

    private function requeue(string $queue, StreamJob $job, int $delay, int $releases, ?int $deadline): void
    {
        $streamKey = $this->getStreamKey($queue);

        // Rebuild payload with incremented attempts.
        $payload = self::decodePayload($job->getRawBody(), $this->serializer);
        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
        unset($payload['torque_releases'], $payload['torque_release_deadline']);

        if ($releases > 0) {
            $payload['torque_releases'] = $releases;
        }

        if ($deadline !== null) {
            $payload['torque_release_deadline'] = $deadline;
        }

        $newPayload = $this->encodePayload($payload);

        $this->redis->execute(
            'EVAL',
            self::LUA_RELEASE,
            '3',
            $streamKey,
            $streamKey.':delayed',
            $streamKey.self::REDELIVERIES_SUFFIX,
            $this->consumerGroup,
            $job->messageId,
            $newPayload,
            $delay > 0 ? (string) $this->availableAt($delay) : '',
        );

        unset($this->countedRedeliveries[$streamKey.'|'.$job->messageId]);

        if ($delay <= 0) {
            $this->recordQueued($newPayload, $queue);
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
        } catch (RedisException $e) {
            // "BUSYGROUP Consumer Group name already exists" is expected.
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
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
    public function getQueue(\UnitEnum|string|null $queue = null): string
    {
        return (string) (enum_value($queue) ?: $this->default);
    }

    /**
     * Build the full Redis stream key for a given queue.
     *
     * When cluster mode is enabled, wraps the queue name in a Redis hash tag
     * so all related keys (stream, :delayed, :notify) land on the same slot.
     */
    public function getStreamKey(\UnitEnum|string|null $queue = null): string
    {
        $queue = $this->getQueue($queue);

        if ($this->cluster && ! str_contains($queue, '{')) {
            $queue = '{'.$queue.'}';
        }

        return $this->prefix.$queue;
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
