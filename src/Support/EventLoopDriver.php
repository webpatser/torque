<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

use Fledge\Loop;
use Fledge\Loop\Driver;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Fledge\Loop\DriverFactory;
use Fledge\Loop\UnsupportedFeatureException;

/**
 * Picks the event loop driver a Torque process runs on, from `torque.event_loop`.
 *
 * The master and every worker are processes of their own: the master forks and
 * immediately execs `artisan torque:worker`, so a worker starts on a fresh PHP
 * image with no loop state from the master. Each one therefore installs its
 * driver with {@see Loop::install()} during its own setup. Nothing here calls
 * `Loop::afterFork()`, which is only for a child that keeps running the parent's
 * code (the long-wait alert steps do that, see TorqueLongWaitAlertCommand).
 *
 * The key reads `FLEDGE_EVENT_LOOP` (`auto`, `poll` or `select`), so a cached
 * config keeps the choice, and one variable steers Torque and the rest of Fledge.
 */
final class EventLoopDriver
{
    /**
     * The configured name, `auto` when unset or empty.
     *
     * @param  array<array-key, mixed>  $config  The `torque` config.
     */
    public static function configured(array $config): string
    {
        $name = trim(Narrow::string($config['event_loop'] ?? null, 'auto'));

        return $name === '' ? 'auto' : $name;
    }

    /**
     * The driver the configured name resolves to here: `poll` or `select`.
     *
     * @param  array<array-key, mixed>  $config  The `torque` config.
     *
     * @throws \InvalidArgumentException For a name other than auto, poll or select.
     * @throws UnsupportedFeatureException For `poll` where neither kqueue nor epoll exists.
     */
    public static function resolve(array $config): string
    {
        return DriverFactory::resolve(self::configured($config));
    }

    /**
     * Install the configured driver as this process's loop, before anything runs on it.
     *
     * When the loop already has callbacks (something during the application's boot
     * used it), {@see Loop::install()} refuses, as swapping would drop that work.
     * The process then keeps the driver it has, and `$warn` is told when that is
     * not the configured one.
     *
     * @param  array<array-key, mixed>  $config  The `torque` config.
     * @param  \Closure(string): void  $warn
     * @return string The driver the loop runs on: `poll`, `select`, or a class name.
     *
     * @throws \InvalidArgumentException For a name other than auto, poll or select.
     * @throws UnsupportedFeatureException For `poll` where neither kqueue nor epoll exists.
     */
    public static function install(array $config, \Closure $warn): string
    {
        $resolved = self::resolve($config);

        try {
            Loop::install($resolved);

            return $resolved;
        } catch (\LogicException $e) {
            $active = self::nameOf(Loop::getDriver());

            if ($active !== $resolved) {
                $warn("event_loop={$resolved} not applied, this process keeps the {$active} driver: {$e->getMessage()}");
            }

            return $active;
        }
    }

    /** `poll`, `select`, or the class of a driver that is neither. */
    public static function nameOf(Driver $driver): string
    {
        return match (true) {
            $driver instanceof PollDriver => 'poll',
            $driver instanceof StreamSelectDriver => 'select',
            default => $driver::class,
        };
    }
}
