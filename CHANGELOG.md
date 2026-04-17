# Changelog

All notable changes to Torque will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.7.0] - 2026-04-17

### Added
- **Dashboard rewrite.** The monolithic `dashboard.wire.php` is replaced by a shell (`dashboard-shell.wire.php`) + dedicated page components under `resources/views/livewire/pages/` (overview, jobs, job-inspector, streams, workers, failed-jobs, settings). Pages share a consistent Flux UI Pro look and navigate client-side through the shell
- **Job inspector page.** Timeline view for a single job's lifecycle events with timestamps, duration, worker, memory, exception details, and payload
- `AuthorizesTorqueAccess` Livewire trait. Runs `Gate::authorize('viewTorque')` on every component lifecycle request, not just on the route, so `wire:click` / `wire:poll` endpoints cannot be reached by users who would fail the gate
- `JobStream::activeJobs()` and `JobStream::recentJobs(status)` for the dashboard overview and jobs pages
- `DeadLetterHandler::listBefore()` for cursor-based pagination on the failed-jobs page
- `DeadLetterHandler` now accepts an `allowedQueues` whitelist (auto-populated from `config('torque.streams')`) so retries cannot push jobs into arbitrary Redis streams
- `PayloadSanitizer` support class. Redacts passwords, API keys, tokens, bearer credentials, and URI-embedded credentials from both exception messages and payload arrays before they are displayed in the dashboard or emailed
- Default `viewTorque` gate falls back to `app()->environment('local')` when the host application hasn't defined one. Dashboard stays visible in development and locked in production
- README section documenting the `@source` Tailwind directive required for Flux-styled dashboard views and clarifying the gate / retry allowlist behavior
- Dependency on `livewire/flux` ^2.0 (dashboard UI)

### Changed
- Dashboard page components live under `src/Dashboard/resources/views/livewire/pages/`. Existing aliases (`torque.dashboard`, `torque.metric-cards`, `torque.streams-table`, `torque.failed-jobs`, `torque.poll-interval`) are gone; use the shell + page components instead
- Dashboard poll defaults tightened: available intervals are `[0, 1000, 2000, 5000, 10000, 30000]` ms with a `1000` ms default
- Job inspector UUID parameter now requires a strict RFC 4122 UUID shape before being used to look up a Redis stream key
- Master process signal loop uses `pcntl_sigtimedwait()` on platforms that support it (Linux), so SIGCHLD and stop signals wake the master instantly instead of after the next 100 ms `usleep` tick. Falls back to the original async signals + `usleep` path on macOS (which never implemented `sigtimedwait`)
- `TorqueSupervisorCommand` only accepts output paths under `storage_path()` and quotes `artisan` / log paths in the generated INI so app paths containing spaces produce valid Supervisor configs
- Dashboard error messages in overview / jobs / streams / failed-jobs pages no longer include raw `$e->getMessage()`. Exceptions are sent to `report()` and a generic message is rendered

### Fixed
- `writePidFile()` refuses to start when the PID path is already a symlink (previously it called `unlink()` then `rename()`, leaving a small TOCTOU window where a symlink could be recreated between the two operations)
- `JobFailedNotification` reuses the new shared `PayloadSanitizer` so its inline redaction regex is no longer duplicated and now also strips tokens, secrets, and Bearer credentials

### Removed
- Dead `WorkerProcess::processPendingMessages()` method. Crash-recovery drain has lived inline in the Fiber main loop since 0.4.0

## [0.6.0] - 2026-04-16

### Fixed
- **Workers not picking up new jobs while running.** Both immediate and delayed jobs dispatched while Torque was already running were invisible until a restart. Root cause: 50 simultaneous XREADGROUP BLOCK connections overwhelmed the async Redis client's notification chain, preventing Fibers from waking up on new messages or timeouts. Replaced BLOCK-based reads with non-blocking `XREADGROUP` + explicit `delay()` yield. Each Fiber now polls and yields cleanly, ensuring the event loop can always service timers (delayed job migration, metrics, pause checks)

### Added
- **Redis Cluster support.** Set `TORQUE_CLUSTER=true` to wrap all queue keys in hash tags (`{queue-name}`). Stream, delayed set, and notification keys for the same queue land on the same cluster slot. Existing hash tags in queue names are preserved (no double-wrapping). Matches the approach Laravel v13.5.0 introduced for its built-in RedisQueue
- **Staggered Fiber startup.** Fibers now start with a small delay between each one (spread across the poll interval) to distribute polling evenly and prevent thundering-herd effects
- **Shared pause flag.** A single `EventLoop::repeat` timer checks the pause key every 2 seconds and updates a shared flag. Fibers read the flag instead of each calling `EXISTS` on every iteration, reducing Redis overhead from 50 calls/cycle to 1
- **Periodic pending re-check.** Fibers re-check their pending entry list every ~50 iterations (~25 seconds) instead of only once at startup. Catches any orphaned messages that were delivered but never acknowledged
- **Delayed job migration logging.** `migrateDelayedJobs` now logs to STDERR when it moves matured jobs from the sorted set to the stream, making it easier to verify the migration timer fires correctly

### Changed
- `readNextMessage()` no longer uses `BLOCK` argument. Returns immediately with a message or null. Callers yield via `\Fledge\Async\delay()` when idle
- `block_for` config now controls the poll interval (converted from ms to seconds) rather than the XREADGROUP BLOCK timeout
- `readPendingMessage()`, `stealMessage()`, and `migrateDelayedJobs()` accept a `$buildStreamKey` closure for cluster-safe key construction
- Updated dependencies from amphp to webpatser/fledge-fiber

## [0.5.2] - 2026-04-12

### Fixed
- Workers stuck after reaching `max_jobs`: event loop timers are now cancelled when limits are reached, allowing the loop to exit cleanly

## [0.5.1] - 2026-04-10

### Fixed
- Stop/start for Deployer: kill orphan worker processes instead of refusing to start

## [0.5.0] - 2026-04-08

### Changed
- Migrated from amphp to webpatser/fledge-fiber for all async primitives (Redis, async/await, sync)
- Fixed `#[\NoDiscard]` warning on async queue operations

## [0.4.0] - 2026-04-06

### Added
- Per-job event streams: every job automatically records lifecycle events to `torque:job:{uuid}` Redis Streams (queued, started, completed, failed, exception)
- `Streamable` trait: jobs can emit custom progress events via `$this->emit('message', progress: 0.5)`
- `JobStreamRecorder`: event listener that writes to per-job streams with configurable TTL and MAXLEN
- `JobStream`: reader class with `events()`, `tail()`, and `isFinished()` for consuming job streams
- `torque:tail` command: live CLI monitoring of individual jobs (`--job={uuid}` or `--latest`)
- `job_streams` config section (enabled, ttl, max_events)

### Fixed
- `parseXreadgroupResponse()` now iterates all streams instead of only checking index 0, which made non-default queues invisible to workers
- Monitor now aggregates metrics from per-worker Redis hashes (the master `publishAggregatedMetrics()` was never called)
- Peak active slots tracking in `MetricsCollector`: snapshot captures high-water mark between publishes for accurate reporting with async fibers

### Changed
- Replaced bulk `XAUTOCLAIM` at startup with per-fiber work-stealing using `retry_after` as min-idle-time per queue
- Reordered fiber loop: read new messages first (`>`), then pending recovery (`0-0`), then steal. Eliminates wasted XAUTOCLAIM calls when fresh messages are available
- Flicker-free monitor: single buffered write with cursor-home instead of screen-clear
- Rolling 60-second throughput average in monitor instead of per-tick delta

## [0.3.0] - 2026-04-06

### Changed
- Replaced `pcntl_fork` with `pcntl_exec` for worker spawning: fixes Fiber conflicts in forked processes

## [0.2.0] - 2026-04-06

### Fixed
- Error catching and stderr logging in worker child processes
- Bootstrap path resolution in forked workers
- Laravel 13 Queue contract compatibility

## [0.1.0] - 2026-04-06

Initial release.

### Added

#### Core
- `StreamQueue`: Laravel Queue contract implementation on Redis Streams (XADD/XREADGROUP/XACK)
- `StreamJob`: Job wrapper with stream message ID, delete/release/attempts tracking
- `StreamConnector`: Queue connector factory for Laravel's QueueManager
- `WorkerProcess`: Revolt event loop with Fiber-based concurrent job execution
- `MasterProcess`: pcntl_fork supervisor with signal handling and auto-respawn
- `TorqueServiceProvider`: Laravel auto-discovery, queue connector and command registration

#### Connection Pools
- `ConnectionPool`: Generic async-safe pool using `Amp\Sync\LocalSemaphore`
- `PooledConnection`: Auto-releasing connection wrapper with `WeakMap`-safe cleanup
- `RedisPool`: Pre-configured pool for amphp/redis clients
- `MysqlPool`: Async MySQL pool wrapping amphp/mysql's `MysqlConnectionPool`
- `HttpPool`: HTTP concurrency limiter for amphp/http-client

#### Job System
- `TorqueJob`: Base job class with `$connection = 'torque'` and pool injection via container
- `CoroutineContext`: Per-Fiber isolated state storage using `WeakMap<Fiber, array>`
- `DeadLetterHandler`: Failed job routing to dead-letter Redis Stream with retry/purge/trim/list

#### Process Management
- `AutoScaler`: Slot-pressure-based worker scaling with configurable thresholds and cooldown
- `ScaleDecision`: Backed enum (ScaleUp/ScaleDown/NoChange)
- Autoscaler wired into MasterProcess monitor loop with least-busy-worker selection for scale-down

#### Metrics
- `MetricsCollector`: Per-worker stats with circular latency buffer (SplFixedArray)
- `MetricsPublisher`: Publishes worker snapshots to Redis hashes with heartbeat TTL
- `WorkerSnapshot`: Readonly value object for point-in-time worker state
- Metrics collection and publishing wired into WorkerProcess event loop

#### Dashboard
- Livewire 4 + Flux UI Pro dashboard at configurable route (default: `/torque`)
- `dashboard.wire.php`: Main layout with tabbed navigation and dynamic `wire:poll`
- `metric-cards.wire.php`: 6-card grid (throughput, concurrent, latency, pending, failed, memory)
- `workers-table.wire.php`: Worker table with color-coded slot usage bars
- `streams-table.wire.php`: Queue/stream overview with pending and delayed counts
- `failed-jobs.wire.php`: Failed jobs list with retry/delete actions and Gate authorization
- `poll-interval.wire.php`: Kibana-style refresh dropdown with localStorage persistence
- `TorqueDashboardController`: Route registration with `viewTorque` Gate (deny by default)

#### CLI Commands
- `torque:start`: Start master + worker processes with `--workers`, `--concurrency`, `--queues`
- `torque:stop`: Graceful shutdown via PID file, `--force` for SIGKILL
- `torque:status`: Display worker metrics and queue depths from Redis
- `torque:pause`: Pause/continue job processing via Redis flag
- `torque:supervisor`: Generate Supervisor config file with path validation

#### Events & Notifications
- `JobPermanentlyFailed`: Event dispatched when a job exhausts all retries
- `JobFailedNotification`: Ready-made mail/array notification with credential redaction

#### Worker Features
- Pause support: Workers check `{prefix}paused` Redis key before reading new jobs
- Delayed job migration: Timer coroutine moves matured jobs from sorted set to stream
- Stale message reclamation: `XAUTOCLAIM` on startup reclaims jobs from crashed workers
- Graceful shutdown: SIGTERM/SIGINT drain in-flight jobs before exiting
- Max jobs/lifetime limits: Workers restart after configurable thresholds

### Security
- Path traversal protection on `torque:supervisor --path` (must be within app directory)
- Redis URI credential masking in console output
- Dashboard gate defaults to deny (applications must explicitly grant access)
- Queue name validation (`^[a-zA-Z0-9_\-.:]+$`) on CLI input and dead-letter retry
- Exception message truncation (1000 chars) in dead-letter stream
- Credential pattern redaction in email notifications
- PID file hardening: symlink detection, atomic write (tmp + rename)
- Gate authorization on all destructive dashboard actions (retry, purge, retryAll)

[Unreleased]: https://github.com/webpatser/torque/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/webpatser/torque/compare/v0.5.2...v0.6.0
[0.5.2]: https://github.com/webpatser/torque/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/webpatser/torque/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/webpatser/torque/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/webpatser/torque/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/webpatser/torque/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/webpatser/torque/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webpatser/torque/releases/tag/v0.1.0
