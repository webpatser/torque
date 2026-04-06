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

Regular Laravel jobs work fine — they run synchronously within their coroutine slot. For full async I/O, extend `TorqueJob` and type-hint the pools you need:

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

## CLI Commands

| Command | Description |
|---------|-------------|
| `torque:start` | Start the master + worker processes |
| `torque:stop` | Graceful shutdown (SIGTERM). Use `--force` for SIGKILL |
| `torque:status` | Show worker metrics, throughput, and queue depths |
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
| `block_for` | 2000 | XREADGROUP block timeout (ms) |

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
- Failed jobs list with retry and delete actions
- Kibana-style configurable poll interval (1s to 1m, or paused)

### Authorization

The dashboard is **denied by default**. Define the `viewTorque` gate in your `AuthServiceProvider`:

```php
Gate::define('viewTorque', fn (User $user) => $user->isAdmin());
```

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
├── Worker 1 (Revolt event loop)
│   ├── 50 Fibers (concurrent jobs)
│   ├── Redis Pool
│   ├── MySQL Pool
│   └── HTTP Pool
├── Worker 2
│   └── ...
├── Worker N
│   └── ...
└── AutoScaler (optional)

Redis Streams
├── torque:default (XREADGROUP consumer groups)
├── torque:default:delayed (sorted set)
├── torque:stream:dead-letter
├── torque:metrics (aggregated stats)
└── torque:worker:* (per-worker stats)
```

### How it works

1. **Master** forks N worker processes via `pcntl_fork()`
2. Each **worker** runs a Revolt event loop with M coroutine slots (gated by `LocalSemaphore`)
3. Main loop: acquire semaphore slot -> `XREADGROUP COUNT 1 BLOCK 2000` -> spawn Fiber via `Amp\async()`
4. Fiber executes job. When the job does async I/O (via AMPHP pools), the Fiber suspends and another job runs
5. On completion: `XACK` + `XDEL`. On failure: retry with exponential backoff or dead-letter
6. A dedicated (non-pooled) Redis connection handles blocking reads to avoid tying up pool slots

### Queue backend: Redis Streams

Redis Streams (not LISTs like Horizon) provide:

- **Consumer groups**: multiple workers, no duplicate processing
- **Acknowledgment**: `XACK` after success, unacked jobs auto-reclaimed via `XAUTOCLAIM`
- **Backpressure**: `XREADGROUP COUNT {slots} BLOCK {ms}` pulls exactly as many jobs as available slots
- **Pending Entries List**: Redis tracks assigned-but-unacked jobs natively

### Compatibility

| Feature | Horizon | Torque |
|---------|---------|--------|
| Queue backend | Redis LIST | Redis Streams |
| Concurrency | 1 job/process | N jobs/process (Fibers) |
| I/O model | Blocking (PDO, curl) | Non-blocking (AMPHP) |
| PHP extensions | None | None |
| Eloquent in jobs | Full support | Sync fallback (blocking) |
| Laravel Queue contract | Full | Full |
| Job batches | Yes | Yes |
| Delayed jobs | Redis sorted set | Redis sorted set |
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
- `revolt/event-loop` — Fiber scheduler
- `amphp/amp` — async/await primitives
- `amphp/redis` — Non-blocking Redis client
- `amphp/sync` — LocalSemaphore for pool management

**Optional** (install when needed):
- `amphp/mysql` — Async MySQL for `MysqlPool`
- `amphp/http-client` — Async HTTP for `HttpPool`

## License

MIT
