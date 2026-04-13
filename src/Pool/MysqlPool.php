<?php

declare(strict_types=1);

namespace Webpatser\Torque\Pool;

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\Mysql\MysqlStatement;

/**
 * Thin wrapper around amphp/mysql's built-in connection pool.
 *
 * Unlike RedisPool (which wraps our generic ConnectionPool), amphp/mysql
 * already ships a full-featured MysqlConnectionPool with idle management,
 * prepared-statement caching, and automatic reconnection. This class simply
 * exposes a consistent Torque pool interface on top of it.
 *
 * Usage:
 *
 *     $pool = new MysqlPool('host=localhost user=root password=secret db=myapp');
 *
 *     // Direct query
 *     $result = $pool->query('SELECT * FROM jobs WHERE status = ?', ['pending']);
 *
 *     // Prepared statement
 *     $stmt = $pool->prepare('INSERT INTO logs (message) VALUES (?)');
 *     $stmt->execute(['Job completed']);
 *
 *     // Scoped access to the underlying pool
 *     $pool->use(fn (MysqlConnectionPool $db) => $db->query('TRUNCATE TABLE cache'));
 *
 * @requires amphp/mysql ^3.0
 */
final class MysqlPool
{
    private MysqlConnectionPool $inner;

    /**
     * @param string $dsn            DSN string accepted by MysqlConfig::fromString(),
     *                               e.g. "host=localhost user=root password=secret db=myapp".
     * @param int    $maxConnections Maximum number of concurrent MySQL connections.
     */
    public function __construct(
        public private(set) string $dsn,
        public private(set) int $maxConnections = 20,
    ) {
        $config = MysqlConfig::fromString($this->dsn);

        $this->inner = new MysqlConnectionPool($config, $this->maxConnections);
    }

    /**
     * Execute a SQL query with optional parameters.
     *
     * Parameters are bound positionally — use `?` placeholders.
     *
     *     $result = $pool->query('SELECT * FROM users WHERE id = ?', [42]);
     */
    #[\NoDiscard]
    public function query(string $sql, array $params = []): MysqlResult
    {
        return $this->inner->query($sql, $params);
    }

    /**
     * Prepare a statement for repeated execution.
     *
     * amphp/mysql caches prepared statements per-connection, so this is
     * efficient even when called in a loop.
     *
     *     $stmt = $pool->prepare('INSERT INTO events (type, payload) VALUES (?, ?)');
     *     $stmt->execute(['job.completed', $json]);
     */
    #[\NoDiscard]
    public function prepare(string $sql): MysqlStatement
    {
        return $this->inner->prepare($sql);
    }

    /**
     * Execute a prepared statement in a single call.
     *
     * Shorthand for prepare + execute when you only need to run it once.
     *
     *     $result = $pool->execute('UPDATE jobs SET status = ? WHERE id = ?', ['done', $id]);
     */
    #[\NoDiscard]
    public function execute(string $sql, array $params = []): MysqlResult
    {
        return $this->inner->execute($sql, $params);
    }

    /**
     * Execute a callback with scoped access to the underlying MysqlConnectionPool.
     *
     * Useful for transactions or operations that need the full amphp pool API:
     *
     *     $pool->use(function (MysqlConnectionPool $db) {
     *         $tx = $db->beginTransaction();
     *         $tx->query('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, $from]);
     *         $tx->query('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, $to]);
     *         $tx->commit();
     *     });
     *
     * @template T
     *
     * @param \Closure(MysqlConnectionPool): T $callback  Receives the inner amphp pool.
     *
     * @return T
     */
    public function use(\Closure $callback): mixed
    {
        return $callback($this->inner);
    }

    /**
     * Close all connections in the pool.
     */
    public function close(): void
    {
        $this->inner->close();
    }
}
