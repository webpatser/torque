<?php

declare(strict_types=1);

/*
 * Run from your Laravel app root:
 *   php /path/to/torque/bench/horizon-comparison/run-horizon-bench.php <workload> [jobs] [warmup]
 *
 * The script auto-detects the host app by walking up from the current working
 * directory until it finds an `artisan` file plus `vendor/autoload.php`.
 */

$cwd = getcwd();
$base = $cwd;
while ($base !== '/' && ! (file_exists($base . '/artisan') && file_exists($base . '/vendor/autoload.php'))) {
    $base = dirname($base);
}
if ($base === '/') {
    fwrite(STDERR, "Could not locate a Laravel app root from {$cwd}. Run this script from inside your Laravel app.\n");
    exit(10);
}

require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\HorizonBenchJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

$workload = $argv[1] ?? 'mixed';
$jobs = (int) ($argv[2] ?? 1000);
$warmup = (int) ($argv[3] ?? 100);

$validWorkloads = ['cpu', 'io', 'async-io', 'fanout', 'mixed', 'payload-small', 'payload-large'];
if (! in_array($workload, $validWorkloads, true)) {
    fwrite(STDERR, "Invalid workload: $workload\n");
    exit(1);
}

config(['queue.default' => 'redis']);

$runId = substr(bin2hex(random_bytes(8)), 0, 8);

// Refuse to start if queue isn't drained from prior run.
$pre = Queue::size('default');
if ($pre > 0) {
    fwrite(STDERR, "Queue not empty (size=$pre). Wait for workers or flushdb.\n");
    exit(2);
}

$payloadLarge = Str::random(64 * 1024);
$payloadSmall = Str::random(256);

$workloadFor = function (string $profile, int $i) use ($payloadSmall, $payloadLarge): array {
    return match ($profile) {
        'cpu' => ['cpu', null],
        'io' => ['io', null],
        'async-io' => ['async-io', null],
        'fanout' => ['fanout', null],
        'payload-small' => ['payload-small', $payloadSmall],
        'payload-large' => ['payload-large', $payloadLarge],
        'mixed' => $i % 5 === 4 ? ['cpu', null] : ['io', null],
    };
};

// Warmup
for ($i = 0; $i < $warmup; $i++) {
    [$w, $blob] = $workloadFor($workload, $i);
    HorizonBenchJob::dispatch($w, microtime(true), $runId, $blob);
}
$deadline = microtime(true) + 60;
while (Queue::size('default') > 0) {
    if (microtime(true) > $deadline) { fwrite(STDERR, "Warmup drain timeout.\n"); exit(2); }
    usleep(50000);
}
// Brief pause for any in-flight handle() calls to finish
usleep(200_000);
// Clear warmup samples
Redis::connection()->del("torque-bench:{$runId}:results");

// Measured run: enqueue, then drain
$enqueueStart = microtime(true);
for ($i = 0; $i < $jobs; $i++) {
    [$w, $blob] = $workloadFor($workload, $i);
    HorizonBenchJob::dispatch($w, microtime(true), $runId, $blob);
}
$enqueueEnd = microtime(true);

$deadline = microtime(true) + max(180, $jobs * 0.5);
while (Queue::size('default') > 0) {
    if (microtime(true) > $deadline) { fwrite(STDERR, "Drain timeout.\n"); exit(3); }
    usleep(5000);
}
$drainEnd = microtime(true);

// Allow last in-flight handle() emit to land, then sample tail
usleep(100_000);

$resultsStream = "torque-bench:{$runId}:results";
$samples = [];
$handles = [];
$entries = Redis::connection()->command('XRANGE', [$resultsStream, '-', '+']);
foreach ($entries as $entry) {
    $fields = $entry[1] ?? [];
    $kv = [];
    for ($j = 0; $j < count($fields); $j += 2) {
        $kv[$fields[$j]] = $fields[$j + 1];
    }
    if (isset($kv['total_ms'], $kv['handle_ns'])) {
        $samples[] = (float) $kv['total_ms'];
        $handles[] = (int) $kv['handle_ns'];
    }
}

$wall = $drainEnd - $enqueueStart;
$drainOnly = $drainEnd - $enqueueEnd;
$throughput = $jobs / $wall;

if (count($samples) > 0) {
    sort($samples);
    sort($handles);
    $count = count($samples);
    $p = fn(float $q) => $samples[(int) floor($q * ($count - 1))];
    printf(
        "workload=%-15s n=%4d  wall=%.3fs  drain=%.3fs  throughput=%.1f/s  p50=%.1fms  p95=%.1fms  p99=%.1fms  handle_med=%.1fµs  samples=%d\n",
        $workload, $jobs, $wall, $drainOnly, $throughput, $p(0.50), $p(0.95), $p(0.99),
        $handles[(int) floor(0.5 * ($count - 1))] / 1000, $count
    );
} else {
    printf("workload=%-15s n=%4d  wall=%.3fs  drain=%.3fs  throughput=%.1f/s  (no samples)\n",
        $workload, $jobs, $wall, $drainOnly, $throughput);
}

Redis::connection()->del($resultsStream);
