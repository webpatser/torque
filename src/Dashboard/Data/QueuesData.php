<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Support\StreamQueueResolver;
use Webpatser\Torque\Torque;

/**
 * Per-stream depth read-model for the queues screen.
 *
 * Counts and throughput come from the per-stream metric rollups; `wait` still
 * has no collector and stays `null`, so the UI hides that column.
 */
final class QueuesData
{
    public function __construct(
        private readonly MetricsPublisher $metrics,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * @return array{queues: list<array<string, mixed>>}
     */
    public function get(): array
    {
        $queue = StreamQueueResolver::make();
        $queues = [];

        $names = array_map(strval(...), array_keys((array) config('torque.streams', [])));

        // Framework pause state (queue:pause / queue:pause --all); one cache
        // round-trip for all names. A broken cache store must not 500 the
        // dashboard, so degrade to "nothing paused".
        try {
            $paused = array_flip(app('queue')->getPausedQueues(Torque::CONNECTION, $names));
        } catch (\Throwable) {
            $paused = [];
        }

        $startOfDay = now()->startOfDay()->getTimestamp();

        foreach ($names as $name) {
            // Minute retention covers a full day, so "today" is exact rather
            // than an hourly approximation.
            $today = $this->metrics->totalsSince($startOfDay, $name);

            $queues[] = [
                'name' => $name,
                'pending' => $queue->pendingSize($name),
                'delayed' => $queue->delayedSize($name),
                'reserved' => $queue->reservedSize($name),
                'processedToday' => $today['processed'],
                'failedToday' => $today['failed'],
                'throughput' => round(MetricsPublisher::perMinuteRate(
                    $this->metrics->minuteBuckets(5, queue: $name),
                    5,
                ), 1),
                'wait' => null,
                'history' => [],
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
