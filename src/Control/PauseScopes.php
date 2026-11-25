<?php

declare(strict_types=1);

namespace Webpatser\Torque\Control;

use Fledge\Async\Redis\RedisClient;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Worker\WorkerProcess;

/**
 * Scoped pauses and the per-master drain key.
 *
 * Next to the global `{prefix}paused` switch, an operator can pause one node
 * or one queue. Both live as fields of a single HASH, `{prefix}pauses`:
 * `node:{node}` and `queue:{queue}`, each holding the epoch it was set at and
 * no TTL, because a pause is state only an operator lifts. A worker reads its
 * own node field and the fields for its queues in one HMGET.
 *
 * A reload drains one master's fleet through `{prefix}drain:{masterId}`, so a
 * drain never touches the global switch and two masters on one host never
 * share a key. Masters from before this key wrote `drain:<pid>` into
 * `{prefix}paused`; {@see decide()} still honours that during a rolling deploy.
 *
 * Every command touches one key, which keeps this valid on Redis Cluster.
 */
final class PauseScopes
{
    private const string NODE_FIELD = 'node:';

    private const string QUEUE_FIELD = 'queue:';

    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix,
    ) {}

    /**
     * The HASH holding every node and queue pause.
     */
    public static function key(string $prefix): string
    {
        return $prefix.'pauses';
    }

    /**
     * The drain key of one master. The master writes it and its workers read
     * it, so both build the name here.
     */
    public static function drainKey(string $prefix, string $masterId): string
    {
        return $prefix.'drain:'.$masterId;
    }

    public function pauseNode(string $node, ?int $now = null): void
    {
        $this->redis->execute('HSET', self::key($this->prefix), self::NODE_FIELD.$node, (string) ($now ?? time()));
    }

    /**
     * @return bool whether the node was paused
     */
    public function resumeNode(string $node): bool
    {
        return Narrow::int($this->redis->execute('HDEL', self::key($this->prefix), self::NODE_FIELD.$node)) > 0;
    }

    public function pauseQueue(string $queue, ?int $now = null): void
    {
        $this->redis->execute('HSET', self::key($this->prefix), self::QUEUE_FIELD.$queue, (string) ($now ?? time()));
    }

    /**
     * @return bool whether the queue was paused
     */
    public function resumeQueue(string $queue): bool
    {
        return Narrow::int($this->redis->execute('HDEL', self::key($this->prefix), self::QUEUE_FIELD.$queue)) > 0;
    }

    /**
     * Every scoped pause, by name, with the epoch it was set at. Fields that
     * are neither a node nor a queue are skipped.
     *
     * @return array{nodes: array<string, int>, queues: array<string, int>}
     */
    #[\NoDiscard]
    public function all(): array
    {
        $nodes = [];
        $queues = [];

        foreach (Narrow::pairs($this->redis->execute('HGETALL', self::key($this->prefix))) as $field => $value) {
            $field = (string) $field;

            if (str_starts_with($field, self::NODE_FIELD) && $field !== self::NODE_FIELD) {
                $nodes[substr($field, strlen(self::NODE_FIELD))] = Narrow::int($value);
            } elseif (str_starts_with($field, self::QUEUE_FIELD) && $field !== self::QUEUE_FIELD) {
                $queues[substr($field, strlen(self::QUEUE_FIELD))] = Narrow::int($value);
            }
        }

        ksort($nodes);
        ksort($queues);

        return ['nodes' => $nodes, 'queues' => $queues];
    }

    /**
     * Lift every node and queue pause. The global switch is not touched.
     *
     * @return int the number of scopes that were paused
     */
    public function clearAll(): int
    {
        $count = Narrow::int($this->redis->execute('HLEN', self::key($this->prefix)));
        $this->redis->execute('DEL', self::key($this->prefix));

        return $count;
    }

    /**
     * What one worker needs to know, in a single HMGET: whether its node is
     * paused and which of its queues are.
     *
     * @param  list<string>  $queues
     * @return array{nodePaused: bool, pausedQueues: list<string>}
     */
    #[\NoDiscard]
    public function workerView(string $node, array $queues): array
    {
        $queues = array_values(array_unique($queues));
        $fields = [self::NODE_FIELD.$node, ...array_map(static fn (string $queue): string => self::QUEUE_FIELD.$queue, $queues)];
        $values = Narrow::list($this->redis->execute('HMGET', self::key($this->prefix), ...$fields));

        $pausedQueues = [];

        foreach ($queues as $i => $queue) {
            if (($values[$i + 1] ?? null) !== null) {
                $pausedQueues[] = $queue;
            }
        }

        return [
            'nodePaused' => ($values[0] ?? null) !== null,
            'pausedQueues' => $pausedQueues,
        ];
    }

    /**
     * The value of one master's drain key, null when it is not draining.
     */
    #[\NoDiscard]
    public function drainValue(string $masterId): ?string
    {
        return Narrow::nullableString($this->redis->execute('GET', self::drainKey($this->prefix, $masterId)));
    }

    /**
     * Combine every pause source into one worker's decision. Static and pure
     * for unit testing.
     *
     * The worker pauses fully for a deliberate global pause (a TTL-less
     * `{prefix}paused`), a pause of its node, its own master's drain key, or a
     * legacy `drain:<pid>` in `{prefix}paused` naming its parent. The global
     * value is judged on its own, so a drain never masks a global pause, and a
     * legacy drain of another master leaves this worker alone, as
     * {@see WorkerProcess::shouldPauseFor()} does.
     * `$globalTtl` uses Redis semantics: -2 key missing, -1 no expiry, >0
     * seconds remaining.
     *
     * Queue pauses never pause the worker fully here; they come back as a
     * list for the caller to merge with its other paused queues.
     *
     * @param  list<string>  $pausedQueues
     * @return array{pauseFully: bool, pausedQueues: list<string>}
     */
    #[\NoDiscard]
    public static function decide(
        ?string $globalValue,
        int $globalTtl,
        bool $nodePaused,
        array $pausedQueues,
        ?string $drainValue,
        int $parentPid,
    ): array {
        $globalPaused = $globalValue !== null && $globalTtl === -1;
        $legacyDrain = $globalValue !== null && $globalTtl !== -2 && $globalTtl !== -1
            && $globalValue === 'drain:'.$parentPid;

        return [
            'pauseFully' => $globalPaused || $legacyDrain || $nodePaused || $drainValue !== null,
            'pausedQueues' => array_values(array_unique($pausedQueues)),
        ];
    }
}
