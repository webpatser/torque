<?php

declare(strict_types=1);

namespace Webpatser\Torque\Queue;

use Illuminate\Queue\Connectors\ConnectorInterface;
use Webpatser\Torque\Support\Narrow;

final class StreamConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection and return the StreamQueue instance.
     *
     * Config keys:
     *  - redis_uri       (string)  Redis URI, e.g. "redis://127.0.0.1:6379"
     *  - queue           (string)  Default queue/stream name
     *  - retry_after     (int)     Seconds before a job is retried
     *  - block_for       (int)     Milliseconds StreamQueue::pop() blocks in XREADGROUP (BLOCK) waiting for a job
     *  - prefix          (string)  Key prefix for all Redis keys
     *  - consumer_group  (string)  Consumer group name
     *  - serializer      (string)  Wire format: 'json' (default) or 'igbinary'
     *
     * @param  array<string, mixed>  $config
     */
    #[\Override]
    public function connect(array $config): StreamQueue
    {
        // Use torque.php config as the single source of truth for prefix
        // and consumer group, so dispatch and workers always agree.
        $torqueConfig = Narrow::map(config('torque', []));
        $redisConfig = Narrow::map($torqueConfig['redis'] ?? null);

        return new StreamQueue(
            redisUri: Narrow::string($config['redis_uri'] ?? $redisConfig['uri'] ?? null, 'redis://127.0.0.1:6379'),
            default: Narrow::string($config['queue'] ?? null, 'default'),
            retryAfter: Narrow::int($config['retry_after'] ?? null, 90),
            blockFor: Narrow::int($config['block_for'] ?? null, 2000),
            prefix: Narrow::string($redisConfig['prefix'] ?? $config['prefix'] ?? null, 'torque:'),
            consumerGroup: Narrow::string($torqueConfig['consumer_group'] ?? $config['consumer_group'] ?? null, 'torque'),
            cluster: Narrow::bool($redisConfig['cluster'] ?? null),
            serializer: Narrow::string($torqueConfig['serializer'] ?? $config['serializer'] ?? null, 'json'),
        );
    }
}
