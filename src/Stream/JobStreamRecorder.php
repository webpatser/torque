<?php

declare(strict_types=1);

namespace Webpatser\Torque\Stream;

use Fledge\Async\Redis\RedisClient;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Webpatser\Torque\Contracts\Silenced;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Records job lifecycle events to a per-job Redis Stream.
 *
 * Each job gets its own stream at `{prefix}job:{uuid}` containing all lifecycle
 * events (queued, started, completed, failed, etc.) plus optional custom events
 * emitted by the job via the {@see Streamable} trait.
 *
 * Events are recorded by listening to Laravel's built-in queue events —
 * no changes to WorkerProcess needed.
 */
final class JobStreamRecorder
{
    /**
     * Upper bound on the life of a stream no terminal event ever reaches: a worker
     * killed mid-job, a job deleted before it threw, a late emit from an async child.
     * A job still running after this long loses its timeline.
     */
    public const int SAFETY_TTL = 604800;

    /**
     * Lua script writing one event and its expiry, so nothing can land between them
     * and the key cannot exist without a TTL. It touches only the job's own key, so
     * it is safe on a cluster.
     *
     * - `terminal`: the short TTL.
     * - `queued`: the short TTL, but only on the stream's first event. The push writes
     *   queued after its XADD, so a fast worker's started can come first, and a short
     *   TTL then would expire a running job.
     * - `started`: the safety TTL, replacing a short one.
     * - anything else: the safety TTL when the key has none (EXPIRE NX, spelled out
     *   because NX needs Redis 7 and this reads the same on every version). A finished
     *   job's short TTL stays.
     *
     * ARGV: expiry mode, short TTL, safety TTL, stream cap, then the event's fields.
     */
    private const string LUA_RECORD = <<<'LUA'
local id = redis.call('XADD', KEYS[1], 'MAXLEN', '~', ARGV[4], '*', unpack(ARGV, 5))

if ARGV[1] == 'terminal' or (ARGV[1] == 'queued' and redis.call('XLEN', KEYS[1]) == 1) then
    redis.call('EXPIRE', KEYS[1], ARGV[2])
elseif ARGV[1] == 'started' or redis.call('TTL', KEYS[1]) == -1 then
    redis.call('EXPIRE', KEYS[1], ARGV[3])
end

return id
LUA;

    private ?RedisClient $redis = null;

    /**
     * What is known about each attempt, by job object: every attempt has its own,
     * also when another slot runs the same uuid after a release, and the entry goes
     * when the worker lets go of the job.
     *
     * @var \WeakMap<object, array{tags: list<string>, silenced: bool, failed: bool}>
     */
    private \WeakMap $attempts;

    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix = 'torque:',
        private readonly int $ttl = 300,
        private readonly int $maxEvents = 1000,
        private readonly bool $enabled = true,
    ) {
        $this->attempts = new \WeakMap;
    }

    /**
     * Record a "queued" event when a job enters the stream.
     *
     * @param  list<string>  $tags
     */
    public function onQueued(string $uuid, string $queue, string $displayName, array $tags = []): void
    {
        $this->record($uuid, 'queued', self::tagged($tags, [
            'queue' => $queue,
            'displayName' => $displayName,
        ]));
    }

    /**
     * Record a "started" event when a worker picks up a job.
     */
    public function onProcessing(JobProcessing $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $uuid = $this->extractUuid($event->job);

        if ($uuid === null) {
            return;
        }

        $this->record($uuid, 'started', $this->withTags($event->job, [
            'queue' => $event->job->getQueue() ?: 'default',
            // The torque driver records the name on the queued event, but jobs
            // can enter through other drivers (a classic queue:work draining a
            // legacy redis list during a migration): their per-job stream then
            // starts here, and without a name the dashboard shows "(unknown)".
            'displayName' => $event->job->resolveName(),
            'attempt' => (string) $event->job->attempts(),
            'worker' => gethostname().'-'.getmypid(),
        ]));
    }

    /**
     * The attempt a job object stands for, read from its payload the first time.
     *
     * @return array{tags: list<string>, silenced: bool, failed: bool}
     */
    private function attempt(object $job): array
    {
        return $this->attempts[$job] ??= $this->inspect($job);
    }

    /**
     * Read a job's tags and whether it is silenced from its payload. The command
     * stays serialized: is_subclass_of on the class name instantiates nothing.
     *
     * Never throws: a failure logs and yields no tags.
     *
     * @return array{tags: list<string>, silenced: bool, failed: bool}
     */
    private function inspect(mixed $job): array
    {
        $tags = [];
        $name = '';

        try {
            $payload = $job->payload();
            $name = (string) ($payload['data']['commandName'] ?? $payload['displayName'] ?? '');
            $tags = JobTagExtractor::fromPayload($payload);
        } catch (\Throwable $e) {
            try {
                logger()->warning('Torque could not read job tags: '.$e->getMessage());
            } catch (\Throwable) {
                // Logging must not break the job either.
            }
        }

        try {
            $silenced = ($name !== '' && (
                is_subclass_of($name, Silenced::class)
                || in_array($name, (array) config('torque.silenced', []), true)
            )) || array_intersect($tags, (array) config('torque.silenced_tags', [])) !== [];
        } catch (\Throwable) {
            $silenced = false;
        }

        return ['tags' => $tags, 'silenced' => $silenced, 'failed' => false];
    }

    /**
     * Attach a job's tags to an event's fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withTags(object $job, array $data): array
    {
        return self::tagged($this->attempt($job)['tags'], $data);
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function tagged(array $tags, array $data): array
    {
        if ($tags !== []) {
            $data['tags'] = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $data;
    }

    /**
     * Drop a silenced job's stream and index entries.
     */
    private function forget(string $uuid): void
    {
        try {
            $redis = $this->getRedis();
            $redis->execute('DEL', $this->prefix.'job:'.$uuid);
            $redis->execute('ZREM', $this->prefix.'jobs:recent', $uuid);
            $redis->execute('ZREM', $this->prefix.'jobs:active', $uuid);
        } catch (\Throwable) {
            // Never let stream recording break job processing.
        }
    }

    /**
     * Record a "completed" event when a job finishes successfully.
     *
     * JobProcessed also fires after a self-release and after a fail() without a
     * throw, and neither is a success. A released job may be running in another
     * slot by now, under the same uuid and stream.
     */
    public function onProcessed(JobProcessed $event): void
    {
        if (! $this->enabled || $event->job->isReleased() || $event->job->hasFailed()) {
            return;
        }

        $uuid = $this->extractUuid($event->job);

        if ($uuid === null) {
            return;
        }

        if ($this->attempt($event->job)['silenced']) {
            $this->forget($uuid);

            return;
        }

        $this->record($uuid, 'completed', $this->withTags($event->job, [
            'memory_bytes' => (string) memory_get_usage(true),
        ]), terminal: true);
    }

    /**
     * Record a "failed" event when a job permanently fails.
     */
    public function onFailed(JobFailed $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $uuid = $this->extractUuid($event->job);

        if ($uuid === null) {
            return;
        }

        $this->attempts[$event->job] = ['failed' => true] + $this->attempt($event->job);

        $this->record($uuid, 'failed', $this->withTags($event->job, [
            'exception_class' => $event->exception::class,
            'exception_message' => mb_substr($event->exception->getMessage(), 0, 500),
            'attempt' => (string) $event->job->attempts(),
        ]), terminal: true);
    }

    /**
     * Record an "exception" event when a job throws but may be retried.
     *
     * On a permanent failure this fires after JobFailed, as in the stock worker.
     * The failed event already tells the story, and an event after it would make
     * the job look unfinished: the dashboard reads a job's status off its last event.
     */
    public function onExceptionOccurred(JobExceptionOccurred $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $uuid = $this->extractUuid($event->job);

        if ($uuid === null || $this->attempt($event->job)['failed']) {
            return;
        }

        $this->record($uuid, 'exception', $this->withTags($event->job, [
            'exception_class' => $event->exception::class,
            'exception_message' => mb_substr($event->exception->getMessage(), 0, 500),
            'attempt' => (string) $event->job->attempts(),
        ]));
    }

    /**
     * Record a custom event emitted by the job via the Streamable trait.
     *
     * @param  array<array-key, mixed>  $data
     * @param  object|null  $job  The queue job emitting it, whose tags the event carries.
     */
    public function emitCustom(string $uuid, string $message, ?float $progress = null, array $data = [], ?object $job = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $fields = ['message' => $message];

        if ($progress !== null) {
            $fields['progress'] = (string) round($progress, 4);
        }

        foreach ($data as $key => $value) {
            $fields[(string) $key] = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $this->record($uuid, 'progress', $job === null ? $fields : $this->withTags($job, $fields));
    }

    /**
     * Core: write an event to the per-job Redis Stream.
     *
     * Also maintains two sorted-set indexes used by the dashboard to avoid
     * O(N) SCAN lookups:
     *
     * - `{prefix}jobs:active`: uuids whose most recent event is non-terminal.
     * - `{prefix}jobs:recent`: all uuids, scored by timestamp (newest first).
     *
     * Both sets are trimmed opportunistically to drop entries older than the
     * per-job stream TTL.
     *
     * @param  array<string, mixed>  $data  Extra stream fields; values must be Redis-encodable scalars.
     */
    public function record(string $uuid, string $type, array $data, bool $terminal = false): void
    {
        if (! $this->enabled || $uuid === '') {
            return;
        }

        try {
            $redis = $this->getRedis();
            $key = $this->prefix.'job:'.$uuid;
            $now = time();

            $args = [
                'EVAL', self::LUA_RECORD, '1', $key,
                $terminal ? 'terminal' : ($type === 'queued' || $type === 'started' ? $type : 'other'),
                (string) $this->ttl,
                (string) self::SAFETY_TTL,
                (string) $this->maxEvents,
                'type', $type,
                'timestamp', (string) hrtime(true),
            ];

            foreach ($data as $field => $value) {
                $args[] = $field;
                $args[] = $value;
            }

            $redis->execute(...$args);

            // Indexes: all recent jobs, and active (non-terminal) jobs.
            $recentKey = $this->prefix.'jobs:recent';
            $activeKey = $this->prefix.'jobs:active';

            $redis->execute('ZADD', $recentKey, (string) $now, $uuid);

            if ($terminal) {
                $redis->execute('ZREM', $activeKey, $uuid);
            } else {
                $redis->execute('ZADD', $activeKey, (string) $now, $uuid);
            }

            // Opportunistic trim (~1/100 writes) so the indexes don't grow
            // unbounded when many short-lived jobs run.
            if (random_int(0, 99) === 0) {
                $cutoff = (string) ($now - $this->ttl);
                $redis->execute('ZREMRANGEBYSCORE', $recentKey, '-inf', '('.$cutoff);
                $redis->execute('ZREMRANGEBYSCORE', $activeKey, '-inf', '('.$cutoff);
            }
        } catch (\Throwable) {
            // Never let stream recording break job processing.
        }
    }

    /**
     * Extract the job UUID from a queue job instance.
     */
    private function extractUuid(mixed $job): ?string
    {
        if (! is_object($job)) {
            return null;
        }

        if (method_exists($job, 'uuid') && $job->uuid() !== '') {
            return $job->uuid();
        }

        if (method_exists($job, 'getJobId')) {
            $id = $job->getJobId();

            return $id !== '' && $id !== null ? $id : null;
        }

        return null;
    }

    private function getRedis(): RedisClient
    {
        return $this->redis ??= createRedisClient($this->redisUri);
    }
}
