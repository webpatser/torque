<?php

declare(strict_types=1);

namespace Webpatser\Torque\Control;

use Fledge\Async\Redis\RedisClient;
use Webpatser\Torque\Support\Narrow;

/**
 * The fleet's list of live masters.
 *
 * Every master publishes a record on its heartbeat: a HASH
 * `{prefix}master:{masterId}` (EX {@see RECORD_TTL_SECONDS}) and its score in
 * the ZSET `{prefix}masters` (masterId to heartbeat epoch, EX
 * {@see INDEX_TTL_SECONDS}). Each write prunes index entries older than
 * {@see STALE_AFTER_SECONDS}, so a master that died without cleaning up drops
 * out within one heartbeat of any other master.
 *
 * Everything read back is untrusted: ids that are not master ids are skipped,
 * a record whose node or PID does not validate reads as missing, the other
 * fields are narrowed to their type, and a missing or invalid `groups` field
 * reads as `[]`.
 *
 * @phpstan-type GroupShape array{queues: list<string>, workers: int, target: int, min: int, max: int, slots: int}
 * @phpstan-type RecordInput array{node: string, pid: int, started_at: int, version: string, supervised: bool, queues: list<string>, workers: int, slots: int, state: string, groups?: array<string, GroupShape>}
 * @phpstan-type Record array{master_id: string, node: string, pid: int, started_at: int, version: string, supervised: bool, queues: list<string>, workers: int, slots: int, state: string, groups: array<string, GroupShape>, heartbeat: ?int}
 */
final class MasterRegistry
{
    public const int RECORD_TTL_SECONDS = 30;

    public const int INDEX_TTL_SECONDS = 60;

    public const int STALE_AFTER_SECONDS = 30;

    /** Node name rule shared with `NodeName`. */
    public const string NODE_PATTERN = '/^[A-Za-z0-9._-]{1,48}$/D';

    /** `{node}-{pid}-{hex8}`. */
    public const string MASTER_ID_PATTERN = '/^[A-Za-z0-9._-]{1,48}-[1-9][0-9]{0,9}-[0-9a-f]{8}$/D';

    public const string GROUP_NAME_PATTERN = '/^[a-z0-9_-]{1,32}$/D';

    private const string STATE_PATTERN = '/^[a-z_]{1,32}$/D';

    private const string QUEUE_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/D';

    private const string VERSION_PATTERN = '/^[A-Za-z0-9.+_-]{1,64}$/D';

    /** Upper bound on the stored `groups` JSON; anything larger reads as `[]`. */
    private const int MAX_GROUPS_BYTES = 65536;

    private const int MAX_GROUPS = 64;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param  (\Closure(): int)|null  $clock  Epoch seconds, `time()` by default.
     */
    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public static function isValidMasterId(string $masterId): bool
    {
        return preg_match(self::MASTER_ID_PATTERN, $masterId) === 1;
    }

    public function indexKey(): string
    {
        return $this->prefix.'masters';
    }

    public function recordKey(string $masterId): string
    {
        return $this->prefix.'master:'.$masterId;
    }

    /**
     * Write or refresh this master's record and its index entry.
     *
     * The hash is written before the index so a reader that finds the id also
     * finds the record. A record published without `groups` drops a field
     * left by an earlier publish.
     *
     * @param  RecordInput  $record
     *
     * @throws \InvalidArgumentException When the id, node or PID does not validate.
     */
    public function publish(string $masterId, array $record): void
    {
        if (! self::isValidMasterId($masterId)) {
            throw new \InvalidArgumentException("Invalid master id [{$masterId}].");
        }

        if (preg_match(self::NODE_PATTERN, $record['node']) !== 1) {
            throw new \InvalidArgumentException("Invalid node name [{$record['node']}].");
        }

        if ($record['pid'] < 1) {
            throw new \InvalidArgumentException("Invalid PID [{$record['pid']}].");
        }

        $now = ($this->clock)();
        $key = $this->recordKey($masterId);

        $fields = [
            'node' => $record['node'],
            'pid' => (string) $record['pid'],
            'started_at' => (string) $record['started_at'],
            'version' => $record['version'],
            'supervised' => $record['supervised'] ? '1' : '0',
            'queues' => implode(',', $record['queues']),
            'workers' => (string) $record['workers'],
            'slots' => (string) $record['slots'],
            'state' => $record['state'],
        ];

        if (isset($record['groups'])) {
            $fields['groups'] = $record['groups'] === [] ? '{}' : json_encode($record['groups'], JSON_THROW_ON_ERROR);
        } else {
            $this->redis->execute('HDEL', $key, 'groups');
        }

        $args = [];

        foreach ($fields as $field => $value) {
            $args[] = $field;
            $args[] = $value;
        }

        $this->redis->execute('HSET', $key, ...$args);
        $this->redis->execute('EXPIRE', $key, (string) self::RECORD_TTL_SECONDS);

        $index = $this->indexKey();
        $this->redis->execute('ZADD', $index, (string) $now, $masterId);
        $this->redis->execute('ZREMRANGEBYSCORE', $index, '-inf', '('.($now - self::STALE_AFTER_SECONDS));
        $this->redis->execute('EXPIRE', $index, (string) self::INDEX_TTL_SECONDS);
    }

    /**
     * Every master with a heartbeat in the last {@see STALE_AFTER_SECONDS}
     * whose record is still present and valid, ordered by master id.
     *
     * @return list<Record>
     */
    #[\NoDiscard]
    public function all(): array
    {
        $now = ($this->clock)();
        $reply = $this->redis->execute(
            'ZRANGEBYSCORE',
            $this->indexKey(),
            (string) ($now - self::STALE_AFTER_SECONDS),
            '+inf',
            'WITHSCORES',
        );

        $records = [];

        foreach (Narrow::pairs($reply) as $masterId => $score) {
            $masterId = (string) $masterId;

            if (! self::isValidMasterId($masterId)) {
                continue;
            }

            $record = $this->readRecord($masterId, Narrow::nullableInt($score));

            if ($record !== null) {
                $records[$masterId] = $record;
            }
        }

        ksort($records, SORT_STRING);

        return array_values($records);
    }

    /**
     * One master's record, or null when it is missing, expired or invalid.
     * The heartbeat comes from the index and is null when the id is not
     * listed there.
     *
     * @return Record|null
     */
    #[\NoDiscard]
    public function find(string $masterId): ?array
    {
        if (! self::isValidMasterId($masterId)) {
            return null;
        }

        $score = $this->redis->execute('ZSCORE', $this->indexKey(), $masterId);

        return $this->readRecord($masterId, Narrow::nullableInt($score));
    }

    /**
     * Drop this master's record and index entry, as on a clean exit.
     */
    public function remove(string $masterId): void
    {
        if (! self::isValidMasterId($masterId)) {
            return;
        }

        $this->redis->execute('ZREM', $this->indexKey(), $masterId);
        $this->redis->execute('DEL', $this->recordKey($masterId));
    }

    /**
     * @return Record|null
     */
    private function readRecord(string $masterId, ?int $heartbeat): ?array
    {
        $fields = Narrow::pairs($this->redis->execute('HGETALL', $this->recordKey($masterId)));

        if ($fields === []) {
            return null;
        }

        $node = Narrow::string($fields['node'] ?? null);
        $pid = Narrow::string($fields['pid'] ?? null);

        if (preg_match(self::NODE_PATTERN, $node) !== 1 || preg_match('/^[1-9][0-9]{0,9}$/D', $pid) !== 1) {
            return null;
        }

        $version = Narrow::string($fields['version'] ?? null);
        $state = Narrow::string($fields['state'] ?? null);

        return [
            'master_id' => $masterId,
            'node' => $node,
            'pid' => (int) $pid,
            'started_at' => self::nonNegativeInt($fields['started_at'] ?? null),
            'version' => preg_match(self::VERSION_PATTERN, $version) === 1 ? $version : 'unknown',
            'supervised' => Narrow::string($fields['supervised'] ?? null) === '1',
            'queues' => self::queues(Narrow::string($fields['queues'] ?? null)),
            'workers' => self::nonNegativeInt($fields['workers'] ?? null),
            'slots' => self::nonNegativeInt($fields['slots'] ?? null),
            'state' => preg_match(self::STATE_PATTERN, $state) === 1 ? $state : 'unknown',
            'groups' => self::decodeGroups($fields['groups'] ?? null),
            'heartbeat' => $heartbeat,
        ];
    }

    /**
     * Decode the `groups` field. Anything that is not exactly the published
     * shape reads as `[]` as a whole, never as a partial map.
     *
     * @return array<string, GroupShape>
     */
    public static function decodeGroups(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_GROUPS_BYTES) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded) || count($decoded) > self::MAX_GROUPS || ($decoded !== [] && array_is_list($decoded))) {
            return [];
        }

        $groups = [];

        foreach ($decoded as $name => $group) {
            if (! is_string($name) || preg_match(self::GROUP_NAME_PATTERN, $name) !== 1 || ! is_array($group)) {
                return [];
            }

            $queues = $group['queues'] ?? null;

            if (! is_array($queues) || ! array_is_list($queues)) {
                return [];
            }

            foreach ($queues as $queue) {
                if (! is_string($queue) || preg_match(self::QUEUE_PATTERN, $queue) !== 1) {
                    return [];
                }
            }

            $workers = $group['workers'] ?? null;
            $target = $group['target'] ?? null;
            $min = $group['min'] ?? null;
            $max = $group['max'] ?? null;
            $slots = $group['slots'] ?? null;

            if (
                ! is_int($workers) || $workers < 0 || ! is_int($target) || $target < 0
                || ! is_int($min) || $min < 0 || ! is_int($max) || $max < 0 || ! is_int($slots) || $slots < 0
            ) {
                return [];
            }

            $groups[$name] = [
                'queues' => $queues,
                'workers' => $workers,
                'target' => $target,
                'min' => $min,
                'max' => $max,
                'slots' => $slots,
            ];
        }

        return $groups;
    }

    private static function nonNegativeInt(mixed $value): int
    {
        $value = Narrow::string($value);

        return preg_match('/^[0-9]{1,18}$/D', $value) === 1 ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    private static function queues(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            explode(',', $raw),
            static fn (string $queue): bool => preg_match(self::QUEUE_PATTERN, $queue) === 1,
        ));
    }
}
