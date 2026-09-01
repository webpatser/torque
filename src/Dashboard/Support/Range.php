<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Support;

use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * The dashboard's global time range: 1h, 24h, 7d or 90d.
 *
 * One table for every screen. The range used to live twice (an `Overview`
 * property driving the throughput chart and a `Jobs` property driving the class
 * table), each with its own copy of the bucket maths and its own seg control.
 * It is now a single chrome-level choice remembered in the session, so the
 * whole dashboard shows one window at a time.
 *
 * A range is nothing more than "how many buckets of which rollup tier", which
 * is exactly what {@see MetricsPublisher::series()} and its siblings take.
 */
final class Range
{
    public const string DEFAULT = '1h';

    /**
     * Bucket geometry and copy per range.
     *
     * `minutes` is the range's total length, used to express a rate that means
     * the same thing at 1h as at 90d. `label` is the card-head readout and
     * `short` the topbar trigger, both of which name the unit the buckets are
     * in so a chart is never mistaken for a finer resolution than it has.
     *
     * @var array<string, array{tier: string, count: int, minutes: int, label: string, short: string}>
     */
    private const array RANGES = [
        '1h' => [
            'tier' => MetricsPublisher::TIER_MINUTE,
            'count' => 60,
            'minutes' => 60,
            'label' => 'jobs / minute · last 60 min',
            'short' => 'last 60 min',
        ],
        '24h' => [
            'tier' => MetricsPublisher::TIER_HOUR,
            'count' => 24,
            'minutes' => 1440,
            'label' => 'jobs / hour · last 24 hours',
            'short' => 'last 24 hours',
        ],
        '7d' => [
            'tier' => MetricsPublisher::TIER_HOUR,
            'count' => 168,
            'minutes' => 10080,
            'label' => 'jobs / hour · last 7 days',
            'short' => 'last 7 days',
        ],
        '90d' => [
            'tier' => MetricsPublisher::TIER_DAY,
            'count' => 90,
            'minutes' => 129600,
            'label' => 'jobs / day · last 90 days',
            'short' => 'last 90 days',
        ],
    ];

    private function __construct(
        public readonly string $key,
        public readonly string $tier,
        public readonly int $count,
        public readonly int $minutes,
        public readonly string $label,
        public readonly string $short,
    ) {}

    /**
     * Resolve a range key, falling back to the default rather than erroring.
     *
     * Every read path goes through here, so an unknown key coming from a
     * hand-crafted request or a stale session degrades to the hour view instead
     * of reaching the rollup readers.
     */
    #[\NoDiscard]
    public static function make(?string $key): self
    {
        $key = $key !== null && self::isValid($key) ? $key : self::DEFAULT;
        $range = self::RANGES[$key];

        return new self(
            key: $key,
            tier: $range['tier'],
            count: $range['count'],
            minutes: $range['minutes'],
            label: $range['label'],
            short: $range['short'],
        );
    }

    #[\NoDiscard]
    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::RANGES);
    }

    /**
     * Every range key, in the order the picker lists them.
     *
     * @return list<string>
     */
    #[\NoDiscard]
    public static function keys(): array
    {
        return array_keys(self::RANGES);
    }

    /**
     * The picker's options: key plus the label it reads as.
     *
     * @return list<array{key: string, short: string, label: string}>
     */
    #[\NoDiscard]
    public static function options(): array
    {
        return array_values(array_map(
            static fn (string $key): array => [
                'key' => $key,
                'short' => self::RANGES[$key]['short'],
                'label' => self::RANGES[$key]['label'],
            ],
            self::keys(),
        ));
    }

    /**
     * Epoch the window starts at, for the `totalsSince()` style readers.
     *
     * Aligned to the tier's bucket boundary so the total covers exactly the
     * buckets the series drawn beside it does, rather than half of an extra one.
     */
    #[\NoDiscard]
    public function sinceEpoch(?int $now = null): int
    {
        $now ??= time();
        $seconds = MetricsPublisher::tierSeconds($this->tier);
        $currentBucket = intdiv($now, $seconds) * $seconds;

        return $currentBucket - ($this->count - 1) * $seconds;
    }

    /**
     * Milliseconds since the epoch the window starts at, for stream ids.
     */
    #[\NoDiscard]
    public function sinceMs(?int $now = null): int
    {
        return $this->sinceEpoch($now) * 1000;
    }
}
