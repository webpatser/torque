<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

use Webpatser\Torque\Queue\StreamConnector;
use Webpatser\Torque\Queue\StreamQueue;

/**
 * Builds a {@see StreamQueue} straight from the `torque` config block.
 *
 * The dashboard inspects queue sizes without going through a configured
 * `queue.connections.*` entry, so it mirrors {@see StreamConnector}
 * and reads prefix/consumer-group/cluster settings from `config('torque')`.
 * Per-queue size lookups pass the queue name explicitly, so a single instance
 * can inspect every configured stream.
 */
final class StreamQueueResolver
{
    /**
     * Build a StreamQueue from the torque config (single source of truth).
     */
    public static function make(): StreamQueue
    {
        $config = Narrow::map(config('torque', []));
        $redis = Narrow::map($config['redis'] ?? null);

        return new StreamQueue(
            redisUri: Narrow::string($redis['uri'] ?? null, 'redis://127.0.0.1:6379'),
            default: 'default',
            prefix: Narrow::string($redis['prefix'] ?? null, 'torque:'),
            consumerGroup: Narrow::string($config['consumer_group'] ?? null, 'torque'),
            cluster: Narrow::bool($redis['cluster'] ?? null),
            serializer: Narrow::string($config['serializer'] ?? null, 'json'),
        );
    }
}
