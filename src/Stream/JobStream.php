<?php

declare(strict_types=1);

namespace Webpatser\Torque\Stream;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Read a job's event stream.
 *
 * Used by the CLI (`torque:tail`), dashboard, and SSE endpoints to follow
 * a job's lifecycle events in real-time.
 */
final class JobStream
{
    private ?\Fledge\Async\Redis\RedisClient $redis = null;

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
     * List currently active (non-terminal) jobs.
     *
     * Reads uuids from the `{prefix}jobs:active` sorted-set index (newest
     * first) and fetches the last event from each job's stream. Falls back to
     * scanning `{prefix}job:*` keys when the index is empty (e.g. for legacy
     * installs without the recorder running).
     *
     * @param  int  $limit  Maximum number of jobs to return.
     * @return array<int, array{uuid: string, type: string, data: array<string, string>}>
     */
    public function activeJobs(int $limit = 100): array
    {
        $redis = $this->getRedis();
        $indexKey = $this->prefix . 'jobs:active';

        $uuids = $redis->execute('ZREVRANGE', $indexKey, '0', (string) ($limit - 1));

        if (! is_array($uuids) || $uuids === []) {
            return $this->scanActiveJobs($limit);
        }

        $active = [];

        foreach ($uuids as $uuid) {
            $uuid = (string) $uuid;
            $key = $this->prefix . 'job:' . $uuid;
            $last = $redis->execute('XREVRANGE', $key, '+', '-', 'COUNT', '1');

            if (! is_array($last) || $last === []) {
                // Stale index entry: stream expired/was purged. Drop it.
                $redis->execute('ZREM', $indexKey, $uuid);

                continue;
            }

            $entry = $this->parseEntries($last)[0] ?? null;

            if ($entry === null) {
                continue;
            }

            if (in_array($entry['type'], ['completed', 'failed', 'dead_lettered'], true)) {
                // Index drift: job reached terminal after ZADD but before the
                // ZREM in the recorder. Self-heal by removing it here.
                $redis->execute('ZREM', $indexKey, $uuid);

                continue;
            }

            $active[] = [
                'uuid' => $uuid,
                'type' => $entry['type'],
                'data' => $entry['data'],
            ];
        }

        return $active;
    }

    /**
     * List recent jobs (both active and completed).
     *
     * Reads uuids from the `{prefix}jobs:recent` sorted-set index, newest
     * first, then fetches first + last events for each. Falls back to a SCAN
     * over `{prefix}job:*` when the index is empty.
     *
     * @param  string|null  $status  Filter: 'active', 'completed', 'failed', or null for all.
     * @param  int  $limit  Maximum number of jobs to return.
     * @return array<int, array{uuid: string, first_event: array, last_event: array}>
     */
    public function recentJobs(?string $status = null, int $limit = 100): array
    {
        $redis = $this->getRedis();
        $indexKey = $this->prefix . 'jobs:recent';

        // Pull an oversized window so post-filtering by status can still
        // produce a full page for statuses that match only some of the jobs.
        $window = max($limit * 3, 300);
        $uuids = $redis->execute('ZREVRANGE', $indexKey, '0', (string) ($window - 1));

        if (! is_array($uuids) || $uuids === []) {
            return $this->scanRecentJobs($status, $limit);
        }

        $terminalTypes = ['completed', 'failed', 'dead_lettered'];
        $jobs = [];

        foreach ($uuids as $uuid) {
            $uuid = (string) $uuid;
            $key = $this->prefix . 'job:' . $uuid;

            $first = $redis->execute('XRANGE', $key, '-', '+', 'COUNT', '1');
            $last = $redis->execute('XREVRANGE', $key, '+', '-', 'COUNT', '1');

            if (! is_array($first) || $first === [] || ! is_array($last) || $last === []) {
                $redis->execute('ZREM', $indexKey, $uuid);

                continue;
            }

            $firstEntry = $this->parseEntries($first)[0] ?? null;
            $lastEntry = $this->parseEntries($last)[0] ?? null;

            if ($firstEntry === null || $lastEntry === null) {
                continue;
            }

            $isTerminal = in_array($lastEntry['type'], $terminalTypes, true);

            if ($status === 'active' && $isTerminal) {
                continue;
            }
            if ($status === 'completed' && $lastEntry['type'] !== 'completed') {
                continue;
            }
            if ($status === 'failed' && ! in_array($lastEntry['type'], ['failed', 'dead_lettered'], true)) {
                continue;
            }

            $jobs[] = [
                'uuid' => $uuid,
                'first_event' => $firstEntry,
                'last_event' => $lastEntry,
            ];

            if (count($jobs) >= $limit) {
                break;
            }
        }

        return $jobs;
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
     * SCAN fallback for `activeJobs()` when the sorted-set index is empty.
     *
     * @return array<int, array{uuid: string, type: string, data: array<string, string>}>
     */
    private function scanActiveJobs(int $limit): array
    {
        $redis = $this->getRedis();
        $pattern = $this->prefix . 'job:*';
        $prefixLen = strlen($this->prefix . 'job:');
        $active = [];
        $cursor = '0';

        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            $cursor = (string) $result[0];
            $keys = is_array($result[1]) ? $result[1] : [];

            foreach ($keys as $key) {
                $key = (string) $key;
                $last = $redis->execute('XREVRANGE', $key, '+', '-', 'COUNT', '1');

                if (! is_array($last) || $last === []) {
                    continue;
                }

                $entry = $this->parseEntries($last)[0] ?? null;

                if ($entry === null) {
                    continue;
                }

                if (in_array($entry['type'], ['completed', 'failed', 'dead_lettered'], true)) {
                    continue;
                }

                $uuid = substr($key, $prefixLen);
                $active[] = [
                    'uuid' => $uuid,
                    'type' => $entry['type'],
                    'data' => $entry['data'],
                ];

                if (count($active) >= $limit) {
                    return $active;
                }
            }
        } while ($cursor !== '0');

        return $active;
    }

    /**
     * SCAN fallback for `recentJobs()` when the sorted-set index is empty.
     *
     * @return array<int, array{uuid: string, first_event: array, last_event: array}>
     */
    private function scanRecentJobs(?string $status, int $limit): array
    {
        $redis = $this->getRedis();
        $pattern = $this->prefix . 'job:*';
        $prefixLen = strlen($this->prefix . 'job:');
        $jobs = [];
        $cursor = '0';
        $terminalTypes = ['completed', 'failed', 'dead_lettered'];

        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            $cursor = (string) $result[0];
            $keys = is_array($result[1]) ? $result[1] : [];

            foreach ($keys as $key) {
                $key = (string) $key;

                $first = $redis->execute('XRANGE', $key, '-', '+', 'COUNT', '1');
                $last = $redis->execute('XREVRANGE', $key, '+', '-', 'COUNT', '1');

                if (! is_array($first) || $first === [] || ! is_array($last) || $last === []) {
                    continue;
                }

                $firstEntry = $this->parseEntries($first)[0] ?? null;
                $lastEntry = $this->parseEntries($last)[0] ?? null;

                if ($firstEntry === null || $lastEntry === null) {
                    continue;
                }

                $isTerminal = in_array($lastEntry['type'], $terminalTypes, true);

                if ($status === 'active' && $isTerminal) {
                    continue;
                }
                if ($status === 'completed' && $lastEntry['type'] !== 'completed') {
                    continue;
                }
                if ($status === 'failed' && ! in_array($lastEntry['type'], ['failed', 'dead_lettered'], true)) {
                    continue;
                }

                $uuid = substr($key, $prefixLen);
                $jobs[] = [
                    'uuid' => $uuid,
                    'first_event' => $firstEntry,
                    'last_event' => $lastEntry,
                ];
            }
        } while ($cursor !== '0');

        usort($jobs, static function (array $a, array $b): int {
            $tsA = (int) ($a['first_event']['data']['timestamp'] ?? 0);
            $tsB = (int) ($b['first_event']['data']['timestamp'] ?? 0);

            return $tsB <=> $tsA;
        });

        return array_slice($jobs, 0, $limit);
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

    private function getRedis(): \Fledge\Async\Redis\RedisClient
    {
        return $this->redis ??= createRedisClient($this->redisUri);
    }
}
