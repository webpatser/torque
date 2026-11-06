# Upgrade Guide

Torque keeps upgrades boring: the master records the installed version in Redis (`{prefix}version`) and, on the first `torque:start` after a deploy, runs the data steps for every version between the recorded one and the installed one. Nothing is required from you unless a section below says so, and a stored version below the oldest release you can upgrade from (1.4.0 since 2.0.0) refuses to start. `torque:status` shows the recorded data version next to the installed one.

## 1.4.x to 2.0.0

No data step, but not a drop-in upgrade either. 2.0.0 refuses to start on data older than 1.4.0, changes when jobs fail for every install, and changes defaults that reach you only in part, depending on your published `config/torque.php`. Read "Before you upgrade" and "Changed defaults" first. Consumer code needs no change unless it uses the classes listed under API changes.

### Before you upgrade

- **Get onto 1.4.x first.** The master refuses to start when `{prefix}version` holds a release below 1.4.0: it logs `Refusing to start: ...`, exits non-zero and leaves no lock, PID file or worker behind. A refused `torque:reload` leaves the old master running. Under Supervisor a refused start just fails again on every retry, so check this before the deploy with `redis-cli GET {prefix}version`. The path: deploy any 1.4.x, start a master once, then deploy 2.0.0.
- **The check passes** when nothing is stored, when the stored value is `dev`, and when Redis is unreachable at start (the check is skipped and logged; the stored version is still not overwritten, so the next start refuses).
- **Escape hatch: `SET {prefix}version 1.4.0`.** It skips the steps 2.0.0 removed: the 0.16.0 one-off cleanup and the 1.0.0 delete of `{prefix}metrics:buckets`. A stored 1.0.0 or later has already run both and loses nothing. A stored 0.16 to 0.18 is left with that one hash. Anything older also keeps its old leftovers (orphaned job streams, index members, stale consumers, worker hashes). None of this touches queued jobs. To clean up afterwards, run `php artisan torque:prune --deep` and `DEL {prefix}metrics:buckets`.
- **Install `laravel/slack-notification-channel` 3.6 or newer** if you have it at all. Composer now conflicts with anything below.
- **Decide about published config** (next two sections), and run `php artisan config:cache` again after the upgrade if you cache config.

### New config keys (all optional, defaults apply when absent)

| Key | Default | What it does |
|---|---|---|
| `release_limit_seconds` | `86400` | Env `TORQUE_RELEASE_LIMIT`. Seconds a job may keep releasing itself, from the available-at of its first release; `0` is off |
| `streams.<name>.release_limit_seconds` | none | Per-stream override of the above; `0` opts that stream out |
| `reload_pause_seconds` | `10` | Env `TORQUE_RELOAD_PAUSE`. How long a draining master waits for an idle fleet before it signals workers. Was `drain_grace_seconds` |
| `timeouts.release_delay_seconds` | `10` | Minimum delay of a job released after its timeout. Was `drain_grace_seconds` |
| `timeouts.settle_seconds` | `5` | Budget of `interrupted()` and the timeout listeners, at most half the grace |

New env vars on the shipped `default` stream: `TORQUE_QUEUE` (the stream's name), `TORQUE_RETRY_AFTER`, `TORQUE_MAX_RETRIES`, `TORQUE_BACKOFF`, `TORQUE_STREAM_TIMEOUT`. See "Stream env vars" below for when they are live.

### Changed defaults: which ones reach you

Laravel merges only top-level keys over the package file. **A key your published file contains keeps its value, `env()` fallback included.** A key it lacks takes the new default, and so does an app with no published file. A file published from 1.3 or 1.4 contains all of the keys below, so it flips none of them until you act. A file from before 1.3 has no `timeouts` or `loop_stall_kill_seconds`, so it inherits those. Check your file key by key; do not assume either outcome.

| Key | 1.x default | 2.0 default | To adopt it in a 1.4.x published file | To keep 1.x where it would flip |
|---|---|---|---|---|
| `strict_attempts` | `false` | `true` | `TORQUE_STRICT_ATTEMPTS=true` | `TORQUE_STRICT_ATTEMPTS=false` |
| `timeouts.enabled` | `false` | `true` | `TORQUE_TIMEOUTS=true` | `TORQUE_TIMEOUTS=false` |
| `memory_limit` | `0` | `512` | `TORQUE_MEMORY_LIMIT=512` | `TORQUE_MEMORY_LIMIT=0` |
| `loop_stall_kill_seconds` | `0` | `null`, derived | Copy the shipped line, or `TORQUE_LOOP_STALL_KILL=300` | `TORQUE_LOOP_STALL_KILL=0` |
| `drain_grace_seconds` | `10` | `null`, derived | Copy the shipped line and unset the env var | `TORQUE_DRAIN_GRACE=10` (logs a WARN) |
| `dashboard.enabled` | `true` | `false` | Nothing to adopt unless you want it off: set `false` | `TORQUE_DASHBOARD=true` |

Copy the shipped lines rather than only dropping the fallback: `(int) env('TORQUE_DRAIN_GRACE')` is a grace of 0, not a derived one. Merge by hand instead of `vendor:publish --force`, which overwrites your streams.

The release limit is the exception: no 1.x file has the key, so it applies to every install on the first deploy.

### Renamed config keys

Rename these in `config/torque.php` now. For this major version the old key still works: its value moves to the new key at boot, the old key wins over the new one if the file has both (so remove it), and the master logs `WARN Renamed config keys in use`, also under `config:cache`. **Torque 3.0 makes this a boot failure.**

| Old key | New key | Env |
|---|---|---|
| `coroutines_per_worker` | `slots_per_worker` | `TORQUE_COROUTINES` to `TORQUE_SLOTS_PER_WORKER` |
| `block_for` | `poll_interval_ms` | `TORQUE_BLOCK_FOR` to `TORQUE_POLL_INTERVAL_MS` |
| `redis.read_timeout` | `redis.read_timeout_seconds` | unchanged |
| `takeover_ready_timeout` | `takeover_ready_timeout_seconds` | unchanged |
| `job_streams` | `job_events` | none |
| `job_limits` (`job`/`stream`) | `honour_job_limits` (bool, `stream` maps to false) | `TORQUE_JOB_LIMITS` to `TORQUE_HONOUR_JOB_LIMITS` |

- Any `job_limits` value other than `stream` means true.
- Old env vars keep working silently in the shipped file through 2.x. A published file still on an old key reads only the old env var, so a new env var does nothing there until you rename the key.
- Change code that reads the old names: `config('torque.coroutines_per_worker')` is now null. A `config()->set()` of an old key after the service provider registered, as in a test, is not moved.
- A queue connection's own `block_for` in `config/queue.php` and the `--coroutines` CLI flag keep their names.

### Behaviour changes

- **Strict attempts (`strict_attempts`): leave headroom in `$tries`.** Jobs are checked before they run and fail without running once past their tries or `retryUntil`. A delivery after a worker went away counts too and ends with reason `redelivery_limit`, and restarts, rotations and other jobs' timeouts cause those. A job's own releases do not use tries; see the release limit.
- **Job timeouts (`timeouts.enabled`): audit every `$timeout` in your job classes before enabling.** Those values were ignored until now, so a forgotten low one starts ending jobs. Raise it or set `0` for no limit. A job with no `$timeout` on a stream with no `timeout` still has no limit: no stream default was added. A job past its limit is failed or retried, which counts as a try, and its whole worker rotates (exit 1). The other slots get the grace and survivors are redelivered, so duplicates happen. Blocking code never lets the timer fire; that is the watchdog's job.
- **Memory limit (`memory_limit`): check the ini value of the process running workers.** A worker at 512 MB real memory drains and exits with status 12. When ini `memory_limit` is lower, PHP fatals before that check, taking every slot with it, and a worker boot `WARN PHP memory_limit is ...` is the only signal. The CLI default of 128M triggers it. Raise the ini value or lower `TORQUE_MEMORY_LIMIT`. Unlimited ini (`-1`) is fine.
- **Release limit: `$tries` no longer ends a releasing job, time does.** On every install, self-releases stop counting against tries and count against `release_limit_seconds` instead. Anything that is not a worker-issued retry after a failure or timeout counts as a self-release, whether from middleware or your own code. Past the limit the job fails with `MaxAttemptsExceededException`, runs `failed()`, and reaches the dead letter with reason `release_limit`, also with `strict_attempts` off. `attempts()` still rises on every release. What to check:
  - **Deliberate polling with `$tries` plus `release()`.** `$tries = 3` with `release()` polling now ends at 24 hours, not at 3 releases. Add a `retryUntil`, or count in the job, if you relied on the old stop.
  - **Opting out.** `$tries = 0`, `release_limit_seconds = 0` or `streams.<name>.release_limit_seconds = 0`. `0` also lifts the limit from jobs already carrying a deadline. A `retryUntil` replaces both limits, and it is only checked before a run under `strict_attempts`.
  - **The deadline is checked at pickup, never mid-run,** same as `retryUntil`. It is set once, by the first release, to that release's available-at plus the limit. A job that released once and then sits past it, in a backlog, on a paused queue or on a slow backoff, dead-letters without running, even with tries left. Raising the limit does not move a deadline already written. A dead-letter retry or `queue:retry` starts fresh.
  - **Jobs in flight at the flip.** A job released under 1.x has a high `attempts` and no `torque_releases`, so under `strict_attempts` it can fail on its first delivery after the upgrade. Drain rate-limited queues on 1.4.x first, or keep strict off until those jobs have finished.
  - **Mass expiry trips the circuit breaker.** A permanent failure is a permanent failure: many jobs reaching the deadline together can pause the stream for `circuit_breaker.cooldown`. Set `'circuit_breaker' => false` on a stream where that is unwanted, or raise its limit before an expected long outage.
  - **`WithoutOverlapping` with its default `releaseAfter = 0` still spins.** The limit caps it at 24 hours, it does not slow it. Give it a `releaseAfter`.
  - **`job_limits: stream` (`honour_job_limits: false`) stays a pure kill switch** and has no release limit and no strict check: a job that keeps releasing is never stopped, and a WARN says so at every boot. Self-releases no longer count toward `max_retries` there either, and backoff follows tries used, not attempts.
  - The stock `queue:work` path ignores all of this.
- **Drain: regenerate your Supervisor config.** `drain_grace_seconds` used to do four jobs and is split into grace, reload pause, release delay and settle (see the tables). Null grace derives `min(300, longest stream timeout or retry_after)`, 60 at package defaults, instead of 10, so running jobs get time to finish. The worst case is pause + grace + `intervals.drain_slack_seconds`, 85 at package defaults.
  - **Run `php artisan torque:supervisor --path=...` after the upgrade, then `supervisorctl reread && supervisorctl update`.** An old `stopwaitsecs` of 60 SIGKILLs a 60-second drain. Set the same number in systemd or container stop timeouts, and in deploy tools that wait on `torque:stop` or `torque:reload`. A stream with a large `retry_after` and no `timeout` can raise the grace to 300, so 325 worst case; grace is a ceiling, and an idle fleet exits at once.
  - **A pinned grace is not corrected for you.** A 1.x published file keeps `env('TORQUE_DRAIN_GRACE', 10)`, and every worker logs `WARN drain_grace_seconds is 10s, below the 60s derived ...` once at boot. That WARN is the only signal. Jobs still running after 10 seconds are abandoned by a restart, and under `strict_attempts` that spends a try.
  - **`TORQUE_RELOAD_PAUSE` sets the pause** that used to follow the grace.
- **`cache:clear` no longer drains workers, unlike the stock worker.** When the `illuminate:queue:restart` key goes from set to gone (a `cache:clear` or an eviction) a worker logs one line, adopts the new baseline and keeps running. The next `queue:restart` is still seen. If your deploy relied on `cache:clear` to restart workers, call `queue:restart` or `torque:reload`.
- **Stream env vars: live only on a republish or in unpublished apps.** A published file keeps its literal `streams` block and ignores them. Before you republish or merge the shipped `default` entry, check what those vars hold today, since they already mean something in `config/queue.php`. MediumGuru's example `TORQUE_RETRY_AFTER` of 90 replaces its literal 60 on a republish, which also moves its derived grace and `stopwaitsecs`. `TORQUE_QUEUE` renames the stream. Streams with names other than `default` stay as literal entries in the file, and these vars never reach them.
- **Stall watchdog (`loop_stall_kill_seconds`): decide whether you accept it.** Null derives `max(300, longest retry_after)`, 300 at package defaults; an integer overrides it, and `0` or `TORQUE_LOOP_STALL_KILL=0` turns it off. The master SIGKILLs a worker whose event loop has not run for that long. Blocking PDO queries, CPU-bound fibers and sync clients all block the loop, so they kill their worker and with it every sibling slot. A killed job returns after `retry_after`, and under `strict_attempts` it counts toward `redelivery_limit`. Set the limit above your longest accepted blocking call. `storage` must be on a local disk.
  - **`WARN Loop stall watchdog` will now appear at boot on database-cache apps,** because worker timers read the default cache synchronously. It fires for a `database` store unless its connection is SQLite or a `fledge-` driver, and for a `redis`, `memcached` or `dynamodb` store whose timeout is unknown or at or past the limit. For `redis` or `memcached`, lower the timeout on that store's connection or raise the limit. A `database` or `dynamodb` store has no timeout Torque can read, so it warns at any limit: move the default cache store, accept the risk, or turn the watchdog off.
- **Dashboard off by default.** Without a published config the dashboard routes are gone after the upgrade; set `TORQUE_DASHBOARD=true` to keep them. A published file has the literal `true`, which does not flip: leave it or change it, and `TORQUE_DASHBOARD` does nothing there until you replace that literal with the shipped line.

### API changes

The public API is the `Torque` facade; `Stream\Streamable`, `JobStream`, `JobStreamRecorder`; `Support\StreamQueueResolver`; `Contracts\Silenced`; `Events\*`; the `torque` connection through `Queue::`; `torque:*` commands; config keys. Everything else may change without a major version.

- `StreamQueue`, `StreamJob` and `WorkerProcess` are now `@internal`. Move code off them, or expect to adapt it on any release. demarcify uses them only in `tests/Feature/TorqueReliabilityTest.php`.
- `UpgradeRunner`: `LEGACY_BUCKETS_SUFFIX` and the 0.16.0 and 1.0.0 steps are removed, and the constructor lost its `StreamHousekeeper` argument. The step mechanism stays, with an empty default. New `MINIMUM_STORED_VERSION` and `assertUpgradePath()`. `StreamHousekeeper::deepClean()` stays for `torque:prune --deep`.
- `WorkerId::parse()` no longer reads the `{host}-{pid}` form: such an id now comes back with a null pid. Only `{host}-{pid}-{hex8}` parses with one.
- The legacy Slack branch in `LongWaitNotification` is removed. `laravel/slack-notification-channel` below 3.6 is a composer conflict.
- New `StreamJob::triesUsed()`, `releases()`, `releaseDeadline()`, `retryAfterFailure()`; `StreamQueue::retryAfterFailure()`, `releaseLimitSeconds()`; `Support\DrainSettings`; `Support\LegacyConfig`.

### Automatic

- 2.0.0 owes no data steps. The first `torque:start` records `2.0.0` in `{prefix}version` and logs that the data is up to date.
- Payloads gain `torque_releases` and `torque_release_deadline` on a job's first release. Dead-letter retries reset them.
- After the first deploy, look in the log for: `Refusing to start`, `WARN Renamed config keys`, `WARN Loop stall watchdog`, `WARN PHP memory_limit`, `WARN drain_grace_seconds`.

### Per-app notes

- **Apps that pinned grace** (published `env('TORQUE_DRAIN_GRACE', 10)`): watch for the boot WARN, copy the shipped line, unset the env var, then regenerate Supervisor. In that order, so the regenerated `stopwaitsecs` sees the new grace.
- **Published apps and the dashboard:** all keep their literal `true`. Only unpublished apps and new installs flip, so skeletor needs `TORQUE_DASHBOARD=true` if it wants one.
- **`dev-main` apps (overheid.io):** a `composer update` pulls in 2.0 without a version bump to review, along with every flip its config does not pin. Its stored `dev` passes the gate. Do the audits above first, or pin a commit until then.
- **Koop shape (ikregeer.nl `SyncKoopDocumentFromApiJob`):** `ThrottlesExceptions` catches every throw and releases, so no tries are spent. Under 1.x an always-failing document looped forever; under 2.0 it dead-letters once, with reason `release_limit`, 24 hours after its first release. An API outage longer than the limit does the same to everything queued, and can trip the circuit breaker. If that stream must outlast that, set its `release_limit_seconds` or a `retryUntil` on the job, and watch the dead letter after the first day.

### Rollback (reasoned, not tested)

Going back to 1.4.x should work: the extra payload fields look harmless to 1.x, which reads only `attempts`, and 1.x logs a stored `2.0.0` as newer and leaves it alone. Published old keys still work on 1.x, but new keys do not, so revert `config/torque.php` with the code. Jobs that released under 2.0 keep their high `attempts`, so under 1.x with `strict_attempts` on they can fail on delivery. Restore the old `stopwaitsecs` only if you restored the old grace.

## 1.3.x to 1.4.0

No data step. **Upgrade promptly: the dashboard's `viewTorque` gate was not checked on Livewire update requests in v0.12.0 through v1.3.0**, so a user holding a valid page snapshot, such as an admin whose access was revoked, could keep calling dashboard actions, including `Dead` retry and purge. 1.4.0 checks the gate on every mount and update and fails closed. A host page embedding a `torque.*` component now gets a 403 on mount without the gate.

### New config keys (all optional, defaults apply when absent)

| Key | Default | What it does |
|---|---|---|
| `waits` | `[]` | Per-queue long-wait threshold in seconds, e.g. `['emails' => 60]`. Off for queues not listed. Route notifications with `Torque::routeMailNotificationsTo()` and `Torque::routeSlackNotificationsTo()` in a service provider; Slack needs `laravel/slack-notification-channel` |
| `silenced` | `[]` | Job classes whose successful runs are left out of the job records |
| `silenced_tags` | `[]` | Tags that silence a job; encrypted jobs have no tags, so they never match |

The Batches page needs `php artisan make:queue-batches-table` and a migration if you have not run batching yet.

### Behaviour changes

- **Job stream history.** A self-release no longer records `completed`. A job failed with `fail()` no longer records `completed` after `failed`. A permanently failed job's status is `failed` rather than `exception`, and a second exception after `fail()` is not written.
- **A job waiting longer than `job_streams.ttl` loses its `queued` event.**
- **`job_streams.enabled = false` also skips tag inspection.**
- **Tags exist only on jobs dispatched after the upgrade.** Jobs already queued have none until they drain.
- **Long-wait alerts run in a child process.** Register `LongWaitDetected` listeners in a service provider, not in code that only the web request boots.

## 1.2.x to 1.3.0

No data step. Timeouts, the watchdog and strict attempts stay off until you opt in; the two behaviour changes below apply to every install.

### New config keys (all optional, defaults apply when absent)

| Key | Default | What it does |
|---|---|---|
| `timeouts.enabled` | `false` | Enforce `$job->timeout() ?? streams.<name>.timeout`; `0` is no limit. A timeout rotates the whole worker (exit 1): other slots get `drain_grace_seconds` and survivors are redelivered, so duplicates happen. `JobTimedOut` is dispatched |
| `streams.<name>.timeout` | none | Seconds a job without its own `$timeout` may run. Keep it below `retry_after`; workers log a WARN at boot otherwise |
| `loop_stall_kill_seconds` | `0` | Master SIGKILLs a worker whose local heartbeat (`storage/framework/torque-heartbeats`) is older than this; `0` is off. Set it above your longest accepted blocking call |

`strict_attempts` (already present since 1.2.0) now also counts redeliveries: a job that keeps killing its worker reaches the dead-letter stream with reason `redelivery_limit`. Leave `$tries` headroom, since drains, rotations and other jobs' timeouts count too.

### Behaviour changes

- **Entries returned at drain change order, in every mode.** A worker that stops hands back what it prefetched or what a `popUsing` callback held back and never started. They go to the tail of the stream, so processing order changes. They are no longer left to be stolen after `retry_after`.
- **Redelivery bookkeeping runs in every mode.** A pass over redelivery counters happens during housekeeping per queue, whether or not `strict_attempts` is on. Counting and the dead-letter reason apply only under `strict_attempts`.
- **`Queue::popUsing('torque', ...)`** is supported: register under `torque` (`default` has no effect); the callback gets the comma-joined eligible queues.

## 1.1.x to 1.2.0

No data step. Read the behaviour changes before deploying, in particular the first two.

### New config keys (all optional, defaults apply when absent)

| Key | Default | What it does |
|---|---|---|
| `job_limits` | `job` | `job`: a job's `retryUntil`, `$tries`, `maxExceptions` and `$backoff` win over the stream's `max_retries` and `backoff`. `stream`: kill switch, only `max_retries` and `2**attempts` count |
| `strict_attempts` | `false` | Check attempts and `retryUntil` before a job runs, as `queue:work` does. Off, a job that calls `release()` is never stopped, which keeps `RateLimited` and `WithoutOverlapping` working. Workers log a boot line saying so |
| `memory_limit` | `0` | Megabytes of real memory at which a worker drains and exits with status 12. The drain waits for running jobs with no grace force exit, so a hung job holds the worker until a signal, `queue:restart` or the lifetime ends it (enable `timeouts.enabled` to end a hung job). `0` is off |
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

These flips shipped in 2.0.0: see [1.4.x to 2.0.0](#14x-to-200).

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
| Supervisors, `maxProcesses` | `workers` processes, each with `slots_per_worker` Fiber slots |
| `balance` / autoscaling | Slot-pressure autoscaling, per-stream `max_concurrency` |
| `tries`, `backoff` | A job's own properties, then `streams.<q>.max_retries` and `backoff` |
| `timeout` | Job `$timeout`, then `streams.<q>.timeout`; on by default since 2.0.0 (`timeouts.enabled`), opt-in from 1.3.0 |
| `memory` | `memory_limit` |
| `trim` / failed job retention | `dead_letter.ttl` and `max_entries`, `metrics.retention` |
| Tags, `silenced`, long-wait `waits` | Tags, `silenced`, `silenced_tags` and `waits`, since 1.4.0 |
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
