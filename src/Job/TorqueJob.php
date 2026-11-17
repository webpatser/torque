<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webpatser\Torque\Pool\HttpPool;
use Webpatser\Torque\Pool\MysqlPool;
use Webpatser\Torque\Pool\RedisPool;
use Webpatser\Torque\Torque;

/**
 * Base class for jobs that leverage Torque's async I/O pools.
 *
 * Extends a standard Laravel queueable job with a default connection of
 * "torque" so jobs are automatically dispatched to the Torque worker.
 *
 * Laravel's container injection takes care of providing the async pools
 * into your handle() method; just type-hint what you need:
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
 * The "torque" connection comes from the `#[Connection]` class attribute,
 * which Laravel's bus dispatcher reads through parent classes. Subclasses
 * may declare any constructor without calling the parent, exactly like a
 * plain Laravel job. An explicit `onConnection()` call or a `$connection`
 * property on the subclass still takes precedence.
 *
 * Available pools (registered as singletons by TorqueServiceProvider):
 *
 * - {@see RedisPool}: async Redis via amphp/redis
 * - {@see MysqlPool}: async MySQL via amphp/mysql
 * - {@see HttpPool}: concurrency-limited HTTP via amphp/http-client
 */
#[Connection(Torque::CONNECTION)]
abstract class TorqueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
}
