# Changelog

All notable changes to Torque will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **`torque:start` and `torque:stop` orphan sweeps could target an innocent PID on Linux.** PHP's `exec()` runs `pgrep -f 'artisan torque:start'` through an `sh -c` wrapper whose own argv contains the pattern text; on Linux the wrapper is visible to pgrep's scan, so the sweep matched a transient shell PID and sent SIGKILL to it (and its process group in `torque:start`), hitting whatever process had recycled that PID by then. All four pgrep patterns now bracket the first character (`[a]rtisan torque:start`), the standard self-match guard. macOS was unaffected because its shell replaces the wrapper via exec before pgrep scans.
- **Reload/stop command tests failing on Linux CI.** The fake-master helper spawned a bare `sleep`, which `readPid()`'s `/proc/<pid>/cmdline` identity check (added in 0.10.x to reject recycled PIDs) correctly refuses. The helper now spawns a PHP process whose argv and process title carry the `torque:start` marker, matching the approach already used in `MasterProcessPidFileTest`. Full suite verified on Linux (PHP 8.5 + Redis in Docker): 318 passed.
- **CI test job failing since it was added** with `Could not read XML from file "--cache-directory"`. With no committed phpunit.xml, Pest generates a temporary config, but its `file_put_contents` call is wrapped in `assert()` (Pest 4.7.5, `src/Plugins/Configuration.php`), which is never evaluated under the runner's `zend.assertions=-1`; the missing file made `--configuration` swallow the next CLI argument. Added a `phpunit.xml.dist` (Unit/Feature/Integration suites, `src/` coverage source) so Pest no longer needs the generated config. Verified locally with `php -d zend.assertions=-1 vendor/bin/pest`.

### Changed
- **Verified against Laravel 13.20.0** with no code changes required. The 13.20 queue changes target subsystems Torque does not use or replaces outright: the `WorkerStopping` event gained an optional `memoryUsage` argument, but Torque never constructs or listens to it (shutdown is Torque's own drain/limit logic and memory is tracked independently in `MetricsCollector`); the `Worker::currentMemoryUsage()` refactor lives in the Illuminate worker loop that `WorkerProcess` replaces; the `SqsQueue` size-method precedence fix is SQS-only; and `WithoutOverlapping` accepting `\UnitEnum` keys is backward compatible and flows through the standard `CallQueuedHandler` pipeline. Everything Torque extends or calls (`Queue` base, `Jobs\Job`, `CallQueuedHandler::getRunningCommand()`, `InspectedJob::fromPayload()`, the `WorkerInterrupted`/`WorkerPausing`/`WorkerResuming` signatures, `ExceptionHandler::shouldStopRetries()`) is untouched this window. Horizon has no releases since v5.47.2, nothing to mirror. Dependencies refreshed to `laravel/framework v13.20.0` + `webpatser/fledge-fiber v13.20.0.0`; test suite green (319 passed, 10 skipped).
- **Verified against Laravel 13.19.0** with no code changes required. The 13.18/13.19 queue changes target subsystems Torque does not use: the `SqsQueue::bulk()` SendMessageBatch rewrite is SQS-only (Torque is a Redis Streams driver and does not override `bulk()`), the `Queue::registerRollbackCallbacksForJobsThatDispatchAfterCommit()` extraction is a behavior-neutral refactor inherited through the base class, and the new `Release` / updated `FailOnException` / `ThrottlesExceptions` middleware run through the standard `CallQueuedHandler` pipeline Torque already uses. Horizon v5.45.6 through v5.47.2 contains only dashboard, CI, and dependency changes, nothing to mirror. Dependencies refreshed to `laravel/framework v13.19.0` + `webpatser/fledge-fiber v13.19.0.1`; test suite green.

## [0.12.0] - 2026-06-24

### Changed
- **Dashboard rewritten from a React SPA to Livewire 4 + Blade.** The dashboard at `/torque` is now a set of full-page Livewire components (overview, workers, queues, feed, inspector, dead-letter) sharing one Blade layout, with `wire:poll` driving live refresh and a Tailwind 4 stylesheet compiled to a self-contained `dist/torque.css`. This drops React, Vite-for-JS, and the `/torque/api/*` JSON layer; Livewire (a new hard dependency, `livewire/livewire ^4`) provides the runtime and bundled Alpine, so applications still need no `npm` step. No Flux or any paid UI library is used. The per-controller JSON shaping moved into reusable `Dashboard\Data\*` read-models that the components consume.

## [0.11.0] - 2026-06-24

### Added
- **Honor the exception handler's `dontRetry()` / `dontRetryWhen()` directives (Laravel v13.17.0+).** `WorkerProcess::handleFailure()` now asks the application's exception handler `shouldStopRetries($exception)` before scheduling a retry; a positive verdict fails the job immediately through the existing dead-letter path, regardless of remaining attempts, matching `Illuminate\Queue\Worker`. The call is guarded by `method_exists`, so handlers from older framework versions keep the previous attempt-count behaviour unchanged.

## [0.10.2] - 2026-05-20

### Fixed
- **`MasterProcess::readPid()` now verifies process identity, not just liveness.** It accepted any live process at the recorded PID as the running master via `posix_kill($pid, 0)`. When `storage/torque.pid` survives a container restart on a bind mount or persistent volume, its PID number is routinely recycled to an unrelated process (php-fpm, the test runner, ...); `torque:start` then aborted with "Torque is already running", `torque:status` reported a phantom master, and `torque:reload` / `torque:flush` / `torque:monitor` were misled the same way. `readPid()` now also confirms the process is a Torque master by checking `/proc/<pid>/cmdline` for `torque:start` before trusting the PID file — a recycled or dead PID is treated as stale, unlinked, and startup proceeds (the existing `pgrep`-based orphan sweep in `torque:start` still handles genuine orphans). On platforms without `/proc` (e.g. macOS) the command line cannot be read, so the check is inconclusive and the liveness test stands alone, matching the previous behaviour.

### Test infrastructure
- `tests/Feature/Process/MasterProcessPidFileTest.php`: the live-PID happy path now forks a child titled `torque:start` so the `/proc` command-line check recognises it; new case `readPid treats a recycled PID running an unrelated process as stale` pins the regression (Linux-only — skips where `/proc` is absent)

## [0.10.1] - 2026-05-20

### Fixed
- **`StreamQueue::later()` now records the delay in the job payload.** It called `createPayload()` without the `$delay` argument, so every delayed job was dispatched with `delay => null` in its payload (the actual scheduling still worked via the `:delayed` ZSET score, but anything reading `$payload['delay']` saw `null`). Mirrors the fix Laravel Horizon shipped in [#1759](https://github.com/laravel/horizon/pull/1759).

## [0.10.0] - 2026-05-17

### Added
- **`torque:reload` command for zero-downtime CI/CD deploys.** Default mode spawns a replacement master via `proc_open`, waits for it to take over `storage/torque.pid`, then signals the old master via SIGUSR2 to drain (pause pickup, wait for in-flight jobs to settle, SIGTERM workers, exit). `--drain` mode skips the spawn step and only signals; use it from systemd `ExecReload=`, a Kubernetes `preStop` hook, or a Supervisor recipe that owns spawning the replacement. Unlike a Reverb-style websocket reload, there is no socket to share: two masters can run briefly during the swap because the Redis queue claims jobs atomically. Static `$spawner` and `$readinessChecker` callables on `TorqueReloadCommand` are swappable in tests so the orchestrator can be exercised without booting real workers
- **SIGUSR2 drain handler on the master.** Subscribed alongside the existing SIGTERM/SIGINT handlers; wired into both the sync (`pcntl_sigtimedwait`) and async signal paths. On delivery the master sets a `drainRequested` flag; the next monitor tick promotes it to an active drain, writes the same `{prefix}paused` Redis key that `torque:pause` uses (workers see it on their existing 2s poll), and starts the `drain_grace_seconds` timer. When the timer elapses, the master sends SIGTERM to workers and the existing shutdown path takes over. Reuses `drain_grace_seconds` (default 10s, `TORQUE_DRAIN_GRACE`); no new config key

### Changed
- `MasterProcess::removePidFile()` now only unlinks `storage/torque.pid` when the file still points at our own PID. After a `torque:reload` swap, the replacement master has already rewritten the file; the draining old master must not clobber that. Symmetric with the fix applied to `webpatser/resonate` in its 0.2.0 release
- **Verified against Laravel 13.9.0** with no code changes required. The only new queue contract, `Illuminate\Contracts\Queue\PreparesForDispatch`, is a dispatch-time hook resolved by `PendingDispatch`, so it works transparently through Torque's standard `Dispatchable` job dispatch. The other 13.9 queue/worker changes target framework subsystems Torque does not use: `Worker::$timedOutExitCode` and the `queue:pause` / `Worker::$pausable` guard belong to the process-per-job `Illuminate\Queue\Worker` (Torque has its own Fiber worker lifecycle), SQS overflow storage is SQS-only (Torque is a Redis Streams driver), and the `Foundation\Cloud` managed-queue wrapper only activates under `LARAVEL_CLOUD_MANAGED_QUEUES=1`. The `illuminate/* ^13.8` constraint already resolves to 13.9.x, so no version bump. Test suite green on `laravel/framework v13.9.0` + `webpatser/fledge-fiber v13.9.0.0`

### Test infrastructure
- New `tests/Feature/Commands/TorqueReloadCommandTest.php` (5 cases): no PID file, `--drain` signals SIGUSR2 to the live PID, spawner failure, readiness timeout escalation, full reload completes when readiness reports OK and the old PID drains
- New `tests/Feature/Process/MasterProcessPidFileTest.php` (10 cases) backfilling the PID-file contract: `readPid` missing / stale-with-auto-unlink / live / symlink-guard, `writePidFile` atomic + symlink-refusal, `removePidFile` own-PID-only unlink plus regression test for the reload swap rule
- New `tests/Feature/Process/MasterProcessDrainTest.php` (5 cases) pinning the drain state machine: drainRequested promotion, grace-still-running, grace-elapsed-triggers-SIGTERM, idle no-op, no re-promotion while already draining
- Suite grows from 290 to 310 tests

## [0.9.0] - 2026-05-06

### Added
- **Laravel 13.8 worker pause/resume events.** `WorkerProcess` now dispatches `Illuminate\Queue\Events\WorkerPausing` and `Illuminate\Queue\Events\WorkerResuming` whenever the Redis-backed pause flag flips. Listeners written for stock Laravel queue workers fire transparently when running on Torque. One dispatch per flip across the 2-second pause poller, not one per tick. Mirrors the `WorkerPausing`/`WorkerResuming` events upstream added in `laravel/framework` 13.8.0 (PR #59895)
- **Laravel 13.8 queue inspection methods.** `StreamQueue::allPendingJobs()`, `StreamQueue::allReservedJobs()`, and `StreamQueue::allDelayedJobs()` returning `Collection<int, Illuminate\Queue\Jobs\InspectedJob>` aggregated across every queue name. Mirrors the `all*` inspection contract upstream added in `laravel/framework` 13.8.0 (PR #59997). Stream-to-Laravel mapping: pending = stream entries not in the consumer group's PEL, reserved = entries in the PEL with the XPENDING delivery count exposed as `attempts`, delayed = entries in the per-queue `:delayed` ZSET. Reserved aggregation is capped at 1000 per queue to bound memory; per-queue `pendingSize()` remains authoritative for totals

### Changed
- `WorkerProcess::applyPauseTransition()` extracted as a public static helper so the transition logic can be unit-tested without standing up a live event loop
- Inline payload-field extraction in `StreamQueue::pop()` extracted to a private static `extractPayload()` helper, reused by the new inspection methods. No behavior change for `pop()`
- Bumped minimum framework support to `laravel/framework ^13.8` (the version that introduces `WorkerPausing`/`WorkerResuming` and `InspectedJob`)

### Test infrastructure
- 7 new unit tests in `tests/Unit/Worker/WorkerProcessTest.php` covering the pause/resume transition: false to true, true to false, no-change idempotency, stuck-paused idempotency, full pause-then-resume cycle, custom connection/queue forwarding, and exception swallowing
- 9 new integration tests in `tests/Integration/StreamQueueInspectionTest.php` covering empty cases, multi-queue aggregation, pending to reserved transition on pop, XPENDING delivery count to `attempts` mapping (using XCLAIM redelivery), delayed-only queues, and aux-key filtering for the `paused` flag and `:delayed` ZSETs

## [0.8.0] - 2026-05-02

### Added
- **Optional igbinary serializer.** Set `TORQUE_SERIALIZER=igbinary` to encode the queue payload envelope as binary instead of JSON. Roughly 2x faster encode/decode on large payloads (~67% throughput gain on the `payload-large` workload), no measurable difference on small ones. Opt-in by design; JSON stays the default so `redis-cli XRANGE` keeps working during debugging. `composer.json` now `suggest`s `ext-igbinary`. `torque:start` prints a one-line install hint when the extension is missing and a `Serializer: igbinary` confirmation when active. Decode path sniffs the first byte (`{`/`[` or `\x00\x00\x00\x02`) so flipping the env var is safe while workers are running and in-flight messages organically drain
- **`torque:bench` artisan command.** Reproducible end-to-end throughput and latency measurement against your own hardware and workload. Seven workload profiles (`cpu`, `io`, `async-io`, `fanout`, `mixed`, `payload-small`, `payload-large`) covering the dimensions where serializer choice, Fiber concurrency, and worker count actually matter. Console output mirrors `torque:status` formatting; `--json=path` (or `--json=-` for stdout) emits a machine-readable schema for CI / diff workflows. v1 requires `--use-running-master`; self-spawning workers from inside the bench command is tracked as v1.1
- **Live `BenchAggregator`, `BenchRunner`, `BenchJob`, `BenchProbe` classes** under `src/Console/Bench/` for the bench machinery. `BenchProbe` is wired but per-stage breakdown (serialize / xadd / xreadgroup / unserialize / handle / xack) is v1.1; current output reports throughput, end-to-end latency p50/p95/p99, and median handle duration
- **`bench/horizon-comparison/` reproduction kit.** `HorizonBenchJob.php` (the symmetric mirror of `BenchJob` for the redis-driver side), `run-horizon-bench.php` (standalone driver, auto-detects the host Laravel app from cwd), and a step-by-step README. Lets anyone reproduce the headline numbers from `BENCHMARKS.md` instead of taking them on faith
- **`BENCHMARKS.md`.** Full apples-to-apples comparison vs Laravel `queue:work` (the engine Horizon runs underneath): up to 15x throughput on async I/O fan-out, 95% lower memory at equivalent throughput on the `fanout` workload. Methodology, raw numbers, igbinary A/B, memory-at-equivalent-throughput framing, and a "what this benchmark does NOT measure" section
- **README rewrite.** Hero claim now sits on defensible measured numbers. New "When to use Torque" / "When to use Horizon instead" sections so users self-select before installing. New "Working with databases (avoid the Eloquent trap)" section explaining why blocking PDO calls in handlers destroy Fiber concurrency and how to use `MysqlPool` or pre-fetch in dispatch instead. Live job progress now positioned as a first-class differentiator with a hero callout and updated compatibility table

### Fixed
- **`StreamJob::payload()` now decodes via `StreamQueue::decodePayload`** instead of inheriting Laravel's JSON-only base implementation. Without this, `TORQUE_SERIALIZER=igbinary` workers crashed in `StreamJob::__construct` with a `TypeError` on the readonly array property when the raw body was igbinary-encoded; jobs piled up and the bench tail timed out. Caught by adding the redis-driver Horizon comparison: the JSON path was incidentally exercising the only code path that worked
- `WorkerProcess::processMessage` now validates incoming payloads via `StreamQueue::isValidPayload` (which sniffs both formats) instead of `json_validate`, so igbinary blobs are no longer dropped as "corrupt"

### Changed
- `StreamQueue` constructor takes a new `serializer` parameter (default `'json'`, backwards compatible). `StreamConnector::connect()` reads `'serializer'` from the queue connection config and forwards it. New private `encodePayload`/`transcodeIncomingPayload` and public static `decodePayload`/`isValidPayload` helpers replace inline `json_encode`/`json_decode` at the four call sites in `pushRaw`, `laterRaw`, `release`, and the telemetry decode in `pushRaw`
- `config/torque.php` adds a top-level `'serializer' => env('TORQUE_SERIALIZER', 'json')` key
- Compatibility table in the README now reflects what Torque actually does for Eloquent (works, but blocks Fibers; use `MysqlPool` for fan-out) and adds rows for "Per-job event timeline" and "Live job progress" to make those differentiators visible

## [0.7.2] - 2026-04-30

### Added
- **Interruptible job support.** When a worker receives `SIGTERM` or `SIGINT`, it now dispatches `Illuminate\Queue\Events\WorkerInterrupted` once and then forwards the signal to every in-flight user command implementing `Illuminate\Contracts\Queue\Interruptible::interrupted(int $signal)`. Mirrors the behaviour `Illuminate\Queue\Worker` introduced in `laravel/framework` 13.7.0 (PRs #59833, #59848), broadened to Torque's N concurrent fibers per worker so all running commands get the callback before shutdown. Jobs that don't implement `Interruptible` are unaffected; an exception thrown from one job's `interrupted()` does not block the rest

### Changed
- `WorkerProcess::processMessage()` and `WorkerProcess::handleFailure()` now accept a prepared `StreamJob` rather than constructing one internally. The fiber loop builds the job up front so each slot's job can be tracked for signal forwarding. No public API change for users

### Test infrastructure
- Test base case (`tests/TestCase.php`) now registers `Livewire\LivewireServiceProvider` and `Flux\FluxServiceProvider`, which the Torque dashboard provider already requires at runtime. Without this, `Feature` tests booting `TorqueServiceProvider` failed with `Target class [livewire.finder] does not exist`

## [0.7.1] - 2026-04-17

### Fixed
- **Workers go silent and never rotate after their lifetime expires.** When a worker reached `max_jobs_per_worker` or `max_worker_lifetime`, the metrics, migration, and pause-check timers self-cancelled and the Fibers tried to return at the top of their loop. Any Fiber suspended inside `processMessage()` or a half-open Redis socket kept the EventLoop alive forever, so the process never exited, the master never saw SIGCHLD, no replacement was spawned, and the dashboard went blank with `0 workers` while delayed jobs piled up. The worker now installs a hard-exit deadline timer: once limits are reached it gives Fibers `drain_grace_seconds` (default 10s) to finish, then calls `exit(0)` so the master can respawn unconditionally
- Per-Fiber reader Redis client now issues a `PING` every ~30 idle iterations and recreates the client on failure, eliminating the half-open socket class of stalls (NAT timeout, `client_output_buffer_limit` kill, server restart) on long-lived workers

### Added
- `drain_grace_seconds` (env `TORQUE_DRAIN_GRACE`, default 10) controls how long Fibers get to drain on worker rotation before the hard exit
- `stall_warn_seconds` (env `TORQUE_STALL_WARN`, default 300) plus a 30s watchdog timer that logs `WARN slot N processing same job for Xs` for any slot whose current job exceeds the threshold. Surfaces hung user jobs before they age out the worker
- Monitor command now shows yellow `● DEGRADED` when the master is alive but no workers are reporting metrics, instead of misleading green `● RUNNING`

### Changed
- Migration, metrics, and pause-check timers no longer self-cancel when limits are reached. They keep firing through the drain window so the dashboard shows live data and delayed jobs keep migrating until the worker actually exits

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
