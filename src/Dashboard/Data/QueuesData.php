<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Data;

use Webpatser\Torque\Support\StreamQueueResolver;

/**
 * Per-stream depth read-model for the queues screen.
 *
 * Throughput / wait / processed-today have no collector yet and are returned as
 * `null`; the UI hides those columns.
 */
final class QueuesData
{
    /**
     * @return array{queues: list<array<string, mixed>>}
     */
    public function get(): array
    {
        $queue = StreamQueueResolver::make();
        $queues = [];

        foreach (array_keys((array) config('torque.streams', [])) as $name) {
            $name = (string) $name;

            $queues[] = [
                'name' => $name,
                'pending' => $queue->pendingSize($name),
                'delayed' => $queue->delayedSize($name),
                'reserved' => $queue->reservedSize($name),
                'processedToday' => null,
                'throughput' => null,
                'wait' => null,
                'history' => [],
                'paused' => false,
            ];
        }

        return ['queues' => $queues];
    }
}
