<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;

/**
 * Moves config keys renamed in Torque 2.0 to their new names, for one major
 * version. Torque 3.0 refuses to boot on them instead.
 *
 * Moved keys are recorded in `torque._legacy_keys`. That entry is plain config,
 * so `config:cache` writes it out and the master can still warn after the
 * process that saw the published file is long gone.
 */
final class LegacyConfig
{
    /**
     * Old key to new key, relative to `torque`. Only `torque.block_for` is here:
     * a queue connection's own `block_for` keeps its name.
     *
     * @var array<string, string>
     */
    public const array RENAMES = [
        'coroutines_per_worker' => 'slots_per_worker',
        'block_for' => 'poll_interval_ms',
        'redis.read_timeout' => 'redis.read_timeout_seconds',
        'takeover_ready_timeout' => 'takeover_ready_timeout_seconds',
        'job_streams' => 'job_events',
        'job_limits' => 'honour_job_limits',
    ];

    /**
     * Move every old key present in the config over its new key.
     *
     * The old key wins: the package does not ship it, so it can only come from
     * the application, while the new key may just be the shipped default. A key
     * that is absent changes nothing, and neither does a second pass, so this is
     * safe on a config that `config:cache` already wrote out moved.
     *
     * With `$published`, only keys in that array move, and the old key stays
     * next to the new one. That is the interim while the shipped file still has
     * the old keys and every reader still reads them; see the service provider.
     *
     * @param  array<string, mixed>|null  $published
     */
    public static function migrate(Repository $config, ?array $published = null): void
    {
        $torque = (array) $config->get('torque', []);
        $recorded = (array) ($torque['_legacy_keys'] ?? []);

        foreach (self::RENAMES as $old => $new) {
            if (! Arr::has($published ?? $torque, $old)) {
                continue;
            }

            Arr::set($torque, $new, self::convert($old, Arr::get($torque, $old)));
            $recorded[$old] = $new;

            if ($published === null) {
                Arr::forget($torque, $old);
            }
        }

        if ($recorded !== []) {
            $torque['_legacy_keys'] = $recorded;
        }

        $config->set('torque', $torque);
    }

    /**
     * The master's boot warning for the recorded keys, null when there are none.
     *
     * @param  array<string, mixed>  $config  Merged Torque config.
     */
    public static function warning(array $config): ?string
    {
        $recorded = (array) ($config['_legacy_keys'] ?? []);

        if ($recorded === []) {
            return null;
        }

        $moves = implode(', ', array_map(
            static fn (string $old, string $new): string => "{$old} -> {$new}",
            array_keys($recorded),
            $recorded,
        ));

        return "WARN Renamed config keys in use: {$moves}. Their values still apply; rename them in config/torque.php, as Torque 3.0 makes this a boot failure.";
    }

    /**
     * `job_limits` was only ever compared to 'stream', so anything else, an
     * unknown string or a typo included, honoured job limits. That stays true.
     */
    private static function convert(string $old, mixed $value): mixed
    {
        return $old === 'job_limits' ? $value !== 'stream' : $value;
    }
}
