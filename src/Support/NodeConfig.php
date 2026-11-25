<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

/**
 * Per-node settings from the `nodes` config.
 *
 * A node name matches the block under its exact name first, otherwise the first
 * fnmatch pattern in declaration order. That block is merged over the flat
 * config: `autoscale` deeply, every other allowed key replaced. CLI options are
 * applied by the caller on top of the result.
 *
 * A node block may split the node into worker groups. {@see groups()} turns a
 * resolved config into the list of groups to run, with one implicit group named
 * `default` when none are configured.
 *
 * Pure: every method takes the `torque` config array and throws
 * \InvalidArgumentException for a block `torque:start` must refuse.
 */
final class NodeConfig
{
    /** Keys a node block may set. */
    public const array NODE_KEYS = [
        'queues', 'workers', 'slots_per_worker', 'autoscale',
        'max_jobs_per_worker', 'max_worker_lifetime', 'memory_limit', 'groups',
    ];

    /** Keys only the flat config may set. */
    public const array GLOBAL_KEYS = ['streams', 'redis', 'waits', 'dead_letter', 'consumer_group'];

    /** Keys a group block may set. */
    public const array GROUP_KEYS = ['queues', 'workers', 'slots_per_worker', 'autoscale'];

    public const string GROUP_NAME_PATTERN = '/^[a-z0-9_-]{1,32}$/D';

    public const string DEFAULT_GROUP = 'default';

    /** The flat defaults torque:start falls back to. */
    public const int DEFAULT_WORKERS = 4;

    public const int DEFAULT_SLOTS_PER_WORKER = 50;

    /**
     * The `nodes` key that applies to a node: its exact name, else the first
     * matching fnmatch pattern in declaration order, else null.
     *
     * @param  array<array-key, mixed>  $nodes
     */
    public static function match(string $node, array $nodes): ?string
    {
        if (array_key_exists($node, $nodes)) {
            return $node;
        }

        foreach (array_keys($nodes) as $pattern) {
            if (fnmatch((string) $pattern, $node)) {
                return (string) $pattern;
            }
        }

        return null;
    }

    /**
     * The flat config with the node's block merged over it, plus `node` set to
     * the node name. Without a matching block the flat config comes back as it is.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException For an invalid `nodes` config or node block.
     */
    public static function resolve(array $config, string $node): array
    {
        $nodes = $config['nodes'] ?? [];

        if (! is_array($nodes)) {
            throw new \InvalidArgumentException('torque.nodes must be an array keyed by node name or pattern.');
        }

        $resolved = $config;
        $resolved['node'] = $node;

        $pattern = self::match($node, $nodes);

        if ($pattern === null) {
            return $resolved;
        }

        $path = "torque.nodes.{$pattern}";
        $block = $nodes[$pattern];

        if (! is_array($block)) {
            throw new \InvalidArgumentException("{$path} must be an array.");
        }

        $streams = self::streamNames($config);

        foreach ($block as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::GLOBAL_KEYS, true)) {
                throw new \InvalidArgumentException("{$path}.{$key} is global: set it at the top level of the torque config, not per node.");
            }

            if (! in_array($key, self::NODE_KEYS, true)) {
                throw new \InvalidArgumentException("{$path}.{$key} is not a per-node setting. Allowed: ".implode(', ', self::NODE_KEYS).'.');
            }

            $resolved[$key] = match ($key) {
                'queues' => self::queues($value, $streams, "{$path}.queues"),
                'autoscale' => array_replace_recursive(Narrow::map($config['autoscale'] ?? null), self::autoscale($value, "{$path}.autoscale")),
                'groups' => self::validateGroups($value, $streams, "{$path}.groups"),
                default => $value,
            };
        }

        return $resolved;
    }

    /**
     * The worker groups of a resolved config, in declaration order. Each group
     * inherits `workers`, `slots_per_worker` and `autoscale` (deeply) from the
     * resolved config. Without groups, one group named `default` over the
     * resolved `queues`, else the stream names, else `default`.
     *
     * @param  array<string, mixed>  $resolved
     * @return list<array{name: string, queues: list<string>, workers: int, slots_per_worker: int, autoscale: array<string, mixed>}>
     *
     * @throws \InvalidArgumentException For an invalid `groups` block.
     */
    public static function groups(array $resolved): array
    {
        $workers = Narrow::int($resolved['workers'] ?? null, self::DEFAULT_WORKERS);
        $slots = Narrow::int($resolved['slots_per_worker'] ?? null, self::DEFAULT_SLOTS_PER_WORKER);
        $autoscale = Narrow::map($resolved['autoscale'] ?? null);
        $raw = $resolved['groups'] ?? null;

        if ($raw === null || $raw === []) {
            return [[
                'name' => self::DEFAULT_GROUP,
                'queues' => self::defaultQueues($resolved),
                'workers' => $workers,
                'slots_per_worker' => $slots,
                'autoscale' => $autoscale,
            ]];
        }

        $groups = [];

        foreach (self::validateGroups($raw, self::streamNames($resolved), 'torque.groups') as $name => $group) {
            $groups[] = [
                'name' => $name,
                'queues' => $group['queues'],
                'workers' => Narrow::int($group['workers'] ?? null, $workers),
                'slots_per_worker' => Narrow::int($group['slots_per_worker'] ?? null, $slots),
                'autoscale' => Narrow::map(array_replace_recursive($autoscale, Narrow::map($group['autoscale'] ?? null))),
            ];
        }

        return $groups;
    }

    /**
     * @param  list<string>  $streams
     * @return array<string, array{queues: list<string>, workers?: mixed, slots_per_worker?: mixed, autoscale?: array<string, mixed>}>
     */
    private static function validateGroups(mixed $groups, array $streams, string $path): array
    {
        // Keys make names unique; a list has none, so every group must be keyed.
        if (! is_array($groups) || ($groups !== [] && array_is_list($groups))) {
            throw new \InvalidArgumentException("{$path} must be an array keyed by unique group names.");
        }

        $validated = [];

        foreach ($groups as $name => $group) {
            $name = (string) $name;

            if (preg_match(self::GROUP_NAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException(
                    "{$path} has an invalid group name [{$name}]: use lowercase letters, digits, underscores and dashes, at most 32.",
                );
            }

            if (! is_array($group)) {
                throw new \InvalidArgumentException("{$path}.{$name} must be an array.");
            }

            foreach (array_keys($group) as $key) {
                if (! in_array($key, self::GROUP_KEYS, true)) {
                    throw new \InvalidArgumentException("{$path}.{$name}.{$key} is not a group setting. Allowed: ".implode(', ', self::GROUP_KEYS).'.');
                }
            }

            if (! array_key_exists('queues', $group)) {
                throw new \InvalidArgumentException("{$path}.{$name}.queues is required.");
            }

            $entry = $group;
            $entry['queues'] = self::queues($group['queues'], $streams, "{$path}.{$name}.queues");

            if (array_key_exists('autoscale', $group)) {
                $entry['autoscale'] = self::autoscale($group['autoscale'], "{$path}.{$name}.autoscale");
            }

            /** @var array{queues: list<string>, workers?: mixed, slots_per_worker?: mixed, autoscale?: array<string, mixed>} $entry */
            $validated[$name] = $entry;
        }

        return $validated;
    }

    /**
     * A non-empty list of stream names, duplicates dropped.
     *
     * @param  list<string>  $streams
     * @return list<string>
     */
    private static function queues(mixed $queues, array $streams, string $path): array
    {
        if (! is_array($queues) || $queues === []) {
            throw new \InvalidArgumentException("{$path} must be a non-empty list of stream names.");
        }

        $names = [];

        foreach ($queues as $queue) {
            if (! is_string($queue) || ! in_array($queue, $streams, true)) {
                $label = is_scalar($queue) ? (string) $queue : get_debug_type($queue);

                throw new \InvalidArgumentException(
                    "{$path} lists [{$label}], which is not a key of torque.streams (".implode(', ', $streams).').',
                );
            }

            $names[] = $queue;
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, mixed>
     */
    private static function autoscale(mixed $autoscale, string $path): array
    {
        if (! is_array($autoscale)) {
            throw new \InvalidArgumentException("{$path} must be an array.");
        }

        return Narrow::map($autoscale);
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return list<string>
     */
    private static function defaultQueues(array $resolved): array
    {
        $queues = array_values(array_filter(
            Narrow::list($resolved['queues'] ?? null),
            static fn (mixed $queue): bool => is_string($queue) && $queue !== '',
        ));

        if ($queues !== []) {
            /** @var list<string> $queues */
            return $queues;
        }

        return self::streamNames($resolved) ?: ['default'];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function streamNames(array $config): array
    {
        return array_map(strval(...), array_keys(Narrow::map($config['streams'] ?? null)));
    }
}
