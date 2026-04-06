<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

/**
 * Per-Fiber isolated state storage.
 *
 * Laravel singletons (auth guard, request instance, config overrides) are shared
 * across all Fibers in a worker process. Two concurrent jobs could interfere with
 * each other. CoroutineContext provides per-Fiber isolation using a WeakMap keyed
 * by the Fiber instance — when a Fiber is garbage collected, its context is
 * automatically cleaned up. No memory leaks.
 *
 * Inspired by Hyperf/Hypervel's coroutine context pattern.
 */
final class CoroutineContext
{
    /** @var \WeakMap<\Fiber, array<string, mixed>> */
    private static \WeakMap $storage;

    public static function init(): void
    {
        self::$storage ??= new \WeakMap();
    }

    /**
     * Set a value in the current Fiber's context.
     */
    public static function set(string $key, mixed $value): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) return; // Not in a Fiber — no isolation needed.

        self::$storage[$fiber] ??= [];
        $data = self::$storage[$fiber];
        $data[$key] = $value;
        self::$storage[$fiber] = $data;
    }

    /**
     * Get a value from the current Fiber's context.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) return $default;

        return self::$storage[$fiber][$key] ?? $default;
    }

    /**
     * Check if a key exists in the current Fiber's context.
     */
    public static function has(string $key): bool
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) return false;

        return isset(self::$storage[$fiber][$key]);
    }

    /**
     * Remove a key from the current Fiber's context.
     */
    public static function forget(string $key): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) return;

        if (isset(self::$storage[$fiber])) {
            $data = self::$storage[$fiber];
            unset($data[$key]);
            self::$storage[$fiber] = $data;
        }
    }

    /**
     * Clear all state for the current Fiber.
     */
    public static function flush(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) return;

        unset(self::$storage[$fiber]);
    }
}
