<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Dashboard\Support\Range;
use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Support\StreamQueueResolver;
use Webpatser\Torque\Torque;
use Webpatser\Torque\Worker\WorkerProcess;

/**
 * Per-stream depth read-model for the queues screen.
 *
 * Counts, throughput and the per-stream sparkline all come from the per-stream
 * metric rollups over the dashboard's global range, so the columns mean the
 * same thing here as on the overview. Depth, pause state and breaker state are
 * point-in-time by nature and ignore the range. `wait` still has no collector
 * and stays `null`, so the UI hides that column.
 */
final class QueuesData
{
    public function __construct(
        private readonly MetricsPublisher $metrics,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * @param  string  $range  A {@see Range} key; scopes the counter columns.
     * @return array{queues: list<array<string, mixed>>}
     */
    public function get(string $range = Range::DEFAULT): array
    {
        $window = Range::make($range);
        $queue = StreamQueueResolver::make();
        $queues = [];

        // Workers write rollups, pauses and breaker state under the bare name, so
        // a stream configured as `{q}` in cluster mode has to be looked up as `q`.
        // Its depth still resolves: the queue tags a bare name again.
        $cluster = (bool) config('torque.redis.cluster', false);
        $names = array_values(array_unique(array_map(
            static fn (int|string $name): string => WorkerProcess::bareQueueName((string) $name, $cluster),
            array_keys((array) config('torque.streams', [])),
        )));

        // Framework pause state (queue:pause / queue:pause --all); one cache
        // round-trip for all names. A broken cache store must not 500 the
        // dashboard, so degrade to "nothing paused".
        try {
            $paused = array_flip(app('queue')->getPausedQueues(Torque::CONNECTION, $names));
        } catch (\Throwable) {
            $paused = [];
        }

        $since = $window->sinceEpoch();

        foreach ($names as $name) {
            $totals = $this->metrics->totalsSince($since, $name);

            // The per-stream rollup has always been written; nothing read it
            // until now, so this sparkline used to be an empty array that the
            // Queues component filled in one poll at a time and lost on every
            // reload.
            $series = $this->metrics->series($window->tier, $window->count, $name);

            $depths = $queue->depths($name);
            $consumers = $queue->consumers($name);

            $queues[] = [
                'name' => $name,
                'pending' => $depths['waiting'],
                'delayed' => $depths['delayed'],
                'reserved' => $depths['reserved'],
                'consumers' => count($consumers),
                // Shortest idle time among the consumers, in milliseconds: how
                // long ago the group last did anything. Null without consumers.
                'idle' => $consumers === [] ? null : min(array_column($consumers, 'idle')),
                'processed' => $totals['processed'],
                'failed' => $totals['failed'],
                // Jobs per minute across the whole range, so the column reads
                // the same at 1h as at 90d (the Jobs screen does likewise).
                'throughput' => round(
                    ($totals['processed'] + $totals['failed']) / $window->minutes,
                    1,
                ),
                'wait' => null,
                'history' => array_map(
                    static fn (array $outcome): int => $outcome['processed'],
                    array_values($series),
                ),
                'paused' => isset($paused[$name]),
                'circuit' => $this->circuit($name),
            ];
        }

        return ['queues' => $queues];
    }

    /**
     * Breaker state for one stream, or null when it is closed.
     *
     * A breaker that cannot be read must never break the screen, hence the
     * rescue: the queues page is the thing an operator opens during exactly the
     * incident that trips it.
     *
     * @return array{state: string, resumesIn: int|null}|null
     */
    private function circuit(string $queue): ?array
    {
        $state = rescue(fn (): ?array => $this->breaker->state($queue), null, false);

        if ($state === null) {
            return null;
        }

        $resumesAt = $state['resumes_at'] ?? null;

        return [
            'state' => (string) $state['state'],
            'resumesIn' => $resumesAt === null ? null : max(0, (int) $resumesAt - time()),
        ];
    }
}
