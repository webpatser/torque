<?php

declare(strict_types=1);

namespace Webpatser\Torque\Control;

use Fledge\Async\Redis\RedisClient;
use Webpatser\Torque\Support\Narrow;

/**
 * Fleet-wide leader lease in `{prefix}leader`.
 *
 * A master acquires it with `SET NX EX`, keeps it with a compare-and-PEXPIRE
 * and gives it up with a compare-and-delete, so a master that lost the lease
 * (it expired and another took it) can never extend or remove the new
 * holder's lease. Whoever is leader is read back validated: a value that is
 * not a master id reads as no leader.
 */
final class LeaderLease
{
    public const int TTL_SECONDS = 15;

    private const string LUA_PEXPIRE_IF_EQUAL = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('PEXPIRE', KEYS[1], ARGV[2])
end
return 0
LUA;

    private const string LUA_DELETE_IF_EQUAL = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix,
        private readonly int $ttlSeconds = self::TTL_SECONDS,
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException("Lease TTL must be at least 1 second, got [{$ttlSeconds}].");
        }
    }

    public function key(): string
    {
        return $this->prefix.'leader';
    }

    /**
     * Take the lease when nobody holds it. False when another master (or this
     * one) already holds it; use {@see renew()} or {@see maintain()} to keep it.
     */
    public function acquire(string $masterId): bool
    {
        self::assertMasterId($masterId);

        return $this->redis->execute('SET', $this->key(), $masterId, 'NX', 'EX', (string) $this->ttlSeconds) !== null;
    }

    /**
     * Extend the lease, only while this master still holds it.
     */
    public function renew(string $masterId): bool
    {
        self::assertMasterId($masterId);

        return Narrow::int($this->redis->execute(
            'EVAL',
            self::LUA_PEXPIRE_IF_EQUAL,
            '1',
            $this->key(),
            $masterId,
            (string) ($this->ttlSeconds * 1000),
        )) === 1;
    }

    /**
     * Renew the lease when held, otherwise try to acquire it. True when this
     * master is leader afterwards. Meant for the master's maintenance tick.
     */
    public function maintain(string $masterId): bool
    {
        return $this->renew($masterId) || $this->acquire($masterId);
    }

    /**
     * Give up the lease, only while this master still holds it.
     */
    public function release(string $masterId): bool
    {
        self::assertMasterId($masterId);

        return Narrow::int($this->redis->execute(
            'EVAL',
            self::LUA_DELETE_IF_EQUAL,
            '1',
            $this->key(),
            $masterId,
        )) === 1;
    }

    /**
     * The current leader's master id, or null when none holds a valid lease.
     */
    #[\NoDiscard]
    public function leader(): ?string
    {
        $value = Narrow::nullableString($this->redis->execute('GET', $this->key()));

        return $value !== null && MasterRegistry::isValidMasterId($value) ? $value : null;
    }

    #[\NoDiscard]
    public function isLeader(string $masterId): bool
    {
        return $this->leader() === $masterId;
    }

    private static function assertMasterId(string $masterId): void
    {
        if (! MasterRegistry::isValidMasterId($masterId)) {
            throw new \InvalidArgumentException("Invalid master id [{$masterId}].");
        }
    }
}
