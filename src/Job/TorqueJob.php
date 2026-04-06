<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for jobs that leverage Torque's async I/O pools.
 *
 * Extends a standard Laravel queueable job with a default connection of
 * "torque" so jobs are automatically dispatched to the Torque worker.
 *
 * Laravel's container injection takes care of providing the async pools
 * into your handle() method — just type-hint what you need:
 *
 *     use Webpatser\Torque\Pool\RedisPool;
 *     use Webpatser\Torque\Pool\HttpPool;
 *     use Webpatser\Torque\Pool\MysqlPool;
 *
 *     class ProcessWebhook extends TorqueJob
 *     {
 *         public function __construct(
 *             private readonly string $url,
 *             private readonly string $payload,
 *         ) {}
 *
 *         public function handle(HttpPool $http, RedisPool $redis): void
 *         {
 *             $response = $http->post($this->url, $this->payload);
 *
 *             $redis->use(fn ($r) => $r->set(
 *                 "webhook:{$this->url}:status",
 *                 (string) $response->getStatus(),
 *             ));
 *         }
 *     }
 *
 * Available pools (registered as singletons by TorqueServiceProvider):
 *
 * - {@see \Webpatser\Torque\Pool\RedisPool}  — async Redis via amphp/redis
 * - {@see \Webpatser\Torque\Pool\MysqlPool}  — async MySQL via amphp/mysql
 * - {@see \Webpatser\Torque\Pool\HttpPool}   — concurrency-limited HTTP via amphp/http-client
 */
abstract class TorqueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The queue connection this job should be dispatched to.
     *
     * Defaults to "torque" so the job is handled by the Torque worker
     * with its fiber-based concurrency and async pool injection.
     */
    public string $connection = 'torque';
}
