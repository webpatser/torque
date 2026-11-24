<?php

declare(strict_types=1);

use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;
use Webpatser\Torque\Metrics\MetricsCollector;
use Webpatser\Torque\Metrics\WorkerSnapshot;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Telemetry\OtelBridge;

/*
 * The bridge in isolation: a real SDK MeterProvider with an in-memory exporter
 * bound as fledge-apm binds its own. No Redis.
 */

/**
 * Bind a real SDK MeterProvider as `MeterProviderInterface`.
 *
 * @return array{ExportingReader, InMemoryExporter}
 */
function otelBridgeProvider(): array
{
    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    $reader = new ExportingReader($exporter);

    app()->instance(MeterProviderInterface::class, (new MeterProviderBuilder)->addReader($reader)->build());

    return [$reader, $exporter];
}

/**
 * Collect once and return the exported metrics by name.
 *
 * @return array<string, Metric>
 */
function otelBridgeCollect(ExportingReader $reader, InMemoryExporter $exporter): array
{
    $reader->collect();

    $metrics = [];

    foreach ($exporter->collect(reset: true) as $metric) {
        $metrics[$metric->name] = $metric;
    }

    return $metrics;
}

/**
 * @return list<NumberDataPoint>
 */
function otelBridgePoints(Metric $metric): array
{
    $points = [];

    foreach ($metric->data->dataPoints as $point) {
        $points[] = $point;
    }

    return $points;
}

/**
 * @return array<string, mixed>
 */
function otelBridgeConfig(bool $otel): array
{
    return ['metrics' => ['enabled' => true, 'otel' => $otel]];
}

function otelBridgeSnapshot(int $active = 3, int $total = 4): WorkerSnapshot
{
    return new WorkerSnapshot(
        jobsProcessed: 10,
        jobsFailed: 1,
        activeSlots: $active,
        totalSlots: $total,
        averageLatencyMs: 12.5,
        slotUsageRatio: $active / $total,
        memoryBytes: 1024,
        timestamp: time(),
        perQueue: ['default' => [10, 1]],
        perJob: ['App\\Jobs\\Example' => [10, 1, 125.0, 40.0]],
    );
}

/**
 * Plain classes standing in for fledge-apm's `Metrics`, which this repo does not install.
 */
final class OtelBridgeApmFixture
{
    /** @var list<string> */
    public static array $marks = [];

    public static int $collects = 0;

    public static function markLongRunning(string $role): void
    {
        self::$marks[] = $role;
    }

    public static function collectDue(): bool
    {
        self::$collects++;

        return true;
    }
}

final class OtelBridgeApmWithoutCollect
{
    /** @var list<string> */
    public static array $marks = [];

    public static function markLongRunning(string $role): void
    {
        self::$marks[] = $role;
    }
}

final class OtelBridgeApmThrowing
{
    public static function markLongRunning(string $role): void {}

    public static function collectDue(): bool
    {
        throw new RuntimeException('export failed');
    }
}

/**
 * Point the bridge at another class in place of fledge-apm's `Metrics`.
 */
function otelBridgeApm(string $class): void
{
    (new ReflectionProperty(OtelBridge::class, 'apmMetrics'))->setValue(null, $class);
}

afterEach(function () {
    $property = new ReflectionProperty(OtelBridge::class, 'apmMetrics');
    $property->setValue(null, $property->getDefaultValue());

    OtelBridgeApmFixture::$marks = [];
    OtelBridgeApmFixture::$collects = 0;
    OtelBridgeApmWithoutCollect::$marks = [];
});

it('ships metrics.otel off by default', function () {
    expect(config('torque.metrics.otel'))->toBeFalse()
        ->and(OtelBridge::configured(config('torque')))->toBeFalse();
});

it('builds nothing while metrics.otel is off, even with a MeterProvider bound', function () {
    [$reader, $exporter] = otelBridgeProvider();

    expect(OtelBridge::make(otelBridgeConfig(false)))->toBeNull()
        ->and(OtelBridge::make([]))->toBeNull()
        ->and(otelBridgeCollect($reader, $exporter))->toBe([]);
});

it('builds nothing without a bound MeterProvider', function () {
    expect(app()->bound(MeterProviderInterface::class))->toBeFalse()
        ->and(OtelBridge::make(otelBridgeConfig(true)))->toBeNull();
});

it('exposes the slot gauges of the snapshot it is handed', function () {
    [$reader, $exporter] = otelBridgeProvider();
    $bridge = OtelBridge::make(otelBridgeConfig(true));

    expect($bridge)->toBeInstanceOf(OtelBridge::class);

    $bridge->recordWorker(otelBridgeSnapshot(active: 3, total: 4));
    $metrics = otelBridgeCollect($reader, $exporter);

    expect(array_keys($metrics))->toEqualCanonicalizing([
        OtelBridge::SLOTS_ACTIVE,
        OtelBridge::SLOTS_LIMIT,
        OtelBridge::SLOTS_USAGE,
    ]);

    expect($metrics[OtelBridge::SLOTS_ACTIVE]->unit)->toBe('{slot}')
        ->and(otelBridgePoints($metrics[OtelBridge::SLOTS_ACTIVE])[0]->value)->toBe(3)
        ->and($metrics[OtelBridge::SLOTS_LIMIT]->unit)->toBe('{slot}')
        ->and(otelBridgePoints($metrics[OtelBridge::SLOTS_LIMIT])[0]->value)->toBe(4)
        ->and($metrics[OtelBridge::SLOTS_USAGE]->unit)->toBe('1')
        ->and(otelBridgePoints($metrics[OtelBridge::SLOTS_USAGE])[0]->value)->toBe(0.75)
        ->and($metrics[OtelBridge::SLOTS_ACTIVE]->instrumentationScope->getName())->toBe(OtelBridge::SCOPE);

    // The next collect reads the newest snapshot.
    $bridge->recordWorker(otelBridgeSnapshot(active: 1, total: 4));
    $metrics = otelBridgeCollect($reader, $exporter);

    expect(otelBridgePoints($metrics[OtelBridge::SLOTS_ACTIVE])[0]->value)->toBe(1)
        ->and(otelBridgePoints($metrics[OtelBridge::SLOTS_USAGE])[0]->value)->toBe(0.25);
});

it('leaves the collector peak alone: it never takes a snapshot itself', function () {
    [$reader, $exporter] = otelBridgeProvider();
    $bridge = OtelBridge::make(otelBridgeConfig(true));

    $collector = new MetricsCollector(totalSlots: 4);

    // Peak 3, then the publish timer's snapshot resets it to the active 1.
    $collector->recordJobStarted();
    $collector->recordJobStarted();
    $collector->recordJobStarted();
    $collector->recordJobCompleted(5.0);
    $collector->recordJobCompleted(5.0);
    $published = $collector->snapshot();

    // A new peak of 3 builds up before the next publish.
    $collector->recordJobStarted();
    $collector->recordJobStarted();
    $collector->recordJobCompleted(5.0);
    $collector->recordJobCompleted(5.0);

    $bridge->recordWorker($published);
    otelBridgeCollect($reader, $exporter);

    expect($collector->snapshot()->activeSlots)->toBe(3);
});

it('reports the master numbers: workers, circuits per queue and long-wait alerts', function () {
    [$reader, $exporter] = otelBridgeProvider();
    $bridge = OtelBridge::make(otelBridgeConfig(true));

    $bridge->recordMaster(4, ['default' => true, 'emails' => false]);
    $bridge->recordLongWait('default');
    $bridge->recordLongWait('default');
    $metrics = otelBridgeCollect($reader, $exporter);

    expect(array_keys($metrics))->toEqualCanonicalizing([
        OtelBridge::MASTER_WORKERS,
        OtelBridge::CIRCUIT_OPEN,
        OtelBridge::LONG_WAITS,
    ]);

    $circuits = [];

    foreach (otelBridgePoints($metrics[OtelBridge::CIRCUIT_OPEN]) as $point) {
        $circuits[$point->attributes->get('messaging.destination.name')] = $point->value;
    }

    expect($metrics[OtelBridge::MASTER_WORKERS]->unit)->toBe('{worker}')
        ->and(otelBridgePoints($metrics[OtelBridge::MASTER_WORKERS])[0]->value)->toBe(4)
        ->and($metrics[OtelBridge::CIRCUIT_OPEN]->unit)->toBe('{circuit}')
        ->and($circuits)->toBe(['default' => 1, 'emails' => 0])
        ->and($metrics[OtelBridge::LONG_WAITS]->unit)->toBe('{alert}')
        ->and(array_sum(array_map(fn (NumberDataPoint $point) => $point->value, otelBridgePoints($metrics[OtelBridge::LONG_WAITS]))))->toBe(2);

    // A closed breaker drops to 0; a tick without a breaker read keeps the last state.
    $bridge->recordMaster(3, ['default' => false, 'emails' => false]);
    $bridge->recordMaster(3);
    $metrics = otelBridgeCollect($reader, $exporter);

    $circuits = [];

    foreach (otelBridgePoints($metrics[OtelBridge::CIRCUIT_OPEN]) as $point) {
        $circuits[$point->attributes->get('messaging.destination.name')] = $point->value;
    }

    $longWaits = isset($metrics[OtelBridge::LONG_WAITS])
        ? array_sum(array_map(fn (NumberDataPoint $point) => $point->value, otelBridgePoints($metrics[OtelBridge::LONG_WAITS])))
        : 0;

    expect($circuits)->toBe(['default' => 0, 'emails' => 0])
        ->and(otelBridgePoints($metrics[OtelBridge::MASTER_WORKERS])[0]->value)->toBe(3)
        ->and($longWaits)->toBe(0);
});

it('carries no attribute outside the allowed list', function () {
    [$reader, $exporter] = otelBridgeProvider();
    $worker = OtelBridge::make(otelBridgeConfig(true));
    $master = OtelBridge::make(otelBridgeConfig(true));

    $worker?->recordWorker(otelBridgeSnapshot());
    $master?->recordMaster(2, ['default' => true, '2' => false]);
    $master?->recordLongWait('default');

    $metrics = otelBridgeCollect($reader, $exporter);
    $keys = [];

    foreach ($metrics as $metric) {
        foreach (otelBridgePoints($metric) as $point) {
            $keys = [...$keys, ...array_keys($point->attributes->toArray())];
        }
    }

    expect($metrics)->toHaveCount(6)
        ->and(array_values(array_unique($keys)))->each->toBeIn(OtelBridge::ATTRIBUTES)
        ->and($keys)->toContain('laravel.queue.connection', 'messaging.destination.name');
});

it('collects on the master tick without fledge-apm installed', function () {
    otelBridgeProvider();
    $bridge = OtelBridge::make(otelBridgeConfig(true));

    expect(fn () => $bridge?->collectMaster())->not->toThrow(Throwable::class);
});

it('marks the master once and asks fledge-apm to collect on every tick', function () {
    otelBridgeProvider();
    otelBridgeApm(OtelBridgeApmFixture::class);
    $bridge = OtelBridge::make(otelBridgeConfig(true));

    $bridge?->recordMaster(2);
    $bridge?->collectMaster();
    $bridge?->collectMaster();
    $bridge?->collectMaster();

    expect(OtelBridgeApmFixture::$marks)->toBe(['torque'])
        ->and(OtelBridgeApmFixture::$collects)->toBe(3);
});

it('only marks the master when fledge-apm has no collectDue()', function () {
    otelBridgeProvider();
    otelBridgeApm(OtelBridgeApmWithoutCollect::class);
    $bridge = OtelBridge::make(otelBridgeConfig(true));

    expect(function () use ($bridge) {
        $bridge?->collectMaster();
        $bridge?->collectMaster();
    })->not->toThrow(Throwable::class)
        ->and(OtelBridgeApmWithoutCollect::$marks)->toBe(['torque']);
});

it('keeps a throwing collectDue() out of the master loop', function () {
    otelBridgeProvider();
    otelBridgeApm(OtelBridgeApmThrowing::class);
    $bridge = OtelBridge::make(otelBridgeConfig(true));

    expect(fn () => $bridge?->collectMaster())->not->toThrow(Throwable::class);
});

it('polls only the queues whose breaker is on, by bare name', function () {
    $config = [
        'queues' => ['{default}', 'emails', 'reports', 'emails'],
        'redis' => ['cluster' => true],
        'circuit_breaker' => ['enabled' => false],
        'streams' => [
            '{default}' => ['circuit_breaker' => ['enabled' => true]],
            'emails' => ['circuit_breaker' => ['enabled' => true]],
            'reports' => ['circuit_breaker' => false],
        ],
    ];

    expect(MasterProcess::breakerGuardedQueues($config))->toBe(['default', 'emails'])
        ->and(MasterProcess::breakerGuardedQueues(['circuit_breaker' => ['enabled' => true]]))->toBe(['default'])
        ->and(MasterProcess::breakerGuardedQueues(['circuit_breaker' => ['enabled' => false]]))->toBe([]);
});

it('reports the breaker state only when every guarded queue was read', function () {
    $reads = [];
    $isOpen = function (string $queue) use (&$reads): bool {
        $reads[] = $queue;

        if ($queue === 'reports') {
            throw new RuntimeException('Redis went away');
        }

        return $queue === 'emails';
    };

    expect(MasterProcess::readCircuitStates(['default', 'emails'], $isOpen))->toBe(['default' => false, 'emails' => true])
        ->and(MasterProcess::readCircuitStates(['default', 'reports', 'emails'], $isOpen))->toBeNull()
        ->and($reads)->toBe(['default', 'emails', 'default', 'reports'])
        ->and(MasterProcess::readCircuitStates([], $isOpen))->toBe([]);
});

arch('the bridge never touches the collector')
    ->expect(OtelBridge::class)
    ->not->toUse(MetricsCollector::class);
