# Torque benchmarks

Reproducible measurements of Torque vs plain Laravel `queue:work` (the engine Horizon runs underneath). Methodology, raw numbers, and reproduction instructions.

> [!NOTE]
> All numbers below are from a single test machine (Apple M-series, PHP 8.5.5, Redis 7 on loopback, 2 OS processes per side). They are illustrative, not absolute. The reproduction recipe is the point: run it on your hardware against your workload before drawing conclusions.

## TL;DR

| Workload                                        | Laravel `queue:work` (2 procs) | Torque (2 workers x 25 fibers) |  Δ vs Laravel |
| ----------------------------------------------- | -----------------------------: | -----------------------------: | ------------: |
| `cpu` (5000x `xxh3` hash per job)               |                          782/s |                          560/s | 0.72x slower  |
| `mixed` (sync I/O + CPU)                        |                          490/s |                          410/s | 0.84x slower  |
| `io` (`usleep` 2 ms, blocking)                  |                          387/s |                          387/s |          1.0x |
| `payload-large` (64 KiB JSON)                   |                          337/s |                          535/s |    **1.6x**   |
| `async-io` (`Fledge\Async\delay` 2 ms)          |                          378/s |                          910/s |    **2.4x**   |
| `fanout` (100 ms async wait)                    |                           18/s |                          281/s |    **15x**    |

> [!TIP]
> The pattern is consistent: Torque wins when handlers yield to I/O, loses when handlers occupy the OS thread. The `fanout` row is the workload Torque was built for.

## Methodology

- **Closed-loop, fixed-N batch**: enqueue all jobs first, then time until the queue drains. Steady-state throughput, reproducible across runs.
- **Symmetric measurement**: each job (Torque-side and Horizon-side) emits one `XADD` to a per-run results stream. Serializer overhead, Redis round-trip, and result aggregation are paid identically by both.
- **Identical hardware and Redis**: only the queue driver and worker model differ.
- **2 OS processes per side**: matches the most common Horizon production setting (one supervisor with N worker children).
- **Warmup discarded**: 100 warmup jobs run before the measured 1000.
- **Median of 3 runs**: cold first run dropped to remove opcache / connection-pool warmup variance.
- **JSON serializer on both sides**: igbinary numbers are reported separately below, not mixed in.

### Workloads

| Profile          | What it does                                            | What it tests                              |
| ---------------- | ------------------------------------------------------- | ------------------------------------------ |
| `cpu`            | 5000x `hash('xxh3', random_bytes(64))` per job          | Pure CPU; serves as the slow-end baseline. |
| `io`             | `usleep(2 ms)` per job                                  | Sync-blocking "I/O" that pins the thread.  |
| `async-io`       | `Fledge\Async\delay(2 ms)` (Torque) / `usleep` (Horizon) | True async wait that suspends the Fiber.   |
| `fanout`         | 100 ms async wait (or blocking on Horizon)              | Realistic external-API latency.            |
| `mixed`          | 80% `io`, 20% `cpu` interleaved                         | Realistic web-app queue mix.               |
| `payload-small`  | Identical handler, 256-byte blob in constructor         | Baseline for serializer overhead.          |
| `payload-large`  | Identical handler, 64 KiB blob                          | Where serializer choice actually matters.  |

`async-io` and `fanout` are the differentiators. On Torque they suspend the Fiber and let the scheduler run another coroutine. On Horizon there is no event loop, so they collapse to blocking sleeps that pin the OS thread. That collapse is the point of the comparison.

## Memory at equivalent throughput (production framing)

Throughput-per-process is one number; memory-at-equivalent-throughput is the more honest production framing. To match Torque's 280 jobs/sec on the `fanout` workload, Horizon needs roughly 30 worker processes (extrapolating linearly from the 2-process 18 jobs/sec measurement: `280 / (18/2) ≈ 31`).

| Workload                     | Horizon procs for ~280 jobs/sec | Torque procs | Memory savings |
| ---------------------------- | ------------------------------: | -----------: | -------------: |
| `fanout` (100 ms async wait) |               ~30 (~2.5 GB RAM) |  2 (~120 MB) |          ~95%  |
| `async-io` (2 ms wait)       |                ~5 (~400 MB RAM) |  2 (~120 MB) |          ~70%  |
| `cpu` / `mixed` / `io`       |                       favorably |          n/a |            n/a |

For a queue dominated by external API calls and webhooks, that translates directly to fewer servers, less memory pressure, and headroom to absorb traffic spikes without provisioning ahead of time.

## igbinary serializer (Torque side)

Optional ~2x payload-encoding speedup, opt-in via `TORQUE_SERIALIZER=igbinary`. Numbers from the same 1000-job, 3-run methodology, Torque-side only:

| Workload         | JSON   | igbinary | Δ      |
| ---------------- | -----: | -------: | -----: |
| `mixed`          |  ~393  |    ~393  |  ±0%   |
| `payload-large`  |  ~498  |    ~833  | **+67%** |
| `io`             |  ~370  |    ~370  |  ±0%   |
| `cpu`            |  ~540  |    ~540  |  ±0%   |

The win is concentrated where it should be: large payloads. Small-payload workloads are dominated by handle time and Redis RTT, not encode/decode.

> [!TIP]
> Even if you don't flip the Torque serializer, set `igbinary.compact_strings = On` in `php.ini` to speed up Laravel's session and cache `serialize()` calls globally. Free win across your whole app.

## Reproduction

Full step-by-step recipe (install Horizon, run both sides, compare): [`bench/horizon-comparison/README.md`](bench/horizon-comparison/README.md).

Quick version, assuming you have a Laravel 12+ app with Redis on `127.0.0.1:6379`:

```bash
# Horizon / queue:work side
composer require laravel/horizon --dev
php artisan horizon:install
cp <torque-package>/bench/horizon-comparison/HorizonBenchJob.php app/Jobs/

redis-cli FLUSHDB
QUEUE_CONNECTION=redis php artisan queue:work redis --sleep=0 --tries=1 --memory=512 &
QUEUE_CONNECTION=redis php artisan queue:work redis --sleep=0 --tries=1 --memory=512 &

php <torque-package>/bench/horizon-comparison/run-horizon-bench.php fanout 1000 100

# Torque side (separate shell, same Redis)
QUEUE_CONNECTION=torque php artisan torque:start --workers=2 --concurrency=25 &
php artisan torque:bench --use-running-master --jobs=1000 --warmup=100 \
    --workload=fanout --workers=2 --coroutines=25
```

Run each workload three times on each side, drop the cold first run, take the median.

## What this benchmark does NOT measure

- **Cold-start latency**: workers are warmed up before measurement.
- **Failure recovery**: no retries, no failed-job tracking, no exception paths.
- **Memory growth under load**: steady-state RSS only, no leak detection.
- **Real-world payload variance**: workloads are deterministic; production payloads vary.
- **Network-bound Redis**: assumes fast loopback Redis. Cross-AZ Redis shifts constants but not the relative ranking.
- **Per-stage breakdown**: serialize / xadd / xreadgroup / handle / xack timings are not yet captured. Tracked as `BenchProbe` v1.1; results will be appended here when ready.
