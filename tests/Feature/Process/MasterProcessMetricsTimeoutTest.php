<?php

declare(strict_types=1);

use Webpatser\Torque\Process\MasterProcess;

/**
 * The master's long-lived Redis clients (metrics publisher, depth probe) give
 * up on a reply after `redis.read_timeout_seconds`, so a half-open socket
 * cannot stall the monitor loop, and a timed-out client is replaced so the
 * next tick starts on a fresh connection.
 */
function silentRedisMaster($server): MasterProcess
{
    $address = (string) stream_socket_get_name($server, false);

    return new MasterProcess(config: [
        'workers' => 1,
        'redis' => ['uri' => 'redis://'.$address, 'prefix' => 'torque-silent:', 'read_timeout_seconds' => 0.3],
        'metrics' => ['enabled' => true, 'publish_interval' => 1],
        'streams' => ['default' => [], 'emails' => []],
    ], logger: fn (string $m) => null);
}

it('gives up on a silent Redis in the metrics publish and replaces the publisher', function () {
    // Accepted by the kernel's backlog and never read: no reply ever comes.
    $server = stream_socket_server('tcp://127.0.0.1:0');

    try {
        $master = silentRedisMaster($server);
        $property = new ReflectionProperty($master, 'metricsPublisher');
        $publisher = (new ReflectionMethod($master, 'makeMetricsPublisher'))->invoke($master);
        $property->setValue($master, $publisher);

        $started = microtime(true);
        (new ReflectionMethod($master, 'publishAggregateMetrics'))->invoke($master);

        expect(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($property->getValue($master))->not->toBeNull()
            ->and($property->getValue($master))->not->toBe($publisher);
    } finally {
        fclose($server);
    }
});

it('gives up on a silent Redis in the depth probe and drops the probe', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');

    try {
        $master = silentRedisMaster($server);
        $probe = new ReflectionProperty($master, 'depthProbe');
        $probe->setValue($master, (new ReflectionMethod($master, 'makeDepthProbe'))->invoke($master));

        $started = microtime(true);
        $samples = (new ReflectionMethod($master, 'gaugeSamples'))->invoke($master, []);

        expect(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($samples['pending'])->toBe(0.0)
            ->and($probe->getValue($master))->toBeNull();
    } finally {
        fclose($server);
    }
});
