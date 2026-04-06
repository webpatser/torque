# Changelog

All notable changes to Torque will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-04-06

### Added
- Per-job event streams — every job automatically records lifecycle events to `torque:job:{uuid}` Redis Streams (queued, started, completed, failed, exception)
- `Streamable` trait — jobs can emit custom progress events via `$this->emit('message', progress: 0.5)`
- `JobStreamRecorder` — event listener that writes to per-job streams with configurable TTL and MAXLEN
- `JobStream` — reader class with `events()`, `tail()`, and `isFinished()` for consuming job streams
- `torque:tail` command — live CLI monitoring of individual jobs (`--job={uuid}` or `--latest`)
- `job_streams` config section (enabled, ttl, max_events)

### Fixed
- `parseXreadgroupResponse()` now iterates all streams instead of only checking index 0, which made non-default queues invisible to workers
- Monitor now aggregates metrics from per-worker Redis hashes (the master `publishAggregatedMetrics()` was never called)
- Peak active slots tracking in `MetricsCollector` — snapshot captures high-water mark between publishes for accurate reporting with async fibers

### Changed
- Replaced bulk `XAUTOCLAIM` at startup with per-fiber work-stealing using `retry_after` as min-idle-time per queue
- Reordered fiber loop: read new messages first (`>`), then pending recovery (`0-0`), then steal — eliminates wasted XAUTOCLAIM calls when fresh messages are available
- Flicker-free monitor: single buffered write with cursor-home instead of screen-clear
- Rolling 60-second throughput average in monitor instead of per-tick delta

## [0.3.0] - 2026-04-06

### Changed
- Replaced `pcntl_fork` with `pcntl_exec` for worker spawning — fixes Fiber conflicts in forked processes

## [0.2.0] - 2026-04-06

### Fixed
- Error catching and stderr logging in worker child processes
- Bootstrap path resolution in forked workers
- Laravel 13 Queue contract compatibility

## [0.1.0] - 2026-04-06

Initial release.

### Added

#### Core
- `StreamQueue` — Laravel Queue contract implementation on Redis Streams (XADD/XREADGROUP/XACK)
- `StreamJob` — Job wrapper with stream message ID, delete/release/attempts tracking
- `StreamConnector` — Queue connector factory for Laravel's QueueManager
- `WorkerProcess` — Revolt event loop with Fiber-based concurrent job execution
- `MasterProcess` — pcntl_fork supervisor with signal handling and auto-respawn
- `TorqueServiceProvider` — Laravel auto-discovery, queue connector and command registration

#### Connection Pools
- `ConnectionPool` — Generic async-safe pool using `Amp\Sync\LocalSemaphore`
- `PooledConnection` — Auto-releasing connection wrapper with `WeakMap`-safe cleanup
- `RedisPool` — Pre-configured pool for amphp/redis clients
- `MysqlPool` — Async MySQL pool wrapping amphp/mysql's `MysqlConnectionPool`
- `HttpPool` — HTTP concurrency limiter for amphp/http-client

#### Job System
- `TorqueJob` — Base job class with `$connection = 'torque'` and pool injection via container
- `CoroutineContext` — Per-Fiber isolated state storage using `WeakMap<Fiber, array>`
- `DeadLetterHandler` — Failed job routing to dead-letter Redis Stream with retry/purge/trim/list

#### Process Management
- `AutoScaler` — Slot-pressure-based worker scaling with configurable thresholds and cooldown
- `ScaleDecision` — Backed enum (ScaleUp/ScaleDown/NoChange)
- Autoscaler wired into MasterProcess monitor loop with least-busy-worker selection for scale-down

#### Metrics
- `MetricsCollector` — Per-worker stats with circular latency buffer (SplFixedArray)
- `MetricsPublisher` — Publishes worker snapshots to Redis hashes with heartbeat TTL
- `WorkerSnapshot` — Readonly value object for point-in-time worker state
- Metrics collection and publishing wired into WorkerProcess event loop

#### Dashboard
- Livewire 4 + Flux UI Pro dashboard at configurable route (default: `/torque`)
- `dashboard.wire.php` — Main layout with tabbed navigation and dynamic `wire:poll`
- `metric-cards.wire.php` — 6-card grid (throughput, concurrent, latency, pending, failed, memory)
- `workers-table.wire.php` — Worker table with color-coded slot usage bars
- `streams-table.wire.php` — Queue/stream overview with pending and delayed counts
- `failed-jobs.wire.php` — Failed jobs list with retry/delete actions and Gate authorization
- `poll-interval.wire.php` — Kibana-style refresh dropdown with localStorage persistence
- `TorqueDashboardController` — Route registration with `viewTorque` Gate (deny by default)

#### CLI Commands
- `torque:start` — Start master + worker processes with `--workers`, `--concurrency`, `--queues`
- `torque:stop` — Graceful shutdown via PID file, `--force` for SIGKILL
- `torque:status` — Display worker metrics and queue depths from Redis
- `torque:pause` — Pause/continue job processing via Redis flag
- `torque:supervisor` — Generate Supervisor config file with path validation

#### Events & Notifications
- `JobPermanentlyFailed` — Event dispatched when a job exhausts all retries
- `JobFailedNotification` — Ready-made mail/array notification with credential redaction

#### Worker Features
- Pause support — Workers check `{prefix}paused` Redis key before reading new jobs
- Delayed job migration — Timer coroutine moves matured jobs from sorted set to stream
- Stale message reclamation — `XAUTOCLAIM` on startup reclaims jobs from crashed workers
- Graceful shutdown — SIGTERM/SIGINT drain in-flight jobs before exiting
- Max jobs/lifetime limits — Workers restart after configurable thresholds

### Security
- Path traversal protection on `torque:supervisor --path` (must be within app directory)
- Redis URI credential masking in console output
- Dashboard gate defaults to deny (applications must explicitly grant access)
- Queue name validation (`^[a-zA-Z0-9_\-.:]+$`) on CLI input and dead-letter retry
- Exception message truncation (1000 chars) in dead-letter stream
- Credential pattern redaction in email notifications
- PID file hardening: symlink detection, atomic write (tmp + rename)
- Gate authorization on all destructive dashboard actions (retry, purge, retryAll)

[Unreleased]: https://github.com/webpatser/torque/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/webpatser/torque/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/webpatser/torque/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/webpatser/torque/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webpatser/torque/releases/tag/v0.1.0
