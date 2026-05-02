# Horizon comparison reproduction

This directory contains everything needed to reproduce the [fair benchmark numbers](../../BENCHMARKS.md) comparing Torque to plain Laravel `queue:work` (which is what Horizon runs under the hood).

## What's in here

| File | Purpose |
| ---- | ------- |
| [`HorizonBenchJob.php`](HorizonBenchJob.php) | Mirror of Torque's `BenchJob` for the redis-driver side. Same workloads, same `XADD` result emit so measurement overhead is symmetric. |
| [`run-horizon-bench.php`](run-horizon-bench.php) | Standalone driver script: dispatches N jobs, drains the queue, tails the results stream, prints throughput + latency. |

Workloads supported: `cpu`, `io`, `async-io`, `fanout`, `mixed`, `payload-small`, `payload-large`. Definitions match Torque's [`BenchJob`](../../src/Console/Bench/BenchJob.php).

> [!NOTE]
> The `async-io` and `fanout` workloads collapse to blocking `usleep` here because Horizon workers have no event loop. That collapse is exactly the comparison: in Torque those same workloads suspend the Fiber and let other coroutines run.

## Reproduction

Pre-requisites: PHP 8.5+, Redis 7+ running on `127.0.0.1:6379`, a Laravel 12+ app with `predis/predis` or `phpredis`.

### 1. Install Horizon side

In your Laravel app root:

```bash
composer require laravel/horizon --dev
php artisan horizon:install

cp /path/to/torque/bench/horizon-comparison/HorizonBenchJob.php app/Jobs/
```

### 2. Run plain `queue:work` workers (2 procs)

`queue:work` is what Horizon spawns per supervisor process. Running it directly removes any Horizon-specific overhead from the comparison.

```bash
redis-cli FLUSHDB
QUEUE_CONNECTION=redis php artisan queue:work redis --queue=default --sleep=0 --tries=1 --memory=512 &
QUEUE_CONNECTION=redis php artisan queue:work redis --queue=default --sleep=0 --tries=1 --memory=512 &
```

### 3. Drive the bench

From the same app root:

```bash
php /path/to/torque/bench/horizon-comparison/run-horizon-bench.php fanout 1000 100
```

Arguments: `<workload> [jobs=1000] [warmup=100]`. Output line:

```text
workload=fanout          n=1000  wall=54.2s  drain=53.9s  throughput=18.4/s  p50=27055ms  p95=51384ms  p99=53564ms  handle_med=103371µs  samples=1000
```

### 4. Run Torque side

In a separate shell, against the same Redis:

```bash
QUEUE_CONNECTION=torque php artisan torque:start --workers=2 --concurrency=25 &
php artisan torque:bench --use-running-master --jobs=1000 --warmup=100 --workload=fanout --workers=2 --coroutines=25
```

### 5. Compare

Run each workload three times on each side, drop the cold first run, take the median. The headline workload is `fanout`: a job that does `usleep(100_000)` (Horizon side) or `Fledge\Async\delay(0.1)` (Torque side) to simulate a 100 ms external API call.

> [!IMPORTANT]
> Always reset state (`redis-cli FLUSHDB`, restart workers) when switching between sides. Leftover stream entries or in-flight jobs from a prior run will skew the next measurement.

## Methodology notes

- **Closed-loop, fixed-N**: enqueue all jobs first, then time until the queue drains. Steady-state throughput, reproducible across runs.
- **Symmetric measurement**: both sides emit one `XADD torque-bench:{runId}:results` per job. The serializer overhead, Redis round-trip, and result aggregation are paid identically by both.
- **Same Redis, same hardware**: only the queue driver and worker model differ.
- **2 OS processes per side**: matches Horizon's "supervisor with N workers" pattern at the most common production setting. Memory framing (Section in [BENCHMARKS.md](../../BENCHMARKS.md)) shows the impact at different process counts.
- **No phpredis vs predis distinction**: the bench works with either. Use whichever your app already has.

## What this benchmark does NOT measure

- **Cold-start latency**: workers are warmed up before measurement.
- **Failure recovery**: no retries, no failed-job tracking.
- **Memory pressure under load**: see the memory framing in BENCHMARKS.md for steady-state RSS, but no leak/growth detection.
- **Real-world payload variance**: workloads are deterministic; production payloads vary.
- **Network-bound Redis**: this assumes a fast loopback Redis. Cross-AZ Redis would shift the constants but not the relative ranking.
