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
    'queue' => 'default',
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

Streams auto-expire after 5 minutes (configurable via `job_streams.ttl`).

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
| `torque:reload` | Zero-downtime reload. Spawns a replacement master, waits for it to take over the PID file, then drains the old one. `--drain` for supervisor-driven setups |
| `torque:supervisor` | Generate a Supervisor config file |

## Configuration

All options are in `config/torque.php`. Key settings:

| Setting | Default | Description |
|---------|---------|-------------|
| `workers` | 4 | Number of worker processes |
| `coroutines_per_worker` | 50 | Concurrent job slots per worker |
| `max_jobs_per_worker` | 10000 | Restart worker after N jobs (prevents memory leaks) |
| `max_worker_lifetime` | 3600 | Restart worker after N seconds |
| `drain_grace_seconds` | 10 | Seconds Fibers get to finish in-flight jobs before the worker hard-exits on rotation |
| `stall_warn_seconds` | 300 | Watchdog logs a WARN for any slot whose current job has been running longer than this |
| `block_for` | 2000 | Poll interval in ms (how often idle Fibers check for new jobs) |
| `redis.cluster` | false | Enable Redis Cluster hash tag support |

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

## Dashboard

Torque includes a self-contained dashboard at `/torque` (configurable). It is built with **Livewire 4** and plain Blade + Tailwind, served Horizon/Pulse-style: a set of full-page Livewire components share one Blade layout, and the compiled `dist/torque.css` is inlined into it. Livewire (a hard dependency) ships its own runtime and bundled Alpine through your app, so there is **no React, no Flux, no paid UI library, no host Tailwind or Vite build, and no `npm` step in your application**. Enable it in config:

```php
'dashboard' => [
    'enabled' => true,
],
```

Features:
- Real-time metrics (throughput, latency, concurrent jobs, memory)
- Worker table with coroutine slot usage bars
- Stream/queue overview with pending and delayed counts
- Failed jobs list with retry and delete actions (cursor-paginated)
- Per-job inspector with a timeline of lifecycle events and exception details
- Live feed with a rolling stream tail and status filters
- Kibana-style configurable poll interval (1s to 30s, or paused)

### Assets

The dashboard ships pre-built. The compiled `dist/torque.css` lives in the package and is inlined into the layout at request time, so there is nothing to publish and no build step in your application. The behaviour comes from Livewire's own runtime (and bundled Alpine), which your app already loads. If you are working on Torque itself, rebuild the stylesheet with:

```bash
npm install
npm run build   # writes dist/torque.css (Tailwind 4)
```

### Authorization

The gate `viewTorque` is checked on every dashboard route (overview, workers, queues, feed, inspector, dead-letter), so no screen or Livewire action is reachable by users who would fail the gate. Define it in your `AuthServiceProvider`:

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
- Retry or delete via dashboard or programmatically
- Listen for the `JobPermanentlyFailed` event for custom notifications

```php
use Webpatser\Torque\Events\JobPermanentlyFailed;

Event::listen(JobPermanentlyFailed::class, function ($event) {
    // $event->jobName, $event->queue, $event->exceptionMessage, etc.
    Notification::route('slack', '#alerts')->notify(new YourNotification($event));
});
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
3. Each Fiber polls for messages with non-blocking `XREADGROUP` (no BLOCK). When no work is available, the Fiber yields to the event loop with a configurable delay (`block_for` / 1000 seconds). This ensures timers (delayed job migration, metrics, pause checks) always fire reliably
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
| Delayed jobs             | Redis sorted set        | Redis sorted set                      |
| Redis Cluster            | Yes                     | Yes                                   |
| Dashboard                | Blade + polling         | Livewire 4 + Blade, `wire:poll` live  |
| Autoscaling              | Balancing strategies    | Slot-pressure based                   |
| Per-job event timeline   | Logs + failed-job retry | First-class, live-tailable per UUID   |
| Live job progress        | Custom code per job     | `$this->emit(...)` via `Streamable`   |
| Worker pause/resume events | `WorkerPausing` / `WorkerResuming` (13.8) | Same events, dispatched on `torque:pause` flips |
| Queue inspection (`all*`) | `allPendingJobs` / `allReservedJobs` / `allDelayedJobs` (13.8) | Same API on `StreamQueue` |

## Production deployment

Generate a Supervisor config:

```bash
php artisan torque:supervisor --workers=4 --user=forge
```

This creates `storage/torque-supervisor.conf`. Copy it to your Supervisor config directory:

```bash
sudo cp storage/torque-supervisor.conf /etc/supervisor/conf.d/torque.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start torque
```

### Zero-downtime reload

`torque:stop` followed by `torque:start` works for cold deploys, but it leaves a queue-processing gap (jobs queue up in Redis until the new master is back). `torque:reload` swaps the master in one step, with no manual chaining of pause + wait + stop:

```bash
# Default: spawn a replacement, wait for it to take over the PID file,
# then drain the old master (pause pickup, wait drain_grace_seconds, SIGTERM).
php artisan torque:reload

# Signal-only mode for systemd ExecReload= / Kubernetes preStop / Supervisor
# recipes that own spawning the replacement themselves.
php artisan torque:reload --drain
```

In-flight jobs finish naturally on the old master while the new one starts taking new work; the Redis queue handles claim-once semantics across both. Tune the drain window with `TORQUE_DRAIN_GRACE` (default `10` seconds).

### Containerized deployment

When `storage/` is a bind mount or persistent volume, `storage/torque.pid` outlives the container. `torque:start` verifies the recorded PID actually belongs to a running Torque master — on Linux it checks `/proc/<pid>/cmdline` — so a stale PID file left by a previous container is detected and cleared automatically, even when its number has since been recycled by an unrelated process. No manual `rm storage/torque.pid` is needed between restarts.

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
