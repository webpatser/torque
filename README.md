# Torque

**The queue that keeps spinning.** Coroutine-based queue worker for Laravel.

Torque replaces Horizon's 1-job-per-process model with N-jobs-per-process using PHP 8.5 Fibers. When a job waits on I/O, the coroutine scheduler switches to another job. Same hardware, 3-10x throughput for I/O-bound workloads.

```
4 workers x 50 coroutines = 200 concurrent jobs in ~300MB RAM
```

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

### Per-Fiber state isolation

Use `CoroutineContext` when you need per-job isolated state (e.g., request-scoped data):

```php
use Webpatser\Torque\Job\CoroutineContext;

// Inside a job handler
CoroutineContext::set('tenant_id', $this->tenantId);
$tenantId = CoroutineContext::get('tenant_id');
```

State is automatically cleaned up when the Fiber completes (backed by `WeakMap`).

## Job Event Streams

Every job automatically records lifecycle events to a per-job Redis Stream. No code changes needed.

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
| `torque:pause` | Pause job processing (in-flight jobs complete) |
| `torque:pause continue` | Resume processing |
| `torque:supervisor` | Generate a Supervisor config file |

## Configuration

All options are in `config/torque.php`. Key settings:

| Setting | Default | Description |
|---------|---------|-------------|
| `workers` | 4 | Number of worker processes |
| `coroutines_per_worker` | 50 | Concurrent job slots per worker |
| `max_jobs_per_worker` | 10000 | Restart worker after N jobs (prevents memory leaks) |
| `max_worker_lifetime` | 3600 | Restart worker after N seconds |
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

Torque includes a Livewire 4 + Flux UI Pro dashboard at `/torque` (configurable).

Features:
- Real-time metrics (throughput, latency, concurrent jobs, memory)
- Worker table with coroutine slot usage bars
- Stream/queue overview with pending and delayed counts
- Failed jobs list with retry and delete actions (cursor-paginated)
- Per-job inspector with a timeline of lifecycle events, payload, and exception details
- Kibana-style configurable poll interval (1s to 30s, or paused)
- Exception messages and payloads are scrubbed for secrets before rendering

### Styling the dashboard

The dashboard uses Flux UI Pro + Tailwind utilities, which are compiled from your application's own Vite build. Two things must be in place:

1. Install Flux Pro in your host app (you almost certainly already have it):
   ```css
   @import '../../vendor/livewire/flux/dist/flux.css';
   ```

2. Add Torque's views to Tailwind's source scan in `resources/css/app.css`:
   ```css
   @source '../../vendor/webpatser/torque/src/Dashboard/resources/views/**/*.php';
   ```

Without the `@source` line, Tailwind won't generate the classes used inside the dashboard and you'll get an unstyled page.

### Authorization

The gate `viewTorque` is checked on the dashboard route **and** on every Livewire action (retry, purge, navigate), so the action endpoints cannot be reached by users who would fail the gate. Define it in your `AuthServiceProvider`:

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

| Feature | Horizon | Torque |
|---------|---------|--------|
| Queue backend | Redis LIST | Redis Streams |
| Concurrency | 1 job/process | N jobs/process (Fibers) |
| I/O model | Blocking (PDO, curl) | Non-blocking (fledge-fiber) |
| PHP extensions | None | None |
| Eloquent in jobs | Full support | Sync fallback (blocking) |
| Laravel Queue contract | Full | Full |
| Job batches | Yes | Yes |
| Delayed jobs | Redis sorted set | Redis sorted set |
| Redis Cluster | Yes | Yes |
| Dashboard | Blade + polling | Livewire 4 + Flux UI |
| Autoscaling | Balancing strategies | Slot-pressure based |

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

## Dependencies

**Required** (installed automatically):
- `revolt/event-loop`: Fiber scheduler
- `webpatser/fledge-fiber`: async/await primitives, non-blocking Redis, sync primitives

**Optional** (install when needed):
- `webpatser/fledge-fiber-database`: Async MySQL for `MysqlPool`
- `webpatser/fledge-fiber-http`: Async HTTP for `HttpPool`

## License

MIT
