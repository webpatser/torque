<?php

declare(strict_types=1);

namespace Webpatser\Torque\Redis;

use Composer\InstalledVersions;
use Fledge\Async\Redis\RedisClient;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Runs Torque's data upgrade steps once per installed version.
 *
 * Deploying a new Torque over an older one leaves keys behind that the new
 * code no longer writes or expires (0.15.0 leaked per-job event streams,
 * consumer names, and an uncapped dead-letter stream). Rather than asking
 * every site to remember a cleanup command, the master runs the steps for its
 * version on the first start after the deploy and records the version in
 * `{prefix}version`.
 *
 * Steps are an ordered `version => callable` map: on an upgrade every step
 * newer than the stored version runs, so a site jumping several releases gets
 * all of them. Each step returns per-category counts, which are merged into
 * one report and logged.
 *
 * Nothing here is allowed to be fatal. The caller wraps the run in a
 * try/catch, and a Redis outage simply means the upgrade retries on the next
 * master start.
 */
final class UpgradeRunner
{
    /** Suffix of the key holding the last-upgraded version. */
    public const string VERSION_KEY_SUFFIX = 'version';

    /** Stored for any install without a comparable release version. */
    public const string DEV_VERSION = 'dev';

    private ?RedisClient $redis = null;

    private readonly string $currentVersion;

    /** @var array<string, callable(): array<string, int>> */
    private readonly array $steps;

    /**
     * @param  \Closure(string): void  $logger
     * @param  array<string, callable(): array<string, int>>|null  $steps  Override for testing.
     * @param  RedisClient|null  $client  Reuse an open connection.
     */
    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix,
        private readonly StreamHousekeeper $housekeeper,
        private readonly \Closure $logger,
        ?string $currentVersion = null,
        ?array $steps = null,
        private readonly ?RedisClient $client = null,
    ) {
        $this->currentVersion = $currentVersion ?? self::installedVersion();
        $this->steps = $steps ?? [
            // 0.16.0 is the first release with bounded keys, so it is also the
            // release that has to clean up what earlier versions leaked.
            '0.16.0' => fn (): array => $this->housekeeper->deepClean(),
        ];
    }

    /**
     * Build a runner from a merged Torque config array.
     *
     * @param  array<string, mixed>  $config
     * @param  \Closure(string): void  $logger
     */
    #[\NoDiscard]
    public static function fromConfig(array $config, \Closure $logger, ?string $currentVersion = null): self
    {
        return new self(
            redisUri: (string) ($config['redis']['uri'] ?? 'redis://127.0.0.1:6379'),
            prefix: (string) ($config['redis']['prefix'] ?? 'torque:'),
            housekeeper: StreamHousekeeper::fromConfig($config),
            logger: $logger,
            currentVersion: $currentVersion,
        );
    }

    /**
     * The installed package version, normalized.
     *
     * A leading `v` is stripped, and anything that is not a release number
     * (`dev-main`, a branch alias, an unreadable install) collapses to `dev`,
     * which always re-runs the steps: a developer moving between branches
     * should get the cleanup, not a version comparison that quietly skips it.
     */
    #[\NoDiscard]
    public static function installedVersion(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion('webpatser/torque');
        } catch (\Throwable) {
            $version = null;
        }

        $version = preg_replace('/^v/', '', (string) $version);

        return $version !== null && preg_match('/^\d+\.\d+/', $version) === 1
            ? $version
            : self::DEV_VERSION;
    }

    /**
     * The version recorded by the last successful upgrade, if any.
     */
    #[\NoDiscard]
    public function storedVersion(): ?string
    {
        $value = $this->redis()->execute('GET', $this->prefix.self::VERSION_KEY_SUFFIX);

        return $value === null ? null : (string) $value;
    }

    /**
     * Run every upgrade step newer than the stored version.
     *
     * @return array{ran: bool, from: string|null, to: string, counts: array<string, int>}
     */
    public function run(): array
    {
        $current = $this->currentVersion;
        $stored = $this->storedVersion();
        $skipped = ['ran' => false, 'from' => $stored, 'to' => $current, 'counts' => []];

        if ($current !== self::DEV_VERSION && $stored !== null && $stored !== self::DEV_VERSION) {
            if (version_compare($stored, $current, '>')) {
                ($this->logger)("Torque data version {$stored} is newer than the installed {$current}; leaving it alone.");

                return $skipped;
            }

            if (version_compare($stored, $current, '=')) {
                return $skipped;
            }
        }

        ($this->logger)('Upgrading Torque data from '.($stored ?? 'an unrecorded version')." to {$current}.");

        $counts = [];

        foreach ($this->steps as $version => $step) {
            if (! $this->stepApplies((string) $version, $stored, $current)) {
                continue;
            }

            foreach ($step() as $category => $count) {
                $counts[$category] = ($counts[$category] ?? 0) + (int) $count;
            }
        }

        $this->redis()->execute('SET', $this->prefix.self::VERSION_KEY_SUFFIX, $current);

        ($this->logger)($counts === []
            ? "Torque data is up to date; recorded version {$current}."
            : 'Upgrade cleanup: '.self::describe($counts).'.');

        return ['ran' => true, 'from' => $stored, 'to' => $current, 'counts' => $counts];
    }

    /**
     * Render per-category counts as one readable line.
     *
     * @param  array<string, int>  $counts
     */
    #[\NoDiscard]
    public static function describe(array $counts): string
    {
        $parts = [];

        foreach ($counts as $category => $count) {
            $parts[] = str_replace('_', ' ', (string) $category).': '.number_format((int) $count);
        }

        return implode(', ', $parts);
    }

    /**
     * A step runs when nothing was recorded yet, when either side is a dev
     * install (no meaningful comparison), or when it is newer than what ran
     * last. Jumping 0.15.0 to 0.18.0 therefore runs every step in between.
     */
    private function stepApplies(string $stepVersion, ?string $stored, string $current): bool
    {
        if ($stored === null || $stored === self::DEV_VERSION || $current === self::DEV_VERSION) {
            return true;
        }

        return version_compare($stepVersion, $stored, '>');
    }

    private function redis(): RedisClient
    {
        return $this->redis ??= $this->client ?? createRedisClient($this->redisUri);
    }
}
