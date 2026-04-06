<?php

declare(strict_types=1);

namespace Webpatser\Torque\Queue;

use Illuminate\Queue\Connectors\ConnectorInterface;

final class StreamConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection and return the StreamQueue instance.
     *
     * Config keys:
     *  - redis_uri       (string)  Redis URI, e.g. "redis://127.0.0.1:6379"
     *  - queue           (string)  Default queue/stream name
     *  - retry_after     (int)     Seconds before a job is retried
     *  - block_for       (int)     Milliseconds XREADGROUP blocks
     *  - prefix          (string)  Key prefix for all Redis keys
     *  - consumer_group  (string)  Consumer group name
     */
    #[\Override]
    public function connect(array $config): StreamQueue
    {
        return new StreamQueue(
            redisUri: $config['redis_uri'] ?? 'redis://127.0.0.1:6379',
            default: $config['queue'] ?? 'default',
            retryAfter: (int) ($config['retry_after'] ?? 90),
            blockFor: (int) ($config['block_for'] ?? 2000),
            prefix: $config['prefix'] ?? 'torque:',
            consumerGroup: $config['consumer_group'] ?? 'torque',
        );
    }
}
