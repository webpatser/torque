<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console\Bench;

use Fledge\Async\Redis\RedisClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webpatser\Torque\Queue\StreamQueue;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Synthetic job dispatched by `torque:bench`.
 *
 * The job branches on a workload tag and reports its end-to-end + intra-process
 * timings to a per-run results stream so the bench command can aggregate them.
 *
 * Clock domains:
 *  - `enqueuedMicrotime` is captured with {@see microtime()} and is comparable
 *    across processes. Used to compute end-to-end latency in milliseconds.
 *  - `enqueuedNs` is captured with {@see hrtime()} and is only meaningful when
 *    compared inside the same process. Kept on the envelope for forward
 *    compatibility but not used for cross-process measurements in v1.
 */
final class BenchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $workload,
        public readonly int $enqueuedNs,
        public readonly float $enqueuedMicrotime,
        public readonly string $runId,
        public readonly ?string $blob = null,
    ) {}

    public function handle(): void
    {
        $startNs = hrtime(true);

        switch ($this->workload) {
            case 'cpu':
                for ($i = 0; $i < 5000; $i++) {
                    hash('xxh3', random_bytes(64));
                }
                break;
            case 'io':
                usleep(2000);
                break;
            case 'async-io':
                \Fledge\Async\delay(0.002);
                break;
            case 'fanout':
                \Fledge\Async\delay(0.1);
                break;
            case 'payload-small':
            case 'payload-large':
                // Touch the blob length so the optimizer cannot eliminate it.
                if ($this->blob !== null) {
                    $touched = strlen($this->blob);
                    if ($touched < 0) {
                        // Unreachable, but keeps the read live.
                        return;
                    }
                }
                break;
        }

        $handleNs = hrtime(true) - $startNs;
        $totalMs = (microtime(true) - $this->enqueuedMicrotime) * 1000.0;

        // TODO(bench): per-stage breakdown via BenchProbe (see plan section A5).

        $this->emitResult($totalMs, $handleNs);
    }

    /**
     * Emit a single XADD to the per-run results stream.
     *
     * Best-effort: failing to record a sample must not break the worker loop.
     */
    private function emitResult(float $totalMs, int $handleNs): void
    {
        try {
            $client = $this->resolveRedisClient();

            $client->execute(
                'XADD',
                "torque-bench:{$this->runId}:results",
                'MAXLEN',
                '~',
                '200000',
                '*',
                'total_ms',
                (string) $totalMs,
                'handle_ns',
                (string) $handleNs,
                'workload',
                $this->workload,
            );
        } catch (\Throwable) {
            // Swallow: a missed sample is preferable to a poisoned worker.
        }
    }

    /**
     * Resolve a Redis client for emitting bench results.
     *
     * Prefer the StreamQueue's existing client (already pooled and configured),
     * fall back to a direct connection if the bound queue is not the torque
     * driver in this context.
     */
    private function resolveRedisClient(): RedisClient
    {
        try {
            $queue = app(StreamQueue::class);

            return $queue->getRedisClient();
        } catch (\Throwable) {
            // Fall through.
        }

        $uri = (string) config('queue.connections.torque.redis_uri', config('torque.redis.uri', 'redis://127.0.0.1:6379'));

        return createRedisClient($uri);
    }
}
