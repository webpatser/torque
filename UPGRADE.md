# Upgrade Guide

Torque keeps upgrades boring: the master records the installed version in Redis (`{prefix}version`) and, on the first `torque:start` after a deploy, runs the data steps for every version between the recorded one and the installed one. Nothing is required from you unless a section below says so. `torque:status` shows the recorded data version next to the installed one.

## 1.1.x to 1.2.0

No data step. Read the behaviour changes before deploying, in particular the first two.

### New config keys (all optional, defaults apply when absent)

| Key | Default | What it does |
|---|---|---|
| `job_limits` | `job` | `job`: a job's `retryUntil`, `$tries`, `maxExceptions` and `$backoff` win over the stream's `max_retries` and `backoff`. `stream`: kill switch, only `max_retries` and `2**attempts` count |
| `strict_attempts` | `false` | Check attempts and `retryUntil` before a job runs, as `queue:work` does. Off, a job that calls `release()` is never stopped, which keeps `RateLimited` and `WithoutOverlapping` working. Workers log a boot line saying so |
| `memory_limit` | `0` | Megabytes of real memory at which a worker drains and exits with status 12. The drain waits for running jobs with no grace force exit, so a hung job holds the worker until a signal, `queue:restart` or the lifetime ends it (timeouts arrive in 1.3.0). `0` is off |
| `force` | `false` | Keep processing during `artisan down` |
| `redis.read_timeout` | `30` | Seconds before a slot gives up on a Redis reply and reconnects (added in 1.1.2) |

### Behaviour changes

- **Honouring `artisan down`.** While the app is down, slots stop polling and claiming. In-flight jobs finish and jobs wait in the stream rather than get lost; polling resumes after `artisan up`. Set `force` to `true` (`TORQUE_FORCE`) to keep the old behaviour of processing during maintenance.
- **Per-job limits now apply.** A job's `retryUntil`, `$tries`, `maxExceptions` and `$backoff` take over from the stream's `max_retries` and `backoff`; `$tries = 0` means unlimited. Jobs that declare nothing behave as before. Set `job_limits` to `stream` to turn this off. A non-default stream `backoff` now takes effect, and exponential retries (`2**attempts`) are capped at 1 hour, also under `job_limits: stream`, and stop 2 seconds short of `retryUntil`. A declared `$backoff` or stream `backoff` stays as set.
- **Queue sizes changed meaning.** `size()` is now waiting plus delayed plus reserved, and `pendingSize()` is waiting only, as in the stock Redis queue. `pendingSize()` used to return the pending entries list, which is `reservedSize()`. In a case with 3 waiting, 2 delayed and 4 reserved, `size()` and `pendingSize()` went from 7 and 4 to 9 and 3. Autoscalers and alerts built on these numbers see new values; the dashboard gauges and Overview now show waiting jobs. The `torque:monitor` and `torque:status` columns follow the same meaning. `size()` after `queue:clear` still counts a running job.
- **`JobExceptionOccurred` fires after `JobFailed` on the fail path**, as in stock.
- **New events.** `WorkerStarting`, `WorkerStopping`, `WorkerIdle`, `WorkerQueuePaused`, `WorkerQueueResumed`, `JobPopping`, `JobPopped`, `JobReleased` and `JobReleasedAfterException` are dispatched. `JobPopping` fires on every poll (about 25 per second at 50 slots), so keep its listeners cheap. A throwing listener on the four job events does not change the job's outcome, unlike stock. A `Looping` listener returning false is honoured. `WorkerStopping` fires after the drain, or at the force exit; `memoryUsage` is in MB, and a hung listener blocks exit until the process is killed. `WorkerOptions` carries real values: `memory` is 0 when the limit is off and `maxTries` defaults to 3.
- **Per-attempt isolation grew.** `Log` context on every channel, `Auth` guards and `Cache::memo()` are now kept per attempt; see the Concurrency contract in the README for what stays shared.
- **Laravel Cloud.** Its listeners hold process-wide job state, so exception attribution is unreliable at more than one slot.

### Coming in 2.0

2.0 will flip these defaults: `strict_attempts` on, `timeouts.enabled` on and a default `memory_limit`. Set them explicitly now if you want the current behaviour to stay. Job timeouts (`$timeout` enforcement, `JobTimedOut`) arrive in 1.3.0, opt-in.

## Coming from Horizon

Concepts and commands, from Laravel's Horizon documentation:

| Horizon | Torque |
|---|---|
| `horizon` | `torque:start` |
| `horizon:status` | `torque:status`, `torque:monitor` for a live view |
| `horizon:pause` / `horizon:continue` | `torque:pause` / `torque:pause continue` |
| `horizon:terminate` | `torque:stop` (graceful), `torque:reload` (zero-downtime takeover) |
| `horizon:forget {id} --all` | `torque:forget {id?} {--all} {--queue=} {--force}` |
| `horizon:clear` | `queue:clear` |
| `horizon:supervisor` | None; one master runs the whole fleet. `torque:supervisor` generates a Supervisor config for the master |
| `horizon:snapshot` | None; the master publishes metrics itself |
| Supervisors, `maxProcesses` | `workers` processes, each with `coroutines_per_worker` Fiber slots |
| `balance` / autoscaling | Slot-pressure autoscaling, per-stream `max_concurrency` |
| `tries`, `backoff` | A job's own properties, then `streams.<q>.max_retries` and `backoff` |
| `timeout` | Job `$timeout` is not enforced before 1.3.0 |
| `memory` | `memory_limit` |
| `trim` / failed job retention | `dead_letter.ttl` and `max_entries`, `metrics.retention` |
| Tags, `silenced`, long-wait `waits` | Not available yet |
| Environments | `env()` in `config/torque.php` and `prefix`; see "Not planned" in the README |

Jobs need no change. Torque is a queue connection (`QUEUE_CONNECTION=torque`), so dispatching, batches and chains work as before. Blocking calls in a handler stall every job on that worker: see the README's section on databases.

## 0.18.x to 1.0.0

1.0.0 is the first stable release: from here on, breaking changes wait for a major version. It removes the remaining migration code for data written by 0.15.x and earlier.

### Before you upgrade

- **Coming from 0.15.x or older?** Upgrade to any 0.16, 0.17 or 0.18 release first and start the master once. Those releases migrate the pre-rollup `metrics:buckets` hash into the minute tier; 1.0.0 only deletes it. Skipping straight to 1.0.0 loses at most the last day of minute history (the minute tier's retention), and every other upgrade step still runs.

### Automatic

- A `1.0.0` data step deletes `{prefix}metrics:buckets` on the first `torque:start` after the deploy and logs it as `legacy keys: 1` (or `0` when it was already gone). It runs once, like every other step.

### Behaviour changes

- `MetricsPublisher` no longer looks for `metrics:buckets` on its first write or read, so a process costs one `EXISTS` less at startup.
- `torque:prune --deep` no longer deletes `metrics:buckets`. Its stale worker hash sweep is unchanged, and is now reported as `Stale worker hashes` instead of `Legacy metric keys`.

### API changes

- `StreamHousekeeper::deepClean()` returns a `worker_hashes` count in place of `legacy_keys`. Code reading that key from the array must switch to the new name.
- New `UpgradeRunner::LEGACY_BUCKETS_SUFFIX` constant (`metrics:buckets`).

Nothing else changes: config keys, env vars, the queue connection, Redis key layout and the `WorkerId` parser (which still reads the older `{host}-{pid}` ids) are the same as in 0.18.4.

## 0.17.x to 0.18.0

### Requirements

- `illuminate/*` `^13.34` (0.18.0). `JobProcessed::$duration` and the `#[CountCrashesAsExceptions]` payload flag need it.
- `webpatser/fledge-fiber` `^13.38` (0.18.2).

### Automatic

- No data step. The `#[CountCrashesAsExceptions]` processing marker (`job-processing:<uuid>`) lives in your default cache store, not in Torque's Redis prefix, and is removed when the attempt ends.

### Behaviour changes

- **`#[CountCrashesAsExceptions]` is honoured.** For a job with the flag and a `maxExceptions` limit, a worker that dies mid-job counts as one exception; once the limit is reached the job fails permanently instead of crashing the next worker. Set each stream's `retry_after` longer than its longest job, or a job stolen through `XAUTOCLAIM` while still running is counted as a crash.
- **A pending re-read no longer hands a running job to a second slot** (0.18.0). Jobs that ran twice concurrently after a slot's PEL re-read now run once.
- **`push()` and `later()` return `?string`** (0.18.3): null for an `afterCommit` job dispatched inside a transaction, which is enqueued on commit and dropped on rollback. Code that type-hints the result as `string` must accept null.
- **`#[\NoDiscard]` is gone from `push()`, `pushRaw()` and `later()`** (0.18.1), so `queue:retry` and `Bus::batch()` work on PHP 8.5.
- **igbinary payloads are only decoded when `torque.serializer` is `igbinary`** (0.18.4). If you switched from `TORQUE_SERIALIZER=igbinary` back to JSON with igbinary entries still queued, those entries now go to the dead-letter stream with reason `serializer_mismatch` instead of running. Drain the queues before switching serializers.
- **The dashboard redacts more** (0.18.4): secret-named properties inside the serialized `data.command`, `Authorization: Basic` credentials, and exception messages, traces and job events on the dead-letter screen and inspector. Unparseable serialized data is hidden entirely.
- **The master refuses a symlinked lock file** and creates its PID temp file exclusively (0.18.4). If `storage/torque.lock` is a symlink in your deploy layout, replace it with a regular file or let the master create it.
- **Queue enumeration uses `SCAN` instead of `KEYS`** (0.18.4), so dashboard loads no longer block Redis on a large keyspace.

### API changes

- `StreamQueue::push()` and `later()`: `?string` return type.
- `StreamQueue::decodePayload()` and `isValidPayload()` take an optional `$serializer` argument (defaults to `torque.serializer`).

## 0.16.x to 0.17.0

### Automatic

- Nothing to migrate. The per-host rollups are new keys, so an installation simply starts collecting them on the first `torque:start` after the deploy; until then the workers screen shows its live columns and an empty history.
- The dashboard's time range moves into the topbar and is remembered per session. Nothing persists server-side beyond the session, so there is no stored state to convert.

### New config keys (all optional, defaults apply when absent)

| Key | Default | What it does |
| --- | --- | --- |
| `dashboard.default_range` | `'1h'` | Range the dashboard opens on: `1h`, `24h`, `7d` or `90d` (no env var; it is a per-session choice in the topbar) |

### Behaviour changes

- **One range for the whole dashboard.** The 1h / 24h / 7d / 90d control used to sit in two card heads (the overview throughput chart and the jobs table) and drove only that card. It is now a topbar picker next to the refresh interval, remembered for the session, and every screen reads it: the throughput chart and its sparklines, the jobs table, the queues counters, the workers cards, the live feed and the dead-letter list.
- **The queues screen is range-scoped.** `processedToday` / `failedToday` are now `processed` / `failed` over the selected range, `throughput` is jobs per minute across that range instead of a fixed five-minute window, and the per-stream sparkline is finally real: it reads the `metrics:rollup:{tier}:{queue}` history that has always been written but never read, so it survives a reload.
- **The workers screen is grouped by host.** A card per machine instead of a card per worker process, with the live processes listed inside it. Worker ids are re-minted on every start, so a per-process row has no history to range over; the host does. `processed` / `failed` / `throughput` on a host row are range-scoped, while the per-process rows keep the lifetime counters straight off the heartbeat hash. A host that ran inside the range but has no live worker now shows as `gone` with its last-seen time rather than disappearing.
- **The live feed and the dead-letter list honour the range**, applied in Redis (`ZREVRANGEBYSCORE` on the job index, a millisecond-epoch low id on the dead-letter stream). Both sources are bounded by their own retention, so a 90d range shows what retention holds, not a guaranteed 90 days. The dead-letter header shows both the in-range count and the stream total.
- The job inspector has no fleet-wide time dimension, so the picker is not rendered there.

### API changes

- `WorkersData::get(string $range = '1h')` returns `['hosts' => ...]` instead of `['workers' => ...]`; each host row carries its live processes under `workers`.
- `QueuesData::get(string $range = '1h')`; the `processedToday` / `failedToday` keys are now `processed` / `failed`.
- `DeadLetterHandler::list()` and `listBefore()` take an optional `$sinceMs`; new `countSince(int $sinceMs)`.
- `JobStream::recentJobs()` and `JobsData::list()` take an optional `$since` (unix timestamp).
- New on `MetricsPublisher`: `recordHostOutcomes()`, `recordHostGauges()`, `hostsSeen()`, `hostSeriesMulti()`, `hostGaugeSeriesMulti()`, `normaliseHost()`, `tierSeconds()`.
- New `Webpatser\Torque\Dashboard\Support\Range`; `OverviewData::isValidRange()` and `JobMetricsData::isValidRange()` delegate to it.

### Redis side

Three new keys per tier plus an index: `metrics:rollup:{tier}:host` (field `{bucket}:{host}`), `metrics:gauge:{tier}:host` (field `{bucket}:{host}:{metric}`) and the `metrics:hosts` sorted set. Every host shares one hash per tier, so a publish tick costs seven extra round trips whatever the fleet size, but storage grows with the number of distinct hostnames seen inside the retention window: roughly 3 MB per host at the defaults.

On Kubernetes `gethostname()` is the pod name, which is re-minted on every rollout, so that window can hold far more hosts than you have machines. The workers screen stays readable either way (the index is scored by last-seen, so only hosts active inside the selected range are listed), but if that footprint matters, shorten `metrics.rollups.daily_days`.

## 0.15.x to 0.16.0

### Automatic

- One-off cleanup of leftovers the old code never expired: per-job event streams without a terminal expiry, `jobs:active` / `jobs:recent` members pointing at streams that are gone, the dead-letter stream trimmed to the new TTL and cap, consumer names of exited workers, and legacy metric keys. Runs once, logs a count per category through the master logger.
- Ongoing housekeeping every `dead_letter.prune_interval` seconds (default 300), so `dead_letter.ttl` is finally enforced without a scheduler.

Preview it before restarting the fleet if you like:

```bash
php artisan torque:prune --deep --dry-run   # report only
php artisan torque:prune --deep             # clean up now
```

### New config keys (all optional, defaults apply when absent)

Republish the config to get the documented blocks, or add only what you want to change:

```bash
php artisan vendor:publish --tag=torque-config --force
```

| Key | Default | Purpose |
|-----|---------|---------|
| `dead_letter.max_entries` | 100000 | Hard cap on the dead-letter stream, enforced on every write (`0` = TTL only) |
| `dead_letter.prune_interval` | 300 | Seconds between the master's housekeeping runs (`0` = off) |
| `circuit_breaker.*` | enabled | Pause a stream whose jobs fail permanently at a high rate; per-stream override via `streams.<queue>.circuit_breaker`, or `false` to opt out |
| `metrics.retention` | 86400 | Was 3600. Seconds of per-minute history |
| `metrics.rollups.hourly_days` / `daily_days` | 90 / 730 | Long-term rollups, cluster-wide, per stream and per job class |
| `dashboard.gauge_max` | null | Fixed gauge scale in jobs/min; `null` auto-fits the busiest minute of the last hour |

### Behaviour changes

- A stream whose permanent-failure ratio crosses `circuit_breaker.threshold` (default 0.9 over the last 100 outcomes) is paused for `cooldown` seconds. If a stream is expected to fail that hard and you still want it processed, set `'circuit_breaker' => false` on that stream.
- Dead-letter entries beyond `max_entries` are evicted oldest first. They remain in the framework's `failed_jobs` table, which the cap does not touch: schedule `queue:prune-failed --hours=168` for that side.
- The overview gauge shows a damped five-minute jobs/min figure instead of the instantaneous per-second rate times 60. The `throughput` field in the aggregate metrics hash is unchanged; `throughput_1m`, `throughput_5m`, `throughput_smoothed` and `jobs_last_hour` were added next to it.

### API changes (only relevant if you construct these classes yourself)

- `MetricsPublisher::__construct()` takes an optional third argument `settings` (the `torque.metrics` config block). Without it the package defaults apply.
- `MetricsCollector::recordJobCompleted()` / `recordJobFailed()` accept an optional queue name and job class.
- `DeadLetterHandler::__construct()` takes an optional `maxEntries`.

### Recommended on the Redis side

Set `maxmemory` and `maxmemory-policy noeviction` on the Redis instance Torque uses, so a runaway writer gets `OOM command not allowed` instead of the kernel killing Redis.
