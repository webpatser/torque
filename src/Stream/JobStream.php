<?php

declare(strict_types=1);

namespace Webpatser\Torque\Stream;

use function Amp\Redis\createRedisClient;

/**
 * Read a job's event stream.
 *
 * Used by the CLI (`torque:tail`), dashboard, and SSE endpoints to follow
 * a job's lifecycle events in real-time.
 */
final class JobStream
{
    private ?\Amp\Redis\RedisClient $redis = null;

    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix = 'torque:',
    ) {}

    /**
     * Read all events recorded so far for a job.
     *
     * @return array<int, array{id: string, type: string, data: array<string, string>}>
     */
    public function events(string $uuid): array
    {
        $redis = $this->getRedis();
        $key = $this->prefix . 'job:' . $uuid;

        $result = $redis->execute('XRANGE', $key, '-', '+');

        if (! is_array($result) || $result === []) {
            return [];
        }

        return $this->parseEntries($result);
    }

    /**
     * Tail a job's event stream, yielding events as they arrive.
     *
     * Blocks until the job reaches a terminal state or the timeout expires.
     *
     * @return \Generator<int, array{id: string, type: string, data: array<string, string>}>
     */
    public function tail(string $uuid, float $timeout = 30.0): \Generator
    {
        $redis = $this->getRedis();
        $key = $this->prefix . 'job:' . $uuid;
        $lastId = '0-0';
        $deadline = microtime(true) + $timeout;
        $blockMs = 1000;

        while (microtime(true) < $deadline) {
            $result = $redis->execute('XREAD', 'COUNT', '100', 'BLOCK', (string) $blockMs, 'STREAMS', $key, $lastId);

            if ($result === null || ! is_array($result)) {
                continue;
            }

            $streamData = $result[0] ?? null;

            if ($streamData === null || ! is_array($streamData[1] ?? null) || $streamData[1] === []) {
                continue;
            }

            $entries = $this->parseEntries($streamData[1]);

            foreach ($entries as $entry) {
                $lastId = $entry['id'];
                yield $entry;

                // Stop tailing on terminal events.
                if (in_array($entry['type'], ['completed', 'failed', 'dead_lettered'], true)) {
                    return;
                }
            }
        }
    }

    /**
     * Check if a job has reached a terminal state.
     */
    public function isFinished(string $uuid): bool
    {
        $events = $this->events($uuid);

        foreach (array_reverse($events) as $event) {
            if (in_array($event['type'], ['completed', 'failed', 'dead_lettered'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse raw XRANGE/XREAD entries into structured arrays.
     *
     * @return array<int, array{id: string, type: string, data: array<string, string>}>
     */
    private function parseEntries(array $rawEntries): array
    {
        $entries = [];

        foreach ($rawEntries as $entry) {
            $id = (string) $entry[0];
            $fields = $entry[1];
            $data = [];

            for ($i = 0, $count = count($fields); $i < $count; $i += 2) {
                $data[(string) $fields[$i]] = (string) $fields[$i + 1];
            }

            $entries[] = [
                'id' => $id,
                'type' => $data['type'] ?? 'unknown',
                'data' => $data,
            ];
        }

        return $entries;
    }

    private function getRedis(): \Amp\Redis\RedisClient
    {
        return $this->redis ??= createRedisClient($this->redisUri);
    }
}
