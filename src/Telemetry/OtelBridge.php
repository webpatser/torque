<?php

declare(strict_types=1);

namespace Webpatser\Torque\Telemetry;

use Illuminate\Container\Container;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Throwable;
use Webpatser\Torque\Metrics\WorkerSnapshot;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Torque;

/**
 * Optional OpenTelemetry metrics for Torque, exported through fledge-apm.
 *
 * Torque depends on one thing only: an `OpenTelemetry\API\Metrics\MeterProviderInterface`
 * bound in the container, which fledge-apm does while APM and its metrics are on.
 * {@see make()} returns null unless `torque.metrics.otel` is on, the interface exists
 * and that binding is there, so with any of the three missing nothing is built and
 * the callers pay one null check. The other touch points are fledge-apm's
 * `Metrics::markLongRunning('torque')` and, in the master only,
 * `Metrics::collectDue()`, both called behind class_exists and is_callable
 * checks. A worker marks itself from inside its event loop and fledge-apm's
 * timer exports for it; the master exports from its maintenance tick
 * ({@see collectMaster()}).
 *
 * Which process reports what. Every Torque process has its own
 * `service.instance.id` on the resource, so a per-process gauge sums correctly
 * across a fleet; a fleet-wide number reported by every worker would not.
 *
 * - A worker reports its own slots: `torque.worker.slots.active`, `.limit` and
 *   `.usage`, read from the snapshot its publish timer already builds
 *   ({@see recordWorker()}). The bridge never takes a snapshot itself, because
 *   taking one resets the collector's peak.
 * - The master reports the fleet: `torque.master.workers`, `torque.queue.circuit.open`
 *   (0 or 1 per queue with a breaker, read from Redis, as the breaker is shared by
 *   the fleet and its open state ends by expiry without an event) and
 *   `torque.queue.long_waits` (one per long-wait alert the master starts,
 *   including the 300 s retry of a failed alert and the 900 s repeat of a wait
 *   that continues, so it counts alerts, not distinct long waits; the
 *   LongWaitDetected event itself runs in a short-lived child that never exports).
 *
 * Gauges are observable and read the last value handed in at collect time, the
 * conservative choice under delta temporality. Attributes are kept to
 * `laravel.queue.connection` and, for per-queue instruments,
 * `messaging.destination.name`: never a job id, pid or slot index.
 */
final class OtelBridge
{
    /** Instrumentation scope of every Torque instrument. */
    public const string SCOPE = 'torque';

    /** `fledge.process.role` Torque processes announce. */
    public const string ROLE = 'torque';

    public const string SLOTS_ACTIVE = 'torque.worker.slots.active';

    public const string SLOTS_LIMIT = 'torque.worker.slots.limit';

    public const string SLOTS_USAGE = 'torque.worker.slots.usage';

    public const string MASTER_WORKERS = 'torque.master.workers';

    public const string CIRCUIT_OPEN = 'torque.queue.circuit.open';

    public const string LONG_WAITS = 'torque.queue.long_waits';

    /** The attribute keys any Torque instrument may carry. */
    public const array ATTRIBUTES = ['laravel.queue.connection', 'messaging.destination.name'];

    /**
     * fledge-apm's metrics facade, by name: it is an optional package. A static
     * property rather than a constant so a test can stand a fixture class in for it.
     */
    private static string $apmMetrics = 'Fledge\\Apm\\Metrics\\Metrics';

    private ?WorkerSnapshot $snapshot = null;

    private ?int $workers = null;

    /** @var array<array-key, bool> Queue => whether its breaker is open. */
    private array $circuits = [];

    private ?CounterInterface $longWaits = null;

    private bool $workerObserved = false;

    private bool $masterObserved = false;

    private bool $marked = false;

    private function __construct(private readonly MeterInterface $meter) {}

    /**
     * The bridge for this process, or null when `torque.metrics.otel` is off, the
     * OpenTelemetry API is not installed, or no MeterProvider is bound (fledge-apm
     * missing, or its metrics off).
     *
     * @param  array<string, mixed>  $config  The merged Torque config.
     */
    public static function make(array $config, ?Container $container = null): ?self
    {
        if (! self::configured($config) || ! interface_exists(MeterProviderInterface::class)) {
            return null;
        }

        try {
            $container ??= Container::getInstance();

            if (! $container->bound(MeterProviderInterface::class)) {
                return null;
            }

            return new self($container->make(MeterProviderInterface::class)->getMeter(self::SCOPE));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether `metrics.otel` is on in this Torque config.
     *
     * @param  array<string, mixed>  $config
     */
    public static function configured(array $config): bool
    {
        return Narrow::bool(Narrow::map($config['metrics'] ?? null)['otel'] ?? null);
    }

    /**
     * Worker side: the snapshot the publish timer just built. Called from inside
     * the event loop, so marking the process long-running starts fledge-apm's
     * collect timer here.
     */
    public function recordWorker(WorkerSnapshot $snapshot): void
    {
        $this->snapshot = $snapshot;

        if (! $this->workerObserved) {
            $this->workerObserved = true;
            $this->observeWorker();
        }

        $this->markLongRunning();
    }

    /**
     * Master side: the live worker count and, when read this tick, the breaker
     * state per queue (null keeps the last one).
     *
     * @param  array<array-key, bool>|null  $circuits  Queue => open.
     */
    public function recordMaster(int $workers, ?array $circuits = null): void
    {
        $this->workers = max(0, $workers);

        if ($circuits !== null) {
            $this->circuits = $circuits;
        }

        if (! $this->masterObserved) {
            $this->masterObserved = true;
            $this->observeMaster();
        }
    }

    /**
     * Master side: one long-wait alert started for this queue. A retry of a failed
     * alert and a repeat after the cooldown count again.
     */
    public function recordLongWait(string $queue): void
    {
        try {
            $this->longWaits ??= $this->meter->createCounter(
                self::LONG_WAITS,
                '{alert}',
                'Long-wait alerts started, including retries of a failed alert and repeats for a wait that continues; not distinct long waits',
            );

            $this->longWaits->add(1, self::queueAttributes($queue));
        } catch (Throwable) {
            // Telemetry never costs the master its loop.
        }
    }

    /**
     * Master side, once per maintenance tick: export when an interval is due.
     *
     * The master's monitor loop is synchronous and turns about once a second, too
     * seldom to carry an asynchronous export, so the master never runs
     * fledge-apm's loop timer. The first call calls `collectDue()` once before
     * marking the process long-running, because fledge-apm treats a process as
     * self-driven from its first `collectDue()` and only starts its backlog and
     * loop observers on the mark when it is not; the mark itself starts no timer.
     * Every call then asks fledge-apm's `Metrics::collectDue()` (0.6.0 and later),
     * which collects at most once per export interval and waits for that export,
     * bounded by the OTLP timeouts. Without that method the master's instruments
     * export only at shutdown. Never throws.
     */
    public function collectMaster(): void
    {
        $collect = [self::$apmMetrics, 'collectDue'];
        $callable = class_exists(self::$apmMetrics) && is_callable($collect);

        if (! $this->marked && $callable) {
            $this->collectGuarded($collect);
        }

        $this->markLongRunning();

        if ($callable) {
            $this->collectGuarded($collect);
        }
    }

    /**
     * @param  callable(): mixed  $collect
     */
    private function collectGuarded(callable $collect): void
    {
        try {
            $collect();
        } catch (Throwable) {
            // Telemetry never costs the master its loop.
        }
    }

    /**
     * The SDK keeps a callback bound to an object only as long as that object
     * lives, so the gauges here and in {@see observeMaster()} go with the bridge
     * and no handle needs keeping.
     */
    private function observeWorker(): void
    {
        try {
            $this->meter->batchObserve(
                function (ObserverInterface $active, ObserverInterface $limit, ObserverInterface $usage): void {
                    $snapshot = $this->snapshot;

                    if ($snapshot === null) {
                        return;
                    }

                    $attributes = self::connectionAttributes();

                    $active->observe($snapshot->activeSlots, $attributes);
                    $limit->observe($snapshot->totalSlots, $attributes);
                    $usage->observe($snapshot->slotUsageRatio, $attributes);
                },
                $this->meter->createObservableGauge(self::SLOTS_ACTIVE, '{slot}', 'Coroutine slots busy in this worker, the peak of the last publish interval'),
                $this->meter->createObservableGauge(self::SLOTS_LIMIT, '{slot}', 'Coroutine slots this worker runs'),
                $this->meter->createObservableGauge(self::SLOTS_USAGE, '1', 'Busy slots as a ratio of the limit, 0 to 1'),
            );
        } catch (Throwable) {
            // Telemetry never costs the worker its loop.
        }
    }

    private function observeMaster(): void
    {
        try {
            $this->meter->batchObserve(
                function (ObserverInterface $workers, ObserverInterface $circuit): void {
                    if ($this->workers !== null) {
                        $workers->observe($this->workers, self::connectionAttributes());
                    }

                    // A numeric queue name came back as an int key.
                    foreach ($this->circuits as $queue => $open) {
                        $circuit->observe($open ? 1 : 0, self::queueAttributes((string) $queue));
                    }
                },
                $this->meter->createObservableGauge(self::MASTER_WORKERS, '{worker}', 'Worker processes the master supervises'),
                $this->meter->createObservableGauge(self::CIRCUIT_OPEN, '{circuit}', '1 while the queue\'s circuit breaker is open, else 0'),
            );
        } catch (Throwable) {
            // Telemetry never costs the master its loop.
        }
    }

    /**
     * fledge-apm's `Metrics::markLongRunning('torque')`, once, when fledge-apm is there.
     */
    private function markLongRunning(): void
    {
        if ($this->marked) {
            return;
        }

        $this->marked = true;
        $mark = [self::$apmMetrics, 'markLongRunning'];

        if (! class_exists(self::$apmMetrics) || ! is_callable($mark)) {
            return;
        }

        try {
            $mark(self::ROLE);
        } catch (Throwable) {
            // No export cadence is no reason to fail.
        }
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function connectionAttributes(): array
    {
        return ['laravel.queue.connection' => Torque::CONNECTION];
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function queueAttributes(string $queue): array
    {
        return [
            'laravel.queue.connection' => Torque::CONNECTION,
            'messaging.destination.name' => $queue,
        ];
    }
}
