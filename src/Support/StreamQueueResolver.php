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
        /** @var array<string, mixed> $config */
        $config = config('torque', []);

        return new StreamQueue(
            redisUri: $config['redis']['uri'] ?? 'redis://127.0.0.1:6379',
            default: 'default',
            prefix: $config['redis']['prefix'] ?? 'torque:',
            consumerGroup: $config['consumer_group'] ?? 'torque',
            cluster: (bool) ($config['redis']['cluster'] ?? false),
            serializer: (string) ($config['serializer'] ?? 'json'),
        );
    }
}
