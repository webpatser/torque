# Torque

**The queue that keeps spinning.** Coroutine-based queue worker for Laravel.

Torque replaces Horizon's 1-job-per-process model with N-jobs-per-process using PHP 8.5 Fibers. When a job waits on I/O, the scheduler switches to another job, so a handful of processes deliver the throughput Horizon needs dozens of processes for.

```text
2 workers x 50 coroutines = 100 concurrent jobs in ~120 MB RAM
equivalent Horizon throughput on async I/O ≈ 30 processes (~2.5 GB)
```

> [!NOTE]
> Numbers from the [fair benchmark](BENCHMARKS.md). On long-running async I/O (HTTP fan-out, slow external APIs) Torque delivers up to **15x throughput** at **95% lower memory footprint**. On pure CPU work it is comparable or slightly slower than Horizon, by design.

> [!TIP]
> **Live job progress, built in.** Every job records a per-job event timeline (queued / started / exception / completed) to a Redis Stream. Tail it from the CLI with `torque:tail --job=<uuid>`, read it programmatically, or stream it to the dashboard / your own UI. Custom progress events are a one-line `$this->emit('...', progress: 0.42)` away. No log scraping, no separate progress table, no extra Redis keys to manage.

## When to use Torque

- HTTP fan-out: calling N external APIs per job
- Slow external services (>50 ms latency per call)
- Webhook delivery to many endpoints
- Bulk operations against rate-limited APIs
- Search index updates, cache warming, notification fan-out
- Any I/O-bound workload where you currently scale by adding more Horizon processes

## When to use Horizon instead

- CPU-bound jobs (image processing, PDF generation, encoding, ML inference)
- Jobs using sync-blocking calls (`curl_exec`, `usleep`, `PDO` without an async wrapper)
- Mature ops tooling around Horizon (we are catching up, not there yet)
- Workloads where memory footprint per worker is not a concern

> [!IMPORTANT]
> Torque only wins when your jobs spend time waiting. If they spend time computing, the Fiber scheduler has nothing to switch to and you pay overhead for nothing. Use the right tool for the workload.

## Requirements

- PHP 8.5+
- Laravel 12+
- Redis 7+ or Valkey (Redis Streams support)
- [Revolt](https://revolt.run) event loop (installed automatically)

## Installation

```bash
composer require webpatser/torque
```

Publish the config:

```bash
php artisan vendor:publish --tag=torque-config
```

Add the queue connection to `config/queue.php`:

```php
'torque' => [
    'driver' => 'torque',
    'queue' => env('TORQUE_QUEUE', 'default'),
    'retry_after' => 90,
    'block_for' => 2000,
    'prefix' => 'torque:',
    'redis_uri' => env('TORQUE_REDIS_URI', 'redis://127.0.0.1:6379'),
    'consumer_group' => 'torque',
],
```

Set it as default in `.env`:

```
QUEUE_CONNECTION=torque
```

## Usage

### Starting the worker

```bash
php artisan torque:start
```

Options:

```bash
php artisan torque:start --workers=8 --concurrency=100 --queues=emails,notifications
```

### Dispatching jobs

Standard Laravel dispatching works unchanged:

```php
ProcessDocument::dispatch($document);
ProcessDocument::dispatch($document)->onQueue('high');
ProcessDocument::dispatch($document)->delay(now()->addMinutes(5));

// Batches work out of the box
Bus::batch([
    new ProcessDocument($doc1),
    new ProcessDocument($doc2),
    new ProcessDocument($doc3),
])->dispatch();
```

### Async jobs with TorqueJob

Regular Laravel jobs work fine; they run synchronously within their coroutine slot. For full async I/O, extend `TorqueJob` and type-hint the pools you need:

```php
use Webpatser\Torque\Job\TorqueJob;
use Webpatser\Torque\Pool\MysqlPool;
use Webpatser\Torque\Pool\HttpPool;

class IndexDocument extends TorqueJob
{
    public function __construct(
        private int $documentId,
    ) {}

    public function handle(MysqlPool $db, HttpPool $http): void
    {
        $result = $db->execute('SELECT * FROM documents WHERE id = ?', [$this->documentId]);
        $row = $result->fetchRow();

        $http->post('http://elasticsearch:9200/docs/_doc/' . $this->documentId, json_encode($row));
    }
}
```

### Working with databases (avoid the Eloquent trap)

> [!WARNING]
> Eloquent uses PDO, which is sync-blocking. **One `User::find($id)` in a handler stalls the entire worker** (and every other Fiber on it) until the round-trip completes. On a 25-coroutine worker that's effectively concurrency 1 for the duration of that call, which destroys the `fanout` advantage Torque is built around.

The fix is to either keep Eloquent out of the handler, or use the async `MysqlPool` for the queries that matter:

```php
// ❌ BAD: blocks the OS thread, every Fiber on this worker waits
public function handle(): void
{
    $user = User::find($this->userId);
    foreach ($user->subscriptions as $sub) {
        Http::post($sub->webhook_url, $payload);
    }
}

// ✅ GOOD (option 1): pre-fetch in dispatch, pass plain data into the handler
ProcessWebhooks::dispatch(
    userId: $user->id,
    webhooks: $user->subscriptions->pluck('webhook_url')->all(),
);

// ✅ GOOD (option 2): extend TorqueJob, use MysqlPool for async queries
class ProcessWebhooks extends TorqueJob
{
    public function handle(MysqlPool $db, HttpPool $http): void
    {
        $rows = $db->query('SELECT webhook_url FROM subscriptions WHERE user_id = ?', [$this->userId]);
        foreach ($rows as $row) {
            $http->post($row['webhook_url'], $this->payload);
        }
    }
}
```

**When sync Eloquent is fine**:
- The handler does at most one or two queries
- The queries are fast (<5 ms) and the worker isn't fanout-heavy
- You're running a CPU or low-concurrency workload where Fiber concurrency wasn't the goal anyway

**When sync Eloquent is a footgun**:
- HTTP fan-out jobs (the `fanout` workload from [BENCHMARKS.md](BENCHMARKS.md))
- Long-running queries (slow joins, large scans)
- Anywhere you'd otherwise be looking at "why am I not getting the throughput Torque promised"

Same pattern applies to other sync clients: `curl_exec`, `Guzzle` without a non-blocking handler, `usleep`, blocking file I/O. Replace with `HttpPool`, `Fledge\Async\delay()`, or pre-compute outside the handler.

### Per-Fiber state isolation

Use `CoroutineContext` when you need per-job isolated state (e.g., request-scoped data):

```php
use Webpatser\Torque\Job\CoroutineContext;

// Inside a job handler
CoroutineContext::set('tenant_id', $this->tenantId);
$tenantId = CoroutineContext::get('tenant_id');
```

State is automatically cleaned up when the Fiber completes (backed by `WeakMap`).

### Concurrency contract

One worker runs up to `slots_per_worker` jobs at once in a single PHP process and a single container. Torque isolates the framework state that jobs commonly write to; everything else is shared by all jobs on that worker. At `coroutines_per_worker = 1` nothing is shared and the stock worker semantics apply to every job.

**Isolated per attempt:**

- `Context`, including child fibers started through `async()`. A job starts with an empty `Context` plus its payload's, and dispatched child jobs carry only their parent's.
- `Log` context (`Log::withContext()` and `Log::channel('x')->withContext()`) on every channel.
- `Auth` guards, including `async()` children.
- `Cache::memo()` stores that exist at boot.
- `defer()` callbacks.

One gap: `Auth::setUser()` from a raw Fiber, an `EventLoop::delay()` callback, or a `Looping` or `JobPopping` listener writes to the ambient guard, which later attempts clone, for the worker's lifetime.

**Shared by every job on the worker:**

- The locale (`app()->setLocale()`), `config()->set()` and the default DB connection.
- Transactions on a shared connection: a `DB::beginTransaction()` in one job is visible to every job using that connection. Use a pool per job (`MysqlPool`) or keep transactions out of async handlers.
- User scoped bindings. The stock reset closure that drops scoped instances runs when a job starts and nothing else is in flight. At one slot that is every job; at more than one it happens only in quiet moments and may never happen under sustained load. At more than one slot the worker logs a boot WARN naming the scoped bindings that stay shared. Resolve per-job state inside the job instead of at boot. Bindings you know are safe to share (stateless services) go in `shared_scoped_bindings` (`TORQUE_SHARED_SCOPED_BINDINGS`, comma-separated) and are left out of that WARN; they are still shared.
- Services that captured the log manager, the auth manager or a guard during boot (mail log transports, for example), and objects nested inside a custom guard.
- `Cache::memo()` for a store added after boot, and the `image` binding.
- `Auth::shouldUse()`.
- Apps that bind their own log or auth manager: the boot WARN names them and Torque does not route them.
- `Log::build()` channels are not cached, so a later `Log::shareContext()` does not reach them.

If your jobs rely on any of the shared items, lower `slots_per_worker` for those streams or run them on a dedicated worker.

> [!NOTE]
> Laravel Cloud's queue listeners hold the current job in process-wide state. Torque dispatches the events anyway, but at more than one slot exception attribution on Cloud (which job an exception is reported against) is unreliable.

### Shared transactions

A database transaction belongs to a connection, and every job on the worker uses the same one. If job A hangs inside a transaction while job B is in flight, B nests in A's transaction. B's commit never reaches the database, B acks as a success, and when the worker exits the transaction rolls back, taking B's writes with it, silently. A's `failed_jobs` row and batch counts live in that same transaction (the dead-letter entry survives). On a timeout with other jobs in flight, Torque skips stock's rollback of the failed-jobs and batch connections, so it cannot destroy the other slots' work; a timeout on the only job in flight keeps the stock rollback. Only separate connections per job (a pool) or `coroutines_per_worker = 1` avoid the risk.
On such a timeout the worker logs a WARN when the failed-jobs connection has a transaction open, since the `failed_jobs` row may then be lost.

### Timeouts

Timeouts are on by default (`timeouts.enabled`, `TORQUE_TIMEOUTS`). They only limit jobs that declare a `$timeout`, or streams with a `timeout`; no stream timeout is set by default. Audit your `$timeout` values when upgrading from 1.x, where they were ignored. Set `TORQUE_TIMEOUTS=false` and `$timeout` means nothing again.

- **Limit.** `$job->timeout() ?? the stream's timeout`, in seconds; `0` or neither set means no limit. Set the stream default with `timeout` in `streams.<name>`. Keep it below `retry_after`; the worker logs a WARN at boot otherwise, because another worker can steal a job that is still running.
- **At expiry.** The job is told through `interrupted()`, then fails if `$failOnTimeout` is set or its attempts are up, and is otherwise released with a delay of at least `timeouts.release_delay_seconds` (10). `JobTimedOut` and `JobExceptionOccurred` are dispatched.
- **The worker rotates.** A timeout stops the whole worker (exit 1) and the master starts a replacement. The other slots get `drain_grace_seconds` (derived from your streams unless set) to finish; whatever is still running after that is redelivered. At-least-once means duplicates can happen, so keep `slots_per_worker` low on streams with jobs that tend to hang.
- **Budget.** `interrupted()` and the `JobInterrupted`, `JobTimedOut` and `JobExceptionOccurred` listeners share a bounded budget of `timeouts.settle_seconds` (5, at most half of `drain_grace_seconds`). The job is failed or released after that, also when one of them has not returned.
- **What is timed.** The timer spans `handle()` only. The stock worker's alarm also covers the `JobProcessing` and `JobProcessed` listeners; here those are not timed.
- **What can time out.** Only a job suspended on the event loop (a Redis, HTTP or async DB call). Code that blocks the process never lets the timer run; use the watchdog below.
- **After abandonment.** The job's fiber is not stopped, because throwing into it could return a connection to the pool mid-reply. Its code may run briefly until the worker exits.
- **Retry delay.** The release delay is at least `timeouts.release_delay_seconds`, so it can overshoot a `retryUntil`.

### Strict attempts and redelivery counting

With `strict_attempts` (`TORQUE_STRICT_ATTEMPTS`, on by default) a job is checked against its tries and `retryUntil` before it runs, and a delivery after the first counts against `$tries` or the stream's `max_retries`. A job that keeps killing its worker then reaches the dead-letter stream with `reason=redelivery_limit` instead of looping. A worker logs a boot WARN for every stream whose `max_retries` is 1 or lower under `strict_attempts`, since a worker death then dead-letters a job that does not declare its own `$tries`. Set `'warn_strict_attempts' => false` on a stream whose jobs all declare `$tries` to silence it.

Counted as a redelivery:

- A steal from another consumer, and a pending re-read.
- A failed release.
- Survivors abandoned by a drain, a rotation or another job's timeout.
- Entries still prefetched or held back when the worker is SIGKILLed or crashes.
- A `>` reply lost to a failed connection, or still unanswered when the grace ends.

Not counted:

- A worker re-reading its own entry.
- Entries returned at drain: prefetched entries and ones a `popUsing` callback held back that never started go back to Redis unchanged. They move to the tail of the stream, so processing order changes. This applies in every mode, `strict_attempts` or not.

Consequences:

- Leave `$tries` headroom. A job can reach its limit through other jobs' timeouts and rotations; raise `drain_grace_seconds` and keep concurrency low on hang-prone streams.
- With `max_retries` at 1, any worker death dead-letters a job that does not declare its own `$tries` (which overrides `max_retries`). Leave headroom in `max_retries` or set `TORQUE_STRICT_ATTEMPTS=false`.
- A job with `retryUntil` is limited only by its deadline.
- A job running past `retry_after` on a live worker is charged when another worker steals it.

### Blocked event loop watchdog

A job that blocks the process (`sleep()`, a blocking PDO query, a blocking extension) freezes every slot and timer of its worker, so timeouts cannot fire. The watchdog is on by default: `loop_stall_kill_seconds` (`TORQUE_LOOP_STALL_KILL`) derives `max(300, longest stream retry_after)`, 300 at package defaults; an integer overrides it and `0` is off. Workers touch a heartbeat file in `storage/framework/torque-heartbeats` about once a second; the master sends SIGKILL to a worker whose file is older than the limit and starts a replacement.

- The heartbeat is local, so `storage` must be on a local disk (the mtime is compared to the host's clock). No Redis is involved, so an outage cannot kill workers.
- A kill takes every slot in that worker, and also hits a process stopped with SIGSTOP or paused in a debugger.
- Set the limit above the longest blocking call you accept. Set your cache store's timeout below the limit, as the boot WARN says (it prints at boot on database-cache apps): the worker's timers read the default cache store, so a network hang there blocks the loop.
- Killed jobs stay pending and are redelivered after `retry_after`. With `strict_attempts` those redeliveries count against the job's limit; without it a job that always blocks loops forever. A kill also takes the sibling slots of that worker.
- A worker that has not yet written a heartbeat is never killed, so a hang during boot is not covered.

### `Queue::popUsing()`

Register the callback for the `torque` connection name: `Queue::popUsing('torque', fn ($queue) => ...)`. Registering under `default` has no effect. The callback receives the comma-joined list of queues this poll may read (those below their `max_concurrency`), not the full configured string.

## Live job progress (built in)

Every job automatically records a lifecycle timeline to a per-job Redis Stream: queued, started, exception, completed, plus any custom events you emit. Watch it live from the CLI, the dashboard, or your own UI without instrumenting each job by hand.

```bash
$ php artisan torque:tail --job=088066c1-b045-4fb6-bc32-ca15cfdf7d08

queued 11:08:34  App\Jobs\ScrapeKvK -> scrpr
started 11:10:28  worker=web-01-4879 attempt=1
exception 11:10:52  attempt=1 No alive nodes. All the 1 nodes seem to be down.
started 11:11:34  worker=web-01-4882 attempt=2
completed 11:11:34  memory=58.5MB
```

### Custom progress events

Add the `Streamable` trait to emit progress from inside your job:

```php
use Webpatser\Torque\Stream\Streamable;

class ImportCsv implements ShouldQueue
{
    use Streamable;

    public function handle(): void
    {
        foreach ($this->rows as $i => $row) {
            // process...
            $this->emit("Imported row {$i}", progress: $i / count($this->rows));
        }
    }
}
```

### Reading streams programmatically

```php
use Webpatser\Torque\Stream\JobStream;

$stream = app(JobStream::class);

// All events so far
$events = $stream->events($uuid);

// Tail (blocks, yields events as they arrive)
foreach ($stream->tail($uuid) as $event) {
    echo $event['type'] . ': ' . ($event['data']['message'] ?? '');
}

// Check completion
$stream->isFinished($uuid); // true after completed/failed
```

Streams auto-expire after 5 minutes (configurable via `job_events.ttl`).

## Redis Cluster Support

Torque supports Redis Cluster out of the box. Enable it in your `.env`:

```
TORQUE_CLUSTER=true
```

When cluster mode is enabled, all Redis keys for a given queue are wrapped in hash tags (`{queue-name}`) so they land on the same cluster slot. This ensures Lua scripts and multi-key operations work correctly across the stream, delayed set, and notification keys.

If your queue names already contain hash tags (e.g., `{myqueue}`), Torque will not double-wrap them.

## CLI Commands

| Command | Description |
|---------|-------------|
| `torque:start` | Start the master + worker processes |
| `torque:stop` | Graceful shutdown (SIGTERM). Use `--force` for SIGKILL |
| `torque:status` | Show worker metrics, throughput, and queue depths |
| `torque:monitor` | Live htop-style terminal dashboard |
| `torque:tail` | Tail a job's event stream in real-time |
| `torque:pause` | Pause job processing (in-flight jobs complete). Dispatches `WorkerPausing` to any registered listener |
| `torque:pause continue` | Resume processing. Dispatches `WorkerResuming` |
| `torque:reload` | Zero-downtime reload via the takeover handshake: spawns `torque:start --takeover`, whose fleet must heartbeat before it claims the PID file and drains the old master. `--drain` for supervisor-driven setups (the supervisor owns the respawn) |
| `torque:prune` | Trim the dead-letter stream (TTL + cap) and delete stale stream consumers. `--deep` also sweeps orphaned job streams, stale index members and stale worker hashes; `--dry-run` to preview |
| `torque:forget {id?} {--all} {--queue=} {--force}` | Delete a dead-letter entry by its stream id (like `1700000000000-0`, shown by the dashboard and `torque:tail`), or with `--all` every entry; uuids and `queue:failed` ids are rejected. `--queue` limits `--all` to one queue, `--force` skips the confirmation. Only `database-uuids` `failed_jobs` rows are removed with the entry; `--all` leaves rows that have no dead-letter entry. Horizon's `horizon:forget` |
| `torque:supervisor` | Generate a Supervisor config file. `--php` sets the PHP binary (default `PHP_BINARY`), `--app-path` overrides the app path (a Deployer `releases/N` path uses the `current` symlink automatically), `--stdout` prints the config instead of writing it |

## Configuration

All options are in `config/torque.php`. Key settings:

| Setting | Default | Description |
|---------|---------|-------------|
| `workers` | 4 | Number of worker processes |
| `slots_per_worker` | 50 | Concurrent job slots per worker (`TORQUE_SLOTS_PER_WORKER`; the old `TORQUE_COROUTINES` still works) |
| `max_jobs_per_worker` | 10000 | Restart worker after N jobs (prevents memory leaks) |
| `max_worker_lifetime` | 3600 | Restart worker after N seconds |
| `max_worker_lifetime_jitter` | 0.1 | Random slice each worker subtracts from its own lifetime, as a ratio. The master forks the fleet inside one second, so without this every worker rotates in the same second. Only ever subtracts; `0.0` disables |
| `drain_grace_seconds` | null | Ceiling on the seconds Fibers get to finish in-flight jobs before the worker hard-exits on rotation (`TORQUE_DRAIN_GRACE`); how long a draining master waits for an idle fleet is `reload_pause_seconds`. Null derives `min(300, longest stream timeout or retry_after)`, 60 at package defaults; an explicit value wins, and a worker logs a WARN at boot when it is below the derived one. An idle worker or fleet exits immediately. The derived value equals the longest stream timeout or `retry_after` (max 300); an explicit value may exceed the 300 cap, but for takeover restarts (`torque:reload` in its default takeover mode, and `torque:start --replace`) a grace at or above the stream `retry_after` lets the two-fleet overlap double-claim jobs; `--drain` restarts are unaffected, and long-running streams may exceed `retry_after` there. `torque:reload`, `torque:stop` and the `stopwaitsecs` of a generated Supervisor config derive from it; regenerate the Supervisor config after changing it |
| `reload_pause_seconds` | 10 | Seconds a SIGUSR2 reload waits for the fleet to go idle before workers are told to stop (`TORQUE_RELOAD_PAUSE`) |
| `takeover_ready_timeout_seconds` | 30 | Seconds a takeover replacement waits for its own workers' first heartbeat before aborting the reload and leaving the old master untouched |
| `metrics.enabled` | true | Master publishes the aggregated fleet metrics hash (real throughput from counter deltas) every `metrics.publish_interval` seconds, with a TTL so a dead publisher reads as no data |
| `metrics.retention` | 86400 | Seconds of per-minute history kept (about 20 bytes per minute) |
| `metrics.rollups.hourly_days` / `daily_days` | 90 / 730 | Days of hourly and daily `processed,failed` rollups kept, cluster-wide and per stream. Roughly 60 KB in total plus the same per stream; `daily_days` `0` keeps them forever (~7 KB a year) |
| `dashboard.enabled` | false | The dashboard is off by default; `TORQUE_DASHBOARD=true` turns it on |
| `dashboard.gauge_max` | null | Scale of the overview gauge in jobs/min; `null` fits it to the busiest minute of the last hour |
| `stall_warn_seconds` | 300 | Watchdog logs a WARN for any slot whose current job has been running longer than this |
| `dead_letter.ttl` | 604800 | Seconds a dead-lettered job is kept before it is trimmed |
| `dead_letter.max_entries` | 100000 | Hard cap on the dead-letter stream, enforced on every write (`XADD MAXLEN ~`). `0` = TTL only |
| `dead_letter.prune_interval` | 300 | Seconds between the master's own housekeeping runs (dead-letter trim + stale consumer sweep). `0` disables it |
| `circuit_breaker.enabled` | true | Pause a stream whose jobs are failing permanently at a high rate |
| `poll_interval_ms` | 2000 | Poll interval in ms: how often idle Fibers check for new jobs (`TORQUE_POLL_INTERVAL_MS`; the old `TORQUE_BLOCK_FOR` still works). Not the connection's `block_for` |
| `redis.cluster` | false | Enable Redis Cluster hash tag support |
| `redis.read_timeout_seconds` | 30 | Seconds a slot waits for a Redis reply before it gives up and reconnects (`TORQUE_REDIS_READ_TIMEOUT`) |
| `memory_limit` | 512 | Megabytes of real memory (`memory_get_usage(true)`) at which a worker drains and exits with status 12, like `queue:work --memory`; the master starts a replacement. Checked once a second. The drain waits for running jobs with no grace force exit, so a hung job holds that worker until a signal, `queue:restart`, the worker lifetime or `max_jobs_per_worker` ends it, and the stall watchdog only warns. Keep `timeouts.enabled` on to end a hung job. A worker logs a WARN at boot when PHP's ini `memory_limit` is below this, since PHP would fatal first. `0` is off (`TORQUE_MEMORY_LIMIT`) |
| streams (`default`) | see file | Shipped `default` stream reads `TORQUE_QUEUE`, `TORQUE_RETRY_AFTER` (60), `TORQUE_MAX_RETRIES` (3), `TORQUE_BACKOFF` (`exponential`) and `TORQUE_STREAM_TIMEOUT` (none). A published config replaces the whole `streams` block, so the env vars apply only to the unpublished file |
| `force` | false | Keep processing during `artisan down`, like `queue:work --force`. Off, slots stop polling and claiming while the app is down; in-flight jobs finish and jobs wait in the stream |
| `honour_job_limits` | true | `true`: a job's `retryUntil`, `$tries`, `maxExceptions` and `$backoff` win over the stream's `max_retries` and `backoff`. `false`: kill switch, only `max_retries` and 2**attempts count, for every job, with no release limit (`TORQUE_HONOUR_JOB_LIMITS`; the old `TORQUE_JOB_LIMITS=stream` still maps to false) |
| `strict_attempts` | true | Also check tries and `retryUntil` before a job runs, as `queue:work` does, and count redeliveries (`TORQUE_STRICT_ATTEMPTS`). Self-releases from `RateLimited`, `WithoutOverlapping` or `release()` do not use up tries; they count against `release_limit_seconds` |
| `shared_scoped_bindings` | `[]` | Scoped bindings that are safe to share between concurrent slots, left out of the boot WARN about shared scoped bindings (`TORQUE_SHARED_SCOPED_BINDINGS`, comma-separated). Sharing and resets are unchanged |
| `release_limit_seconds` | 86400 | Seconds a job may keep calling `release()`, from its first release; past it the job dead-letters with reason `release_limit`. `0` is off; a stream can override it; `retryUntil` replaces it and `$tries = 0` opts out (`TORQUE_RELEASE_LIMIT`) |
| `timeouts.enabled` | true | Enforce `$job->timeout() ?? stream timeout` for jobs that declare one (`TORQUE_TIMEOUTS`). A timeout rotates the whole worker; see Timeouts |
| `timeouts.release_delay_seconds` | 10 | Minimum delay when a timed-out job is released |
| `timeouts.settle_seconds` | 5 | Budget for `interrupted()` and the timeout listeners; at most half of `drain_grace_seconds` |
| `loop_stall_kill_seconds` | null | SIGKILL a worker whose heartbeat file is older than this many seconds (`TORQUE_LOOP_STALL_KILL`). Null derives `max(300, longest retry_after)`, 300 at package defaults; `0` is off. See Blocked event loop watchdog |

### Autoscaling

```php
'autoscale' => [
    'enabled' => true,
    'min_workers' => 2,
    'max_workers' => 8,
    'scale_up_threshold' => 0.85,   // Scale up when 85% of slots are busy
    'scale_down_threshold' => 0.20, // Scale down when 20% of slots are busy
    'cooldown' => 30,               // Seconds between scaling decisions
],
```

### Connection pools

```php
'pools' => [
    'redis' => ['size' => 30, 'idle_timeout' => 60],
    'mysql' => ['size' => 20, 'idle_timeout' => 60],
    'http'  => ['size' => 15, 'idle_timeout' => 30],
],
```

### Circuit breaker

When a stream's dependency dies (an API returning 500s, expired credentials), every job on it fails permanently and the whole backlog burns straight into the dead-letter stream. The breaker stops that: once the permanent-failure ratio of a stream's recent outcomes crosses `threshold`, that stream is no longer polled for `cooldown` seconds. Other streams keep running.

```php
'circuit_breaker' => [
    'enabled' => true,
    'window' => 100,        // outcomes per stream in the sliding window
    'min_samples' => 20,    // never trip below this many outcomes
    'threshold' => 0.9,     // permanent-failure ratio that opens the breaker
    'cooldown' => 300,      // seconds the stream stays paused before half-open
    'half_open_max' => 5,   // probe jobs allowed while half-open
    'retention' => 3600,    // TTL on the outcome window keys
],
```

Only permanent failures (jobs that reached the dead-letter stream) count as failures; completions count as successes and a retry is neutral. After the cooldown the breaker goes half-open: up to `half_open_max` jobs are let through, all of them failing re-opens it for another cooldown, and the first success closes it and resets the window.

Override per stream, or opt a stream out entirely:

```php
'streams' => [
    'scrpr' => ['circuit_breaker' => ['threshold' => 0.8, 'cooldown' => 600]],
    'mail'  => ['circuit_breaker' => false],
],
```

State is shared across the whole fleet through Redis (every key carries an expiry) and an open breaker is expressed as a paused queue, exactly like `php artisan queue:pause <name>`. Listen for `QueueCircuitOpened` / `QueueCircuitClosed` to alert on it, check `torque:status` for a line per tripped stream, and force one closed with `php artisan torque:pause continue` or `php artisan queue:resume torque:<queue>` once the upstream problem is fixed.

## Dashboard

Torque includes a self-contained dashboard at `/torque` (configurable). It is built with **Livewire 4** and plain Blade + Tailwind, served Horizon/Pulse-style: a set of full-page Livewire components share one Blade layout, and the compiled `dist/torque.css` is inlined into it. Livewire (a hard dependency) ships its own runtime and bundled Alpine through your app, so there is **no React, no Flux, no paid UI library, no host Tailwind or Vite build, and no `npm` step in your application**. The dashboard is off by default as of 2.0. Enable it with `TORQUE_DASHBOARD=true` or in config:

```php
'dashboard' => [
    'enabled' => true,
],
```

Features:
- Real-time metrics (throughput, latency, concurrent jobs, memory) with a damped throughput gauge: the needle shows a smoothed five-minute jobs/min figure plus the exact count for the last hour, so a burst of 1500 jobs every five minutes reads as 300/min instead of 0 and then 90K
- Long-term history: minute, hour and day rollups (cluster-wide and per stream) power the 1h / 24h / 7d / 90d throughput chart and the processed / failed today columns on the Queues page
- Worker table with coroutine slot usage bars
- Jobs page: throughput, average and peak runtime, and fail rate per job class over 1h / 24h / 7d / 90d
- Stream/queue overview with pending and delayed counts
- Failed jobs list with retry and delete actions (cursor-paginated)
- Per-job inspector with a timeline of lifecycle events and exception details
- Live feed with a rolling stream tail and status filters
- Batches page: read-only list of bus batches with a detail panel (needs `php artisan make:queue-batches-table`; at most 100 failed job ids per view)
- Tag filter on the live feed (exact match over the latest 100 jobs)
- Kibana-style configurable poll interval (1s to 30s, or paused)

### Assets

The dashboard ships pre-built. The compiled `dist/torque.css` lives in the package and is inlined into the layout at request time, so there is nothing to publish and no build step in your application. The reactivity comes from Livewire's own runtime, which your app already loads; the chrome (refresh popover, theme and sidebar toggles, copy button, row links) is a small nonce'd vanilla script in the layout. If you are working on Torque itself, rebuild the stylesheet with:

```bash
npm install
npm run build   # writes dist/torque.css (Tailwind 4)
```

### Content-Security-Policy

The dashboard works under a strict CSP with a nonce and needs no `'unsafe-eval'`. Torque stamps its inline `<style>` and `<script>` tags with whatever nonce you set, either through `Vite::useCspNonce()` (picked up automatically, same convention Livewire follows) or explicitly:

```php
\Webpatser\Torque\Torque::cspNonce($nonce);
```

The dashboard chrome uses no Alpine expressions on purpose: Alpine compiles every directive expression (`x-data`, `x-show`, `@click`, `:class`, `$store`) with `new Function`, which a `script-src` without `'unsafe-eval'` blocks, and the expressions then fail silently. Everything is delegated `data-torque-*` handlers in that one nonce'd script instead, and chrome actions that reach the component (the refresh interval) go through `Livewire.find(id).call(...)`, plain JS with nothing to interpret. If your policy omits `'unsafe-eval'`, also set `'csp_safe' => true` in `config/livewire.php` so Livewire loads its CSP-safe bundle for the `wire:` directives. The dashboard checks this on every response: a policy without `'unsafe-eval'` while `csp_safe` is off shows a warning banner in the chrome and logs one line per hour, so the mismatch never fails silently.

### Authorization

The gate `viewTorque` is checked on every dashboard route (overview, workers, queues, feed, inspector, dead-letter, batches) and again on every Livewire mount and update, so no screen or Livewire action is reachable by users who fail the gate, including a user whose access was revoked after the page loaded. A host page embedding a `torque.*` component gets a 403 on mount without the gate. Define it in your `AuthServiceProvider`:

```php
Gate::define('viewTorque', fn (User $user) => $user->isAdmin());
```

If you don't define a gate, Torque falls back to `app()->environment('local')`; the dashboard shows up in development but stays locked in production until you define the gate explicitly.

Retries from the failed-jobs page only accept targets that exist in `config('torque.streams')`, so a compromised session cannot inject jobs into arbitrary Redis streams.

### Dashboard middleware

Default: `['web', 'auth']`. Override in config:

```php
'dashboard' => [
    'enabled' => true,
    'path' => 'torque',
    'middleware' => ['web', 'auth', 'can:admin'],
],
```

## Failed jobs

Jobs that exhaust all retries are moved to a dead-letter Redis Stream. You can:

- View them in the dashboard
- Retry or delete via dashboard or programmatically. A retry resets attempts, refreshes `retryUntil`, clears the `maxExceptions` counter, refuses when the job class no longer exists, and deletes the `failed_jobs` row (`database-uuids` failer only)
- Listen for the `JobPermanentlyFailed` event for custom notifications

```php
use Webpatser\Torque\Events\JobPermanentlyFailed;

Event::listen(JobPermanentlyFailed::class, function ($event) {
    // $event->jobName, $event->queue, $event->exceptionMessage, etc.
    Notification::route('slack', '#alerts')->notify(new YourNotification($event));
});
```

### Dead-letter retention

The stream is bounded in two ways, so a failure storm can never fill Redis:

```php
'dead_letter' => [
    'ttl' => 604800,           // entries older than 7 days are trimmed
    'max_entries' => 100000,   // hard cap, applied on every write (0 = TTL only)
    'prune_interval' => 300,   // seconds between the master's housekeeping runs (0 = off)
],
```

`max_entries` is enforced by the write itself (`XADD ... MAXLEN ~`), which is what keeps the stream bounded even during a storm; trimming is approximate, so the real length settles slightly above the cap in exchange for an O(1) write. Size it against your payloads: at ~6 KB per entry (payload plus truncated trace) the default is roughly 600 MB worst case.

The master applies the TTL, the cap, and a sweep of consumer names left behind by exited workers on its first tick after start and every `prune_interval` seconds after that, so nothing has to be scheduled. Run the same pass by hand whenever you want:

```bash
php artisan torque:prune --dry-run        # report only
php artisan torque:prune --dead-letter-max=50000 --consumer-idle=3600
```

## Architecture

```
Master Process (torque:start)
+-- Worker 1 (Revolt event loop)
|   +-- 50 Fibers (non-blocking poll + yield)
|   +-- Redis Pool
|   +-- MySQL Pool
|   +-- HTTP Pool
+-- Worker 2
|   +-- ...
+-- Worker N
|   +-- ...
+-- AutoScaler (optional)

Redis Streams
+-- torque:{default} (XREADGROUP consumer groups)
+-- torque:{default}:delayed (sorted set, cluster-safe)
+-- torque:stream:dead-letter
+-- torque:worker:* (per-worker stats with heartbeat TTL)
+-- torque:job:* (per-job event streams, auto-expiring)
```

### How it works

1. **Master** spawns N worker processes via `pcntl_exec()` (`php artisan torque:worker`)
2. Each **worker** runs a Revolt event loop with M Fiber slots
3. Each Fiber polls for messages with non-blocking `XREADGROUP` (no BLOCK). When no work is available, the Fiber yields to the event loop with a configurable delay (`poll_interval_ms` / 1000 seconds). This ensures timers (delayed job migration, metrics, pause checks) always fire reliably
4. Fiber startup is staggered across the poll interval so polling is evenly distributed
5. **Work-stealing**: idle Fibers claim stale messages from dead consumers via `XAUTOCLAIM` (per-queue `retry_after` as idle threshold)
6. On completion: `XACK` + `XDEL`. On failure: retry with exponential backoff or dead-letter
7. A shared pause flag (updated by a timer) replaces per-Fiber Redis checks, reducing overhead from 50 `EXISTS` calls per cycle to 1

### Queue backend: Redis Streams

Redis Streams (not LISTs like Horizon) provide:

- **Consumer groups**: multiple workers, no duplicate processing
- **Acknowledgment**: `XACK` after success, unacked jobs auto-reclaimed via `XAUTOCLAIM`
- **Non-blocking reads**: `XREADGROUP` without BLOCK returns immediately, letting Fibers yield cleanly
- **Pending Entries List**: Redis tracks assigned-but-unacked jobs natively
- **Cluster support**: hash-tagged keys keep related data on the same slot

### Compatibility

| Feature                  | Horizon                 | Torque                                |
| ------------------------ | ----------------------- | ------------------------------------- |
| Queue backend            | Redis LIST              | Redis Streams                         |
| Concurrency              | 1 job/process           | N jobs/process (Fibers)               |
| I/O model                | Blocking (PDO, curl)    | Non-blocking (fledge-fiber)           |
| PHP extensions           | None                    | None (igbinary optional)              |
| Eloquent in jobs         | Full support            | Works, but blocks Fibers; use `MysqlPool` for fan-out |
| Laravel Queue contract   | Full                    | Full                                  |
| Job batches              | Yes                     | Yes                                   |
| Batches page             | Yes                     | Read-only list and detail; needs `make:queue-batches-table` |
| Long-wait alerts         | Yes                     | `waits` per queue, `LongWaitDetected`, mail and Slack routes |
| Job tags                 | Yes                     | `tags()` or model auto-tags, stored at dispatch; feed filter |
| Silenced jobs            | Yes                     | `Silenced` contract, `silenced`, `silenced_tags` |
| Delayed jobs             | Redis sorted set        | Redis sorted set                      |
| Redis Cluster            | Yes                     | Yes                                   |
| Dashboard                | Blade + polling         | Livewire 4 + Blade, `wire:poll` live  |
| Autoscaling              | Balancing strategies    | Slot-pressure based                   |
| Per-job event timeline   | Logs + failed-job retry | First-class, live-tailable per UUID   |
| Live job progress        | Custom code per job     | `$this->emit(...)` via `Streamable`   |
| Worker pause/resume events | `WorkerPausing` / `WorkerResuming` (13.8) | Same events, dispatched on `torque:pause` flips |
| Worker lifecycle events | `WorkerStarting`, `WorkerStopping`, `WorkerIdle`, `WorkerQueuePaused`, `WorkerQueueResumed`, `Looping` | `WorkerStarting` and `WorkerStopping` once per worker; `Looping` on every loop. A listener returning false from `Looping` is honoured |
| Pop and release events | `JobPopping`, `JobPopped`, `JobReleased`, `JobReleasedAfterException` | Dispatched. `JobPopping` fires on every poll (about 25 per second at 50 slots), so keep its listeners cheap. A throwing listener on the job events does not change the job's outcome, unlike stock |
| Job timeout event | `JobTimedOut` | Dispatched when `timeouts.enabled` is on (the default since 2.0) |
| Maintenance mode | `queue:work` waits while `artisan down`, `--force` overrides | Same: slots stop polling, `force` config overrides |
| Framework queue pause | `queue:pause` / `queue:pause --all` (13.25) | Honored: global pause stops the worker, per-queue pause skips that stream (keys on the `torque` connection); `torque:pause` stays independent |
| Enum queue names | `\UnitEnum` accepted by all drivers (13.25) | Same on `StreamQueue` via `enum_value()` |
| Queue inspection (`all*`) | `allPendingJobs` / `allReservedJobs` / `allDelayedJobs` (13.8) | Same API on `StreamQueue` |
| Job interrupt event | `JobInterrupted` per `Interruptible` job on SIGTERM/SIGINT (13.31) | Same event per in-flight slot, carrying the `StreamJob` and signal |
| Job run time | `JobProcessed::$duration` in milliseconds (13.34) | Same, timed around `fire()` per slot |
| Crash counting | `#[CountCrashesAsExceptions]` counts a worker death mid-job against `maxExceptions` (13.34) | Crashes are counted per slot against `maxExceptions`, and so are thrown exceptions; `max_retries` is only the limit for a job that declares no `$tries` or `retryUntil`. A forced drain exit is not counted as a crash. Known limitation: a job still running past `retry_after` that another worker steals (XAUTOCLAIM) reuses its attempt number and is counted as a crash, so set `retry_after` longer than the longest job |

### Long-wait alerts

Get told when a queue stops draining. Set a threshold in seconds per queue in `config/torque.php`; queues you do not list are not checked, and the default is off for all:

```php
'waits' => ['emails' => 60],
```

The master checks every 30 seconds. When the oldest waiting job on a queue is older than its threshold, Torque dispatches `Webpatser\Torque\Events\LongWaitDetected` and sends the notifications once per episode. It repeats after 15 minutes while the queue is still over, or after 5 minutes if the alert failed. Route notifications from a service provider:

```php
Torque::routeMailNotificationsTo('ops@example.com');
Torque::routeSlackNotificationsTo($webhookUrl);
```

Slack needs `laravel/slack-notification-channel`. Every route gets a Block Kit message (needs `laravel/slack-notification-channel` 3.6 or newer).

- The event and the notifications run in a short-lived child process that boots the app fresh, so register listeners and routes in a service provider.
- One alert can take up to 60 seconds (`long_wait_alert_seconds`), shared across its steps. A throwing listener can run again for the same episode.
- Failures appear in the master's output and the app log.
- Each host's master alerts separately, and a master restart may re-fire once for an ongoing episode.

### Tags and silenced jobs

Tags are computed at dispatch on the real job object and stored in the payload. A `tags()` method wins, and it runs in the dispatching process, so it can run queries there. Without one, public properties holding an Eloquent model or a collection of models become `Model:key` tags (`App\Models\User:42`). Protected properties, arrays and nested objects are not inspected. A job gets at most 50 tags of 191 characters. `ShouldBeEncrypted` jobs have no tags. Jobs queued before the 1.4.0 upgrade have none until they drain. The live feed filters by tag over its latest 100 jobs.

Silence jobs whose successes are noise: implement `Webpatser\Torque\Contracts\Silenced`, list the class under `silenced`, or list a tag under `silenced_tags` (encrypted jobs have no tags, so tags cannot match them). A silenced job's success is omitted: it shows as queued or running until it finishes, then vanishes from the feed. Failures are always recorded. Metrics and `JobProcessed` are unaffected.

### Not planned

These Horizon features have no Torque equivalent in 1.x, each for a reason:

| Horizon feature | Why not |
|---|---|
| Per-environment supervisors | Torque has one master with one slot pool. Per-stream `max_concurrency`, `prefix` isolation and `env()` in `config/torque.php` cover the main uses |
| Queue grouping and per-supervisor pause | Same single pool. Pause a stream with `queue:pause`, or all of Torque with `torque:pause` |
| Tag monitoring with retention | Not planned; open an issue if you need it |
| Fast termination | `torque:reload` takes over with a new fleet while the old one drains, which makes it unnecessary |

## Production deployment

Generate a Supervisor config:

```bash
php artisan torque:supervisor --workers=4 --user=forge
```

Options:

- `--php`: the PHP binary written into the config (default `PHP_BINARY`). `PHP_BINARY` is the fully resolved binary (for example a Homebrew Cellar patch-level path) and can go stale after an upgrade, so in production pass a stable path such as `--php=/usr/bin/php8.5`.
- `--app-path`: override the application path written into the config; it must be absolute. When `base_path()` is a Deployer release (`releases/N`), the sibling `current` symlink is detected and used automatically, so the config survives new releases.
- `--stdout`: print the config instead of writing a file, for example `php artisan torque:supervisor --stdout | sudo tee /etc/supervisor/conf.d/torque.conf`. `--path` is ignored with `--stdout` (a warning goes to stderr).

This creates `storage/torque-supervisor.conf`. Copy it to your Supervisor config directory:

```bash
sudo cp storage/torque-supervisor.conf /etc/supervisor/conf.d/torque.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start torque
```

### Operations

A few settings on the Redis instance and the app side that keep a failure storm from turning into an outage:

- **Give Torque's Redis a `maxmemory` and set `maxmemory-policy noeviction`.** With eviction on, a runaway writer silently evicts other keys; with `noeviction` the writer gets `OOM command not allowed` instead, which is loud, contained, and recoverable. Torque's own keys are all bounded (dead-letter cap, metrics TTLs, breaker expiries).
- **Prune `failed_jobs` too.** The dead-letter cap bounds Redis, not your database: the framework's failed-jobs table grows one row per permanent failure. Schedule `Schedule::command('queue:prune-failed --hours=168')->daily();`.
- **Optionally schedule `torque:prune`** hourly as belt and braces. Since the master runs the same housekeeping every `dead_letter.prune_interval` seconds it is no longer required, but it costs nothing and covers a fleet that is down while the scheduler still runs.

### Upgrading from an older Torque

Version-specific notes live in [UPGRADE.md](UPGRADE.md).

Leftovers from a previous version (per-job event streams that never got their terminal expiry, index members pointing at streams that are gone, worker hashes of exited processes, the pre-rollup `metrics:buckets` hash) are cleaned up automatically on the first `torque:start` after the deploy: the master runs the sweep once per version and records it in `{prefix}version`, logging a count per category.

To preview it before restarting the fleet, run `php artisan torque:prune --deep --dry-run` and then the same command without `--dry-run`. `torque:status` shows the recorded data version next to the installed one.

### Zero-downtime reload

`torque:stop` followed by `torque:start` works for cold deploys, but it leaves a queue-processing gap (jobs queue up in Redis until the new master is back). `torque:reload` swaps the master in one step, with no manual chaining of pause + wait + stop. Pick the mode by who owns the respawn:

**Under a process supervisor (supervisord, systemd): use `--drain`.** The supervisor owns respawning, so the reload only signals:

```bash
# supervisord (autorestart=true): drain, exit, supervisor respawns from the
# current release. systemd: wire it as ExecReload=; Kubernetes: preStop.
php artisan torque:reload --drain
```

A spawned replacement would escape supervision permanently, which is why the default mode warns when the master looks supervised.

**Unsupervised hosts: the default takeover handshake:**

```bash
php artisan torque:reload
```

The reload spawns `torque:start --takeover=<oldPid>`, which boots its fleet in its own session, waits for its own workers' first metrics heartbeat (`takeover_ready_timeout_seconds`, default 30 s), and only then claims the PID file and signals the old master to drain. A replacement whose fleet never becomes healthy aborts the takeover with the old master untouched, so a broken deploy stays a failed reload instead of an outage; the replacement's output is surfaced by the reload command on failure.

During the swap the old master's drain pause is scoped to its own workers (the pause key carries the master PID and a TTL), so the new fleet keeps consuming throughout. In-flight jobs finish naturally on the old master; the Redis queue handles claim-once semantics across both fleets. For takeover restarts, keep `drain_grace_seconds` (`TORQUE_DRAIN_GRACE`, derived by default) below the stream `retry_after` so the brief overlap cannot double-claim jobs. `--drain` restarts are unaffected by this, and long-running streams may exceed `retry_after` there. One accepted risk on unsupervised hosts: a takeover master SIGKILLed after claiming the PID file leaves nothing to respawn it, which is inherent to running without a supervisor.

#### Two clocks, one ceiling

`--timeout` is how long `torque:reload` itself keeps watching; `drain_grace_seconds` is what the master and its workers hold themselves to. They are not the same number, and the worst case of a drain is the reload pause (`reload_pause_seconds`, 10) plus the grace plus a slack (15): the master waits up to the pause for its fleet to report idle, SIGTERMs it, and then every worker gets the grace window for the job it still holds. At package defaults that is 85 seconds.

Left unset, `--timeout` is derived from that worst case, so the default can never be shorter than the drain it waits for. Passing it explicitly is for deploy tools with a run timeout of their own:

```bash
# Deployer's run() defaults to a 300s timeout; keep the reload inside it.
php artisan torque:reload --drain --if-running --timeout=240
```

A short explicit timeout means "stop watching", not "cut the drain short". Past it the command reports that the master is still draining and returns successfully, without a signal: pickup has been paused since the SIGUSR2, and the master exits on its own once the fleet is idle or the grace runs out. The SIGTERM escalation is reserved for a master still alive past its own ceiling, which is a wedged one. `torque:stop` derives its pre-SIGKILL window the same way and takes the same `--timeout` override.

A drain pause belongs to the master that wrote it, so a master starting into a `drain:<pid>` pause whose PID is no longer running deletes the key and logs it, instead of honouring the rest of its TTL (`reload_pause_seconds + drain_grace_seconds + 60`, hours on installations with a long grace) after a reload was killed mid-drain. A deliberate `torque:pause` is never cleared automatically, and `torque:status` names which of the two a pause is.

Mutual exclusion between masters is a lifetime `flock` on `storage/torque.lock` (released by the kernel on any exit, including SIGKILL), and the PID file self-heals every second: the owning master rewrites it if missing, reclaims it from a stale PID, and self-demotes into a drain if another live master owns it. Concurrent reloads fail fast on `storage/torque.reload.lock`.

### Containerized deployment

When `storage/` is a bind mount or persistent volume, `storage/torque.pid` outlives the container. `torque:start` verifies the recorded PID actually belongs to a running Torque master, via `/proc/<pid>/cmdline` on Linux, `ps` elsewhere, so a stale PID file left by a previous container reads as "not running", even when its number has since been recycled by an unrelated process. Readers never delete the file (that would race a takeover's atomic rename); the next master simply overwrites it at boot. No manual `rm storage/torque.pid` is needed between restarts.

## Performance

### Fair comparison vs Laravel `queue:work` / Horizon

Same hardware, same Redis, same number of OS processes (2 each), 1000 jobs per run, median of 3 measured runs after a 100-job warmup. Each job emits one `XADD` result-event so measurement overhead is symmetric on both sides. Full reproduction recipe in [BENCHMARKS.md](BENCHMARKS.md).

| Workload                                | Laravel `queue:work` (2 procs) | Torque (2 workers x 25 fibers) |  Δ vs Laravel |
| --------------------------------------- | -----------------------------: | -----------------------------: | ------------: |
| `cpu` (5000x `xxh3` hash per job)       |                          782/s |                          560/s | 0.72x slower  |
| `mixed` (sync I/O + CPU)                |                          490/s |                          410/s | 0.84x slower  |
| `io` (`usleep` 2 ms, blocking)          |                          387/s |                          387/s |          1.0x |
| `payload-large` (64 KiB JSON)           |                          337/s |                          535/s |    **1.6x**   |
| `async-io` (`Fledge\Async\delay` 2 ms)  |                          378/s |                          910/s |    **2.4x**   |
| `fanout` (100 ms async wait)            |                           18/s |                          281/s |    **15x**    |

> [!TIP]
> The pattern is consistent: Torque wins when handlers yield to I/O, loses when handlers occupy the OS thread. The `fanout` row is the workload Torque was built for. Pure CPU is not.

**Memory at equivalent throughput** (the production framing):

| Workload                     | Horizon procs for ~280 jobs/sec | Torque procs | Memory savings |
| ---------------------------- | ------------------------------: | -----------: | -------------: |
| `fanout` (100 ms async wait) |               ~30 (~2.5 GB RAM) |  2 (~120 MB) |          ~95%  |
| `async-io` (2 ms wait)       |               ~5  (~400 MB RAM) |  2 (~120 MB) |          ~70%  |

For a queue dominated by external API calls and webhooks, that translates directly to fewer servers, less memory pressure, and headroom to absorb traffic spikes without provisioning ahead of time.

### Benchmarking your own workload

Torque ships with a `torque:bench` command that produces reproducible numbers (jobs/sec, p50/p95/p99 latency) on your actual hardware. Run it before tuning anything: serializer choice, worker count, coroutines per worker. Optimization without numbers is guesswork.

```bash
# Default mixed workload (80% I/O, 20% CPU), 10k jobs, 4 workers
php artisan torque:bench

# Specific workload profile
php artisan torque:bench --workload=payload-large --jobs=10000

# Compare serializers (json vs igbinary), JSON output for diffing
php artisan torque:bench --workload=payload-large --serializer=json --json=baseline.json
php artisan torque:bench --workload=payload-large --serializer=igbinary --json=igbinary.json
jq -s '.[0].results.throughput_per_sec, .[1].results.throughput_per_sec' baseline.json igbinary.json
```

**Workload profiles**:

| Profile | What it simulates |
|---|---|
| `cpu` | Tight hash loop, measures handler-side CPU under Fibers |
| `io` | `usleep(2 ms)` per job, simulates Redis/HTTP/DB wait |
| `mixed` (default) | 80% I/O, 20% CPU, realistic web-app queue |
| `payload-small` | 256 B blob, baseline for serializer overhead |
| `payload-large` | 64 KiB blob, where serializer choice actually shows |

**Flags**: `--workers`, `--coroutines`, `--jobs`, `--warmup`, `--serializer`, `--json`, `--force`. See `php artisan torque:bench --help` for the full list.

> [!NOTE]
> The v1 bench command requires `--use-running-master`. Start a torque worker fleet first (`php artisan torque:start`), then run the bench against it. Self-spawning workers from inside the bench command lands in a follow-up release.

For deeper profiling, use [XHProf](https://www.php.net/manual/en/book.xhprof.php) or [Excimer](https://github.com/wikimedia/php-excimer) on a running worker. The bench output tells you whether to bother.

### igbinary: ~2x faster payload encoding

Torque can encode its Redis Streams envelope with [igbinary](https://github.com/igbinary/igbinary7) instead of JSON. Roughly 2x faster on encode and decode, smaller on the wire. Recommended once you have a baseline benchmark to compare against.

**Install** (PECL):

```bash
pecl install igbinary
echo "extension=igbinary" | sudo tee -a /etc/php/8.5/cli/php.ini
echo "extension=igbinary" | sudo tee -a /etc/php/8.5/fpm/php.ini
```

Or via your distro: `apt install php8.5-igbinary` on Debian/Ubuntu, `brew install php@8.5-igbinary` style packages on macOS.

**Enable** in your `.env`:

```env
TORQUE_SERIALIZER=igbinary
```

**Verify** with the bench command:

```bash
php artisan torque:bench --workload=payload-large --serializer=igbinary
```

`torque:start` prints `Serializer: igbinary` on boot when active, and a one-line install hint when the extension is missing.

> [!TIP]
> **Safe to flip while running.** Torque sniffs the first byte of every payload (`{`/`[` for JSON, `\x00\x00\x00\x02` for igbinary), so in-flight messages decoded with the old format keep working. New messages come out as igbinary. Both coexist until the stream organically drains.

> [!WARNING]
> Igbinary payloads are binary, not human-readable. `redis-cli XRANGE torque:default - +` returns gibberish for the payload field once you flip the switch. Stick with `--serializer=json` (the default) during debugging sessions.

> [!TIP]
> Setting `igbinary.compact_strings = On` in `php.ini` also speeds up Laravel's session and cache `serialize()` calls globally, even without flipping the torque serializer. Free win across your whole app.

## Dependencies

**Required** (installed automatically):
- `revolt/event-loop`: Fiber scheduler
- `webpatser/fledge-fiber`: async/await primitives, non-blocking Redis, sync primitives

**Optional** (install when needed):
- `webpatser/fledge-fiber-database`: Async MySQL for `MysqlPool`
- `webpatser/fledge-fiber-http`: Async HTTP for `HttpPool`
- `ext-igbinary`: ~2x faster payload encoding when `TORQUE_SERIALIZER=igbinary` is set. See [Performance](#performance).

## License

MIT
