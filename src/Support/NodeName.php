<?php

declare(strict_types=1);

namespace Webpatser\Torque\Support;

use Illuminate\Container\Container;

/**
 * The name this machine runs under in the fleet.
 *
 * Resolved from an explicit option (`--node`), then the `torque.node` config
 * (TORQUE_NODE), then the hostname. A name is letters, digits, dots,
 * underscores and dashes, at most 48 of them. An explicit name is validated
 * strictly and {@see resolve()} throws for anything else so `torque:start`
 * can refuse. The hostname fallback never throws: it goes through
 * {@see fromHostname()}, which keeps a valid hostname unchanged and turns any
 * other into a valid, deterministic name, so single-host installs with long
 * or unusual hostnames keep starting without TORQUE_NODE.
 *
 * A master or worker sets its name once at boot with {@see setCurrent()};
 * everything else in that process reads {@see current()}.
 */
final class NodeName
{
    public const string PATTERN = '/^[A-Za-z0-9._-]{1,48}$/D';

    public const int MAX_LENGTH = 48;

    /** The hostname when the system reports none. */
    public const string UNKNOWN = 'unknown';

    private static ?string $current = null;

    /** Length of the hostname prefix kept when a long hostname is shortened. */
    private const int HOSTNAME_PREFIX_LENGTH = 39;

    /** Hex characters of the hostname hash appended when it is shortened. */
    private const int HOSTNAME_HASH_LENGTH = 8;

    /**
     * The node name from the option, else the `node` key of the given torque
     * config (the application's `torque` config when null), else the hostname
     * normalised by {@see fromHostname()}. Blank values count as unset.
     *
     * @param  array<string, mixed>|null  $config
     *
     * @throws \InvalidArgumentException When an explicit name (option or config) is not valid.
     */
    public static function resolve(?string $option = null, ?array $config = null): string
    {
        $explicit = self::explicit($option, $config);

        return $explicit !== null ? self::validate($explicit) : self::fromHostname(self::hostname());
    }

    /**
     * A valid node name for a hostname; never throws.
     *
     * A hostname that is already valid is returned unchanged, so existing
     * per-host history keys stay identical. Otherwise every character outside
     * `[A-Za-z0-9._-]` becomes a dash, and a result longer than 48 becomes its
     * first 39 characters, a dash and the first 8 hex characters of the
     * SHA-256 of the original hostname, so distinct long hostnames stay
     * distinct. An empty hostname becomes {@see UNKNOWN}.
     */
    public static function fromHostname(string $hostname): string
    {
        if ($hostname === '') {
            return self::UNKNOWN;
        }

        if (self::isValid($hostname)) {
            return $hostname;
        }

        $clean = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $hostname);

        if (strlen($clean) <= self::MAX_LENGTH) {
            return $clean;
        }

        return substr($clean, 0, self::HOSTNAME_PREFIX_LENGTH)
            .'-'
            .substr(hash('sha256', $hostname), 0, self::HOSTNAME_HASH_LENGTH);
    }

    /**
     * The name itself when valid.
     *
     * @throws \InvalidArgumentException When it is not.
     */
    public static function validate(string $name): string
    {
        if (! self::isValid($name)) {
            throw new \InvalidArgumentException(
                "Invalid Torque node name [{$name}]: use letters, digits, dots, underscores and dashes, at most "
                .self::MAX_LENGTH.' characters. Set TORQUE_NODE or pass --node.',
            );
        }

        return $name;
    }

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    /**
     * This machine's hostname, {@see UNKNOWN} when the system reports none.
     */
    public static function hostname(): string
    {
        $host = gethostname();

        return $host === false || $host === '' ? self::UNKNOWN : $host;
    }

    /**
     * Fix this process's node name. Setting the same name again is a no-op;
     * a different one is a bug in the caller.
     *
     * @throws \InvalidArgumentException When the name is not valid.
     * @throws \LogicException When a different name was set before.
     */
    public static function setCurrent(string $name): string
    {
        self::validate($name);

        if (self::$current !== null && self::$current !== $name) {
            throw new \LogicException('The Torque node name is already set to ['.self::$current."], cannot change it to [{$name}].");
        }

        return self::$current = $name;
    }

    /**
     * This process's node name: the one set at boot, otherwise the same name
     * {@see resolve()} gives without an option. Never throws: outside a master
     * or worker (a web request dispatching a job) an invalid configured name,
     * which `torque:start` would refuse, goes through {@see fromHostname()}
     * as well, and the hostname fallback uses that same normalisation, so a
     * web process and its master agree on the name.
     */
    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $explicit = self::explicit(null, null);

        if ($explicit !== null) {
            return self::isValid($explicit) ? $explicit : self::fromHostname($explicit);
        }

        return self::fromHostname(self::hostname());
    }

    public static function hasCurrent(): bool
    {
        return self::$current !== null;
    }

    /**
     * @internal Tests only.
     */
    public static function resetForTesting(): void
    {
        self::$current = null;
    }

    /**
     * The trimmed explicit name from the option or config, null when both are blank.
     *
     * @param  array<string, mixed>|null  $config
     */
    private static function explicit(?string $option, ?array $config): ?string
    {
        $config ??= self::applicationConfig();

        foreach ([$option, Narrow::nullableString($config['node'] ?? null)] as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * The application's `torque` config, empty outside a booted application.
     *
     * @return array<string, mixed>
     */
    private static function applicationConfig(): array
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return [];
        }

        return Narrow::map($container->make('config')->get('torque'));
    }
}
