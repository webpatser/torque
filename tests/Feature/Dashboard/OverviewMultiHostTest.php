<?php

declare(strict_types=1);

use Webpatser\Torque\Dashboard\Data\OverviewData;
use Webpatser\Torque\Metrics\MetricsPublisher;

/**
 * A multi-host fleet: every master publishes its own host's aggregate and
 * gauges, and the overview derives the cluster figures at read time. A shared
 * hash folded by every master would read as a per-host mean instead.
 */
function resetMultiHostMetricKeys(): void
{
    $redis = torqueRedis();
    $redis->execute('DEL', 'torque-test:default', 'torque-test:metrics', 'torque-test:metrics:hosts', 'torque-test:metrics:hosts:first', 'torque-test:metrics:live');

    foreach (['host-a', 'host-b'] as $host) {
        $redis->execute('DEL', 'torque-test:metrics:live:'.$host);
    }

    foreach (['minute', 'hour', 'day'] as $tier) {
        $redis->execute('DEL', 'torque-test:metrics:rollup:'.$tier, 'torque-test:metrics:rollup:'.$tier.':host');
        $redis->execute('DEL', 'torque-test:metrics:gauge:'.$tier, 'torque-test:metrics:gauge:'.$tier.':host');
    }

    $locks = $redis->execute('KEYS', 'torque-test:metrics:prune:*');

    foreach (is_array($locks) ? $locks : [] as $lock) {
        $redis->execute('DEL', (string) $lock);
    }
}

/**
 * The current epoch, after waiting out the last five seconds of a minute.
 *
 * A test here writes and reads within well under a second, so five seconds
 * of headroom keeps the write and the read in one minute bucket.
 */
function clearOfMinuteBoundary(): int
{
    $now = time();

    if ($now % 60 >= 55) {
        time_sleep_until(intdiv($now, 60) * 60 + 60.05);
        $now = time();
    }

    return $now;
}

/**
 * One master's tick for one host: per-host outcomes, gauges and the aggregate.
 *
 * @param  array<string, float|int>  $gauges
 * @param  array<string, float|int>  $aggregate
 */
function publishHostTick(string $host, int $jobs, array $gauges, array $aggregate, int $now): void
{
    $publisher = app(MetricsPublisher::class);

    $publisher->recordHostOutcomes([$host => [$jobs, 0]], $now);
    $publisher->recordGauges($gauges, $now, $host);
    $publisher->publishAggregate($aggregate, $host);
}

beforeEach(function () {
    resetMultiHostMetricKeys();

    // OverviewData reads stream depths, which error with NOGROUP until the
    // stream and its consumer group exist.
    torqueRedis()->execute('XGROUP', 'CREATE', 'torque-test:default', 'torque-test', '$', 'MKSTREAM');

    // The publisher, Range and OverviewData read the wall clock themselves and
    // none takes an injected one, so the write epoch cannot be frozen into the
    // read. Start well clear of a minute boundary instead (a minute boundary
    // also covers the hour and day ones), so every write and the read that
    // follows land in the same bucket.
    $this->now = clearOfMinuteBoundary();

    $this->hostA = fn () => publishHostTick('host-a', 30, [
        MetricsPublisher::GAUGE_LATENCY => 100.0,
        MetricsPublisher::GAUGE_CONCURRENT => 10,
        MetricsPublisher::GAUGE_MEMORY => 200.0,
        MetricsPublisher::GAUGE_WORKER_MEMORY_PEAK => 50.0,
        MetricsPublisher::GAUGE_PENDING => 500,
        MetricsPublisher::GAUGE_DELAYED => 7,
    ], [
        'throughput' => 2.5,
        'concurrent' => 10,
        'total_slots' => 100,
        'avg_latency' => 100.0,
        'jobs_processed' => 300,
        'jobs_failed' => 3,
        'memory_mb' => 200.0,
        'workers' => 4,
    ], $this->now);

    $this->hostB = fn () => publishHostTick('host-b', 10, [
        MetricsPublisher::GAUGE_LATENCY => 300.0,
        MetricsPublisher::GAUGE_CONCURRENT => 6,
        MetricsPublisher::GAUGE_MEMORY => 100.0,
        MetricsPublisher::GAUGE_WORKER_MEMORY_PEAK => 80.0,
        MetricsPublisher::GAUGE_PENDING => 500,
        MetricsPublisher::GAUGE_DELAYED => 7,
    ], [
        'throughput' => 1.5,
        'concurrent' => 6,
        'total_slots' => 50,
        'avg_latency' => 300.0,
        'jobs_processed' => 100,
        'jobs_failed' => 1,
        'memory_mb' => 100.0,
        'workers' => 2,
    ], $this->now);
});

afterEach(function () {
    rescue(fn () => resetMultiHostMetricKeys(), null, false);
});

it('sums two hosts into the cluster gauges and figures', function () {
    ($this->hostA)();
    ($this->hostB)();

    $data = app(OverviewData::class)->get('1h');
    $series = $data['series'];

    // Slots and memory add across hosts, the peak is the largest worker
    // anywhere, latency is weighted by each host's jobs in the bucket
    // (30 x 100ms + 10 x 300ms over 40 jobs), and queue depth, which every
    // master measures for the whole cluster, is not multiplied by the hosts.
    expect(end($series['concurrent']))->toBe(16.0)
        ->and(end($series['memory']))->toBe(300.0)
        ->and(end($series['memoryPeak']))->toBe(80.0)
        ->and(end($series['latency']))->toBe(0.15)
        ->and(end($series['pending']))->toBe(500.0)
        ->and(end($series['delayed']))->toBe(7.0);

    expect($data['totals']['slots'])->toBe(150)
        ->and($data['totals']['busy'])->toBe(16)
        ->and($data['metrics']['throughput'])->toBe(4.0)
        ->and($data['metrics']['concurrent'])->toBe(16)
        ->and($data['metrics']['workers'])->toBe(6)
        ->and($data['metrics']['jobsTotal'])->toBe(400)
        ->and($data['metrics']['memoryMb'])->toBe(300.0)
        // Weighted by processed jobs: (300 x 100 + 100 x 300) / 400.
        ->and($data['metrics']['latencyMs'])->toBe(150.0)
        ->and($data['metrics']['failRate'])->toBe(1.0);
});

it('shows a single host exactly as it publishes it', function () {
    ($this->hostA)();

    $data = app(OverviewData::class)->get('1h');
    $series = $data['series'];

    expect(end($series['concurrent']))->toBe(10.0)
        ->and(end($series['memory']))->toBe(200.0)
        ->and(end($series['memoryPeak']))->toBe(50.0)
        ->and(end($series['latency']))->toBe(0.1)
        ->and(end($series['pending']))->toBe(500.0)
        ->and(end($series['delayed']))->toBe(7.0)
        ->and($data['totals']['slots'])->toBe(100)
        ->and($data['metrics']['throughput'])->toBe(2.5)
        ->and($data['metrics']['workers'])->toBe(4)
        ->and($data['metrics']['latencyMs'])->toBe(100.0)
        ->and($data['metrics']['jobsTotal'])->toBe(300);
});

it('drops a host whose aggregate expired', function () {
    ($this->hostA)();
    ($this->hostB)();

    torqueRedis()->execute('PEXPIRE', 'torque-test:metrics:live:host-b', '1');
    usleep(50_000);

    $metrics = app(OverviewData::class)->get('1h')['metrics'];

    expect($metrics['workers'])->toBe(4)
        ->and($metrics['concurrent'])->toBe(10)
        ->and($metrics['throughput'])->toBe(2.5);
});

it('does not double count old-layout data next to the new layout', function () {
    $redis = torqueRedis();
    $publisher = app(MetricsPublisher::class);

    // An old master still running mid-deploy: the shared hash and an
    // unscoped gauge sample in the same bucket.
    $redis->execute('HSET', 'torque-test:metrics', 'concurrent', '99', 'total_slots', '999', 'workers', '9', 'throughput', '50');
    $publisher->recordGauges([MetricsPublisher::GAUGE_CONCURRENT => 99, MetricsPublisher::GAUGE_MEMORY => 999.0], $this->now);

    ($this->hostA)();
    ($this->hostB)();

    $data = app(OverviewData::class)->get('1h');

    expect(end($data['series']['concurrent']))->toBe(16.0)
        ->and(end($data['series']['memory']))->toBe(300.0)
        ->and($data['metrics']['workers'])->toBe(6)
        ->and($data['metrics']['concurrent'])->toBe(16)
        ->and($data['totals']['slots'])->toBe(150);
});

it('still reads an all-old fleet from the old layout', function () {
    $redis = torqueRedis();
    $publisher = app(MetricsPublisher::class);

    $redis->execute('HSET', 'torque-test:metrics', 'concurrent', '12', 'total_slots', '80', 'workers', '3', 'throughput', '5');
    $redis->execute('EXPIRE', 'torque-test:metrics', '30');
    $publisher->recordGauges([MetricsPublisher::GAUGE_CONCURRENT => 12], $this->now);

    $data = app(OverviewData::class)->get('1h');

    expect(end($data['series']['concurrent']))->toBe(12.0)
        ->and($data['metrics']['workers'])->toBe(3)
        ->and($data['metrics']['concurrent'])->toBe(12)
        ->and($data['totals']['slots'])->toBe(80);
});
