<?php

declare(strict_types=1);

namespace Webpatser\Torque\Stream;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

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
    private ?\Fledge\Async\Redis\RedisClient $redis = null;

    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix = 'torque:',
        private readonly int $ttl = 300,
        private readonly int $maxEvents = 1000,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Record a "queued" event when a job enters the stream.
     */
    public function onQueued(string $uuid, string $queue, string $displayName): void
    {
        $this->record($uuid, 'queued', [
            'queue' => $queue,
            'displayName' => $displayName,
        ]);
    }

    /**
     * Record a "started" event when a worker picks up a job.
     */
    public function onProcessing(JobProcessing $event): void
    {
        $uuid = $this->extractUuid($event->job);

        if ($uuid === null) {
            return;
        }

        $this->record($uuid, 'started', [
            'queue' => $event->job->getQueue() ?? 'default',
            'attempt' => (string) $event->job->attempts(),
            'worker' => gethostname() . '-' . getmypid(),
        ]);
    }

    /**
     * Record a "completed" event when a job finishes successfully.
     */
    public function onProcessed(JobProcessed $event): void
    {
        $uuid = $this->extractUuid($event->job);
        if ($uuid === null) {
            return;
        }

        $this->record($uuid, 'completed', [
            'memory_bytes' => (string) memory_get_usage(true),
        ], terminal: true);
    }

    /**
     * Record a "failed" event when a job permanently fails.
     */
    public function onFailed(JobFailed $event): void
    {
        $uuid = $this->extractUuid($event->job);

        if ($uuid === null) {
            return;
        }

        $this->record($uuid, 'failed', [
            'exception_class' => $event->exception::class,
            'exception_message' => mb_substr($event->exception->getMessage(), 0, 500),
            'attempt' => (string) $event->job->attempts(),
        ], terminal: true);
    }

    /**
     * Record an "exception" event when a job throws but may be retried.
     */
    public function onExceptionOccurred(JobExceptionOccurred $event): void
    {
        $uuid = $this->extractUuid($event->job);

        if ($uuid === null) {
            return;
        }

        $this->record($uuid, 'exception', [
            'exception_class' => $event->exception::class,
            'exception_message' => mb_substr($event->exception->getMessage(), 0, 500),
            'attempt' => (string) $event->job->attempts(),
        ]);
    }

    /**
     * Record a custom event emitted by the job via the Streamable trait.
     */
    public function emitCustom(string $uuid, string $message, ?float $progress = null, array $data = []): void
    {
        $fields = ['message' => $message];

        if ($progress !== null) {
            $fields['progress'] = (string) round($progress, 4);
        }

        foreach ($data as $key => $value) {
            $fields[(string) $key] = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $this->record($uuid, 'progress', $fields);
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
     */
    public function record(string $uuid, string $type, array $data, bool $terminal = false): void
    {
        if (! $this->enabled || $uuid === '') {
            return;
        }

        try {
            $redis = $this->getRedis();
            $key = $this->prefix . 'job:' . $uuid;
            $now = time();

            $args = [
                $key,
                'MAXLEN', '~', (string) $this->maxEvents,
                '*',
                'type', $type,
                'timestamp', (string) hrtime(true),
            ];

            foreach ($data as $field => $value) {
                $args[] = $field;
                $args[] = $value;
            }

            $redis->execute('XADD', ...$args);

            // Indexes: all recent jobs, and active (non-terminal) jobs.
            $recentKey = $this->prefix . 'jobs:recent';
            $activeKey = $this->prefix . 'jobs:active';

            $redis->execute('ZADD', $recentKey, (string) $now, $uuid);

            if ($terminal) {
                $redis->execute('ZREM', $activeKey, $uuid);
                // Set TTL on terminal events so the stream auto-cleans.
                $redis->execute('EXPIRE', $key, (string) $this->ttl);
            } else {
                $redis->execute('ZADD', $activeKey, (string) $now, $uuid);
            }

            // Opportunistic trim (~1/100 writes) so the indexes don't grow
            // unbounded when many short-lived jobs run.
            if (random_int(0, 99) === 0) {
                $cutoff = (string) ($now - $this->ttl);
                $redis->execute('ZREMRANGEBYSCORE', $recentKey, '-inf', '(' . $cutoff);
                $redis->execute('ZREMRANGEBYSCORE', $activeKey, '-inf', '(' . $cutoff);
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
        if (method_exists($job, 'uuid') && $job->uuid() !== '') {
            return $job->uuid();
        }

        if (method_exists($job, 'getJobId')) {
            $id = $job->getJobId();

            return $id !== '' && $id !== null ? $id : null;
        }

        return null;
    }

    private function getRedis(): \Fledge\Async\Redis\RedisClient
    {
        return $this->redis ??= createRedisClient($this->redisUri);
    }
}
