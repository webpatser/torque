<?php

declare(strict_types=1);

namespace Webpatser\Torque\Redis;

use Composer\InstalledVersions;
use Fledge\Async\Redis\Connection\SocketRedisConnector;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Async\Stream\DnsSocketConnector;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Runs Torque's data upgrade steps once per installed version.
 *
 * Deploying a new Torque over an older one can leave keys behind that the new
 * code no longer writes or expires. Rather than asking every site to remember
 * a cleanup command, the master runs the steps for its version on the first
 * start after the deploy and records the version in `{prefix}version`.
 *
 * Steps are an ordered `version => callable` map: on an upgrade every step
 * newer than the stored version runs, so a site jumping several releases gets
 * all of them. Each step returns per-category counts, which are merged into
 * one report and logged. This release owes no steps, so the default map is
 * empty.
 *
 * Steps are only kept for releases at or above {@see self::MINIMUM_STORED_VERSION}.
 * Data older than that is refused by {@see self::assertUpgradePath()}, the one
 * thing here that is fatal on purpose. Everything else is best-effort: the
 * caller wraps the run in a try/catch, and a Redis outage simply means the
 * upgrade retries on the next master start.
 */
final class UpgradeRunner
{
    /** Suffix of the key holding the last-upgraded version. */
    public const string VERSION_KEY_SUFFIX = 'version';

    /** Stored for any install without a comparable release version. */
    public const string DEV_VERSION = 'dev';

    /** The oldest stored release this version can upgrade from. */
    public const string MINIMUM_STORED_VERSION = '1.4.0';

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
        private readonly \Closure $logger,
        ?string $currentVersion = null,
        ?array $steps = null,
        private readonly ?RedisClient $client = null,
    ) {
        $this->currentVersion = $currentVersion ?? self::installedVersion();
        $this->steps = $steps ?? [];
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
        return $this->readVersion($this->redis());
    }

    /**
     * Refuse a stored release older than this version can upgrade from.
     *
     * Passes when nothing is recorded, when the stored value is `dev` or has no
     * release number to compare, and when Redis cannot be reached: an outage
     * must not stop a start, and without a stored version there is nothing to
     * refuse. An installed dev checkout gets no exemption, it owes the removed
     * steps just the same. It connects once, see {@see self::gateClient()}.
     *
     * @throws \DomainException With the operator-facing message, only on a refusal.
     */
    public function assertUpgradePath(): void
    {
        try {
            $stored = $this->readVersion($this->client ?? $this->gateClient());
        } catch (\Throwable $e) {
            ($this->logger)("Upgrade path check skipped ({$e->getMessage()}).");

            return;
        }

        if (self::isBelowMinimum($stored)) {
            throw new \DomainException($this->refusalMessage((string) $stored));
        }
    }

    /**
     * Run every upgrade step newer than the stored version.
     *
     * The stored version is never overwritten while it is below the minimum,
     * even when {@see self::assertUpgradePath()} passed during a Redis outage
     * that has ended since: the next start still has to refuse.
     *
     * @return array{ran: bool, from: string|null, to: string, counts: array<string, int>}
     */
    public function run(): array
    {
        $current = $this->currentVersion;
        $stored = $this->storedVersion();
        $skipped = ['ran' => false, 'from' => $stored, 'to' => $current, 'counts' => []];

        if (self::isBelowMinimum($stored)) {
            $minimum = self::MINIMUM_STORED_VERSION;

            ($this->logger)("Torque data in Redis was last upgraded by {$stored}, below {$minimum}: not recording this version, and the next start refuses. ".$this->upgradeAdvice());

            return $skipped;
        }

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
     * last. Jumping 1.4.0 to 2.2.0 therefore runs every step in between.
     */
    private function stepApplies(string $stepVersion, ?string $stored, string $current): bool
    {
        if ($stored === null || $stored === self::DEV_VERSION || $current === self::DEV_VERSION) {
            return true;
        }

        return version_compare($stepVersion, $stored, '>');
    }

    /**
     * Compare only the leading release number, padded to three segments, so
     * the suffix cannot decide: `1.4.0-rc.1`, `1.4.0.1` and `1.4.x-dev` have
     * reached 1.4, while `1.3.9.9` and `1.3.x-dev` have not. A pre-release of
     * the minimum counts as the minimum, since it carries everything the
     * removed steps did. A value with no leading number cannot be called older
     * and passes, like `dev`.
     */
    private static function isBelowMinimum(?string $stored): bool
    {
        if ($stored === null || preg_match('/^v?(\d+(?:\.\d+)*)/', trim($stored), $matches) !== 1) {
            return false;
        }

        $release = implode('.', array_pad(explode('.', $matches[1]), 3, '0'));

        return version_compare($release, self::MINIMUM_STORED_VERSION, '<');
    }

    private function refusalMessage(string $stored): string
    {
        $minimum = self::MINIMUM_STORED_VERSION;

        return "Refusing to start: Torque data in Redis was last upgraded by {$stored}, below {$minimum}, the oldest release this version upgrades from. "
            .$this->upgradeAdvice();
    }

    private function upgradeAdvice(): string
    {
        $minimum = self::MINIMUM_STORED_VERSION;

        return "Upgrade through 1.4.x first and start a master on it once, or skip the removed upgrade steps with: SET {$this->prefix}version {$minimum}";
    }

    private function readVersion(RedisClient $redis): ?string
    {
        $value = $redis->execute('GET', $this->prefix.self::VERSION_KEY_SUFFIX);

        return $value === null ? null : (string) $value;
    }

    private function redis(): RedisClient
    {
        return $this->redis ??= $this->client ?? createRedisClient($this->redisUri);
    }

    /**
     * A client for the gate that tries to connect once.
     *
     * The default socket connector makes three attempts with 2s and 4s between
     * them, and the gate runs first on every start, so it would add those seconds
     * to a start during an outage. That retry sits below the link, which is why
     * the socket connector is the part replaced here: `maxReconnectAttempts` on
     * the link would only repeat the whole sequence.
     */
    private function gateClient(): RedisClient
    {
        $config = RedisConfig::fromUri($this->redisUri);
        $context = (new ConnectContext)->withConnectTimeout($config->getTimeout());

        if (($tlsContext = $config->getTlsContext()) !== null) {
            $context = $context->withTlsContext($tlsContext);
        }

        return createRedisClient($config, new SocketRedisConnector(
            $config->getConnectUri(),
            $context,
            new DnsSocketConnector,
            tcpKeepalive: $config->usesTcpKeepalive(),
        ));
    }
}
