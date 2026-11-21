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
 * Picks the event loop driver a Torque process runs on.
 *
 * The master and every worker are processes of their own: the master forks and
 * immediately execs `artisan torque:worker`, so a worker starts on a fresh PHP
 * image with no loop state from the master. Each one therefore installs its
 * driver with {@see Loop::install()} during its own setup. Nothing here calls
 * `Loop::afterFork()`, which is only for a child that keeps running the parent's
 * code (the long-wait alert steps do that, see TorqueLongWaitAlertCommand).
 *
 * The environment variable, when set in the process environment, takes
 * precedence over the cached configuration. `FLEDGE_EVENT_LOOP` (`auto`, `poll`
 * or `select`) is the rollback switch an operator reaches for during an
 * incident, so it must work without a config rebuild: a non-empty value from
 * `getenv()` wins over `torque.event_loop`, which itself reads the same variable
 * and only differs once `config:cache` has frozen an older value. The callers in
 * production pass {@see environment()}; the methods default to the config alone.
 */
final class EventLoopDriver
{
    /** The variable the rest of Fledge reads too. */
    public const string ENV = DriverFactory::ENV;

    /** `FLEDGE_EVENT_LOOP` from this process's environment, null when unset or empty. */
    public static function environment(): ?string
    {
        $value = getenv(self::ENV);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The configured name, `auto` when unset or empty.
     *
     * @param  array<array-key, mixed>  $config  The `torque` config.
     * @param  string|null  $environment  {@see environment()}: wins when not null.
     */
    public static function configured(array $config, ?string $environment = null): string
    {
        if ($environment !== null) {
            return $environment;
        }

        $name = trim(Narrow::string($config['event_loop'] ?? null, 'auto'));

        return $name === '' ? 'auto' : $name;
    }

    /**
     * Which setting decided, for the log line that names it.
     *
     * @param  string|null  $environment  {@see environment()}.
     */
    public static function source(?string $environment): string
    {
        return $environment !== null
            ? self::ENV.' from the process environment'
            : 'torque.event_loop';
    }

    /**
     * The driver the configured name resolves to here: `poll` or `select`.
     *
     * @param  array<array-key, mixed>  $config  The `torque` config.
     * @param  string|null  $environment  {@see environment()}: wins when not null.
     *
     * @throws \InvalidArgumentException For a name other than auto, poll or select.
     * @throws UnsupportedFeatureException For `poll` where neither kqueue nor epoll exists.
     */
    public static function resolve(array $config, ?string $environment = null): string
    {
        return DriverFactory::resolve(self::configured($config, $environment));
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
     * @param  string|null  $environment  {@see environment()}: wins when not null.
     * @return string The driver the loop runs on: `poll`, `select`, or a class name.
     *
     * @throws \InvalidArgumentException For a name other than auto, poll or select.
     * @throws UnsupportedFeatureException For `poll` where neither kqueue nor epoll exists.
     */
    public static function install(array $config, \Closure $warn, ?string $environment = null): string
    {
        $resolved = self::resolve($config, $environment);

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
