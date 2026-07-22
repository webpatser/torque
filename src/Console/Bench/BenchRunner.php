<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console\Bench;

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Support\Str;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Queue\StreamQueue;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Orchestration layer for `torque:bench`.
 *
 * Responsibilities (v1):
 *  1. Pre-flight: clean the per-run streams so each invocation starts dry.
 *  2. Enqueue N {@see BenchJob} instances on the configured Torque connection.
 *  3. Tail the per-run results stream via XREADGROUP, feeding samples into
 *     {@see BenchAggregator} until `--jobs` results are observed.
 *  4. Cleanup the per-run streams on exit.
 *
 * Worker spawning is OUT OF SCOPE for v1: {@see MasterProcess}
 * uses `pcntl_exec` to launch `torque:worker` artisan processes which re-read
 * `config('torque')` from disk, so a child cannot pick up an in-memory config
 * override (e.g. a different consumer group or serializer). v1 therefore
 * requires the user to run `php artisan torque:start` against the bench config
 * out-of-band and pass `--use-running-master`. Self-spawning with config
 * isolation is tracked as v1.1 follow-up in the plan file.
 *
 * TODO(bench): per-stage breakdown via BenchProbe (see plan section A5).
 */
final class BenchRunner
{
    private const BENCH_RESULTS_GROUP = 'torque-bench-tailer';

    public function __construct(
        private readonly int $jobs,
        private readonly int $workers,
        private readonly int $coroutines,
        private readonly string $workload,
        private readonly string $serializer,
        private readonly int $warmup,
        private readonly bool $useRunningMaster,
        private readonly string $runId,
    ) {}

    /**
     * Execute the benchmark run end-to-end.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $aggregator = new BenchAggregator;
        $redis = $this->resolveRedis();
        $resultsStream = "torque-bench:{$this->runId}:results";

        $this->preflightCleanup($redis, $resultsStream);

        // Ensure the consumer group exists before any producer XADDs land, so
        // we never miss the first samples to a "no group" race.
        $this->ensureGroup($redis, $resultsStream, self::BENCH_RESULTS_GROUP);

        $aggregator->wallStart();

        $this->enqueueJobs();

        $this->tailResults($redis, $resultsStream, $aggregator);

        $aggregator->wallStop();

        $this->cleanup($redis, $resultsStream);

        $summary = $aggregator->summary();

        return [
            'run_id' => $this->runId,
            'config' => [
                'jobs' => $this->jobs,
                'workers' => $this->workers,
                'coroutines' => $this->coroutines,
                'workload' => $this->workload,
                'serializer' => $this->serializer,
                'warmup' => $this->warmup,
                'use_running_master' => $this->useRunningMaster,
            ],
            'results' => $summary,
        ];
    }

    /**
     * Resolve a Redis client for results-stream operations.
     *
     * Reuses the StreamQueue's client when bound (test/dev), otherwise opens
     * a fresh connection from the queue connection URI.
     */
    private function resolveRedis(): RedisClient
    {
        try {
            return app(StreamQueue::class)->getRedisClient();
        } catch (\Throwable) {
            $uri = (string) config(
                'queue.connections.torque.redis_uri',
                config('torque.redis.uri', 'redis://127.0.0.1:6379'),
            );

            return createRedisClient($uri);
        }
    }

    /**
     * XTRIM and DEL all per-run keys so a stale prior run cannot pollute samples.
     */
    private function preflightCleanup(RedisClient $redis, string $resultsStream): void
    {
        $base = "torque-bench:{$this->runId}";

        try {
            $redis->execute('XTRIM', $base, 'MAXLEN', '0');
        } catch (\Throwable) {
            // Stream may not exist; ignore.
        }

        try {
            $redis->execute('DEL', $resultsStream);
        } catch (\Throwable) {
            // Best-effort.
        }

        try {
            $redis->execute('DEL', "{$base}:delayed");
        } catch (\Throwable) {
            // Best-effort.
        }
    }

    /**
     * Create the consumer group on the results stream (idempotent).
     */
    private function ensureGroup(RedisClient $redis, string $stream, string $group): void
    {
        try {
            $redis->execute('XGROUP', 'CREATE', $stream, $group, '0', 'MKSTREAM');
        } catch (RedisException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * Dispatch all bench jobs onto the queue.
     */
    private function enqueueJobs(): void
    {
        for ($i = 0; $i < $this->jobs; $i++) {
            [$workload, $blob] = $this->buildWorkloadFor($i);

            $job = new BenchJob(
                workload: $workload,
                enqueuedNs: hrtime(true),
                enqueuedMicrotime: microtime(true),
                runId: $this->runId,
                blob: $blob,
            );

            BenchJob::dispatch(
                $job->workload,
                $job->enqueuedNs,
                $job->enqueuedMicrotime,
                $job->runId,
                $job->blob,
            );
        }
    }

    /**
     * Resolve the workload tag and blob for the i-th job.
     *
     * @return array{0: string, 1: string|null}
     */
    private function buildWorkloadFor(int $index): array
    {
        return match ($this->workload) {
            'cpu' => ['cpu', null],
            'io' => ['io', null],
            'async-io' => ['async-io', null],
            'fanout' => ['fanout', null],
            'payload-small' => ['payload-small', $this->payload(256)],
            'payload-large' => ['payload-large', $this->payload(64 * 1024)],
            // mixed: 80% io, 20% cpu by index modulo (0..3 -> io, 4 -> cpu).
            'mixed' => $index % 5 === 4 ? ['cpu', null] : ['io', null],
            default => ['io', null],
        };
    }

    private function payload(int $bytes): string
    {
        return Str::random($bytes);
    }

    /**
     * Tail the results stream until we have observed all expected samples.
     *
     * Discards the first {@see $warmup} samples (after warmup completes the
     * worker is in steady state). Emits no samples to the aggregator until the
     * warmup count is met.
     */
    private function tailResults(RedisClient $redis, string $stream, BenchAggregator $aggregator): void
    {
        $observed = 0;
        $consumerId = 'bench-'.getmypid();
        $deadlineSeconds = max(60, $this->jobs);
        $started = microtime(true);

        while ($observed < $this->jobs) {
            if (microtime(true) - $started > $deadlineSeconds) {
                throw new \RuntimeException(
                    "Bench timed out: observed {$observed}/{$this->jobs} results in {$deadlineSeconds}s. "
                    .'Is `php artisan torque:start` running with the bench-isolated config?',
                );
            }

            $response = $redis->execute(
                'XREADGROUP',
                'GROUP',
                self::BENCH_RESULTS_GROUP,
                $consumerId,
                'COUNT',
                '500',
                'BLOCK',
                '1000',
                'STREAMS',
                $stream,
                '>',
            );

            if ($response === null) {
                continue;
            }

            $streamData = $response[0] ?? null;
            if ($streamData === null) {
                continue;
            }

            $messages = $streamData[1] ?? [];

            foreach ($messages as $message) {
                $messageId = (string) $message[0];
                $fields = $message[1] ?? [];

                $sample = $this->parseFields($fields);

                $observed++;

                if ($observed > $this->warmup && $sample !== null) {
                    $aggregator->addSample(
                        $sample['total_ms'],
                        $sample['handle_ns'],
                        $sample['workload'],
                    );
                }

                $redis->execute('XACK', $stream, self::BENCH_RESULTS_GROUP, $messageId);
            }
        }
    }

    /**
     * Parse the flat XADD field list into a sample row.
     *
     * @param  array<int, mixed>  $fields
     * @return array{total_ms: float, handle_ns: int, workload: string}|null
     */
    private function parseFields(array $fields): ?array
    {
        $kv = [];
        for ($i = 0, $count = count($fields); $i < $count; $i += 2) {
            $kv[(string) $fields[$i]] = (string) $fields[$i + 1];
        }

        if (! isset($kv['total_ms'], $kv['handle_ns'], $kv['workload'])) {
            return null;
        }

        return [
            'total_ms' => (float) $kv['total_ms'],
            'handle_ns' => (int) $kv['handle_ns'],
            'workload' => $kv['workload'],
        ];
    }

    /**
     * Drop per-run state from Redis.
     */
    private function cleanup(RedisClient $redis, string $resultsStream): void
    {
        try {
            $redis->execute('XGROUP', 'DESTROY', $resultsStream, self::BENCH_RESULTS_GROUP);
        } catch (\Throwable) {
            // Best-effort.
        }

        try {
            $redis->execute('DEL', $resultsStream);
        } catch (\Throwable) {
            // Best-effort.
        }
    }
}
