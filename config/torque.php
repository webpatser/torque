<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Payload serializer
    |--------------------------------------------------------------------------
    | Controls how the queue envelope is encoded on the wire.
    |
    | 'json'     : default, human readable via redis-cli XRANGE.
    | 'igbinary' : ~2x faster encoding and ~30% smaller payloads. Requires
    |              ext-igbinary (pecl install igbinary). Binary on the wire,
    |              so redis-cli output becomes unreadable. Decoding is format
    |              sniffing, so flipping this value is safe with in-flight
    |              JSON messages still in the stream.
    */
    'serializer' => env('TORQUE_SERIALIZER', 'json'),

    /*
    |--------------------------------------------------------------------------
    | Worker processes
    |--------------------------------------------------------------------------
    */
    'workers' => (int) env('TORQUE_WORKERS', 4),

    /*
    |--------------------------------------------------------------------------
    | Coroutines per worker
    |--------------------------------------------------------------------------
    | How many jobs each worker process can handle concurrently via Fibers.
    | Higher = more concurrent I/O-bound jobs. Lower = less memory per worker.
    | Rule of thumb: 50 for I/O-heavy, 10-20 for CPU-heavy jobs.
    */
    'coroutines_per_worker' => (int) env('TORQUE_COROUTINES', 50),

    /*
    |--------------------------------------------------------------------------
    | Max jobs before worker restart
    |--------------------------------------------------------------------------
    | Prevents memory leaks from long-running processes.
    */
    'max_jobs_per_worker' => (int) env('TORQUE_MAX_JOBS', 10000),

    /*
    |--------------------------------------------------------------------------
    | Max worker lifetime (seconds)
    |--------------------------------------------------------------------------
    | Worker gracefully restarts after this duration regardless of job count.
    */
    'max_worker_lifetime' => (int) env('TORQUE_MAX_LIFETIME', 3600),

    /*
    |--------------------------------------------------------------------------
    | Max worker lifetime jitter (ratio of the lifetime)
    |--------------------------------------------------------------------------
    | The master forks its whole fleet within the same second, so without
    | jitter every worker reaches max_worker_lifetime in the same second too
    | and the fleet rotates in lockstep. Each worker subtracts a random slice
    | of up to this ratio from its own lifetime, spreading the rotations.
    |
    | Only ever subtracts, so an effective lifetime never exceeds the
    | configured one; the master's stale-consumer threshold depends on that.
    | Set to 0.0 to disable and have every worker use the exact lifetime.
    */
    'max_worker_lifetime_jitter' => (float) env('TORQUE_MAX_LIFETIME_JITTER', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Drain grace (seconds)
    |--------------------------------------------------------------------------
    | Once limits are reached (max jobs, max lifetime, or stop signal), Fibers
    | get up to this many seconds to finish in-flight jobs before the worker
    | forces exit(0). Guarantees the master sees SIGCHLD and respawns even if
    | a Fiber is stuck inside processMessage() or a half-open Redis socket.
    |
    | It is a ceiling, not a wait: a worker whose slots are all idle exits
    | immediately, and so does a draining master whose fleet is idle. Size it
    | for the longest job you are willing to wait for, not for how long you
    | want a rotation or a deploy to take.
    |
    | Also reused by `torque:reload`: when the master receives SIGUSR2, it
    | pauses pickup, waits up to this many seconds for in-flight jobs to
    | clear, and then signals workers to stop.
    */
    'drain_grace_seconds' => (int) env('TORQUE_DRAIN_GRACE', 10),

    /*
    |--------------------------------------------------------------------------
    | Takeover readiness timeout (seconds)
    |--------------------------------------------------------------------------
    | During a self-spawn `torque:reload`, how long the replacement master
    | waits for the first metrics heartbeat from its own workers before
    | aborting the takeover and leaving the old master untouched. Keep it
    | below torque:reload's --health-timeout.
    */

    'takeover_ready_timeout' => (int) env('TORQUE_TAKEOVER_READY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Stall warning threshold (seconds)
    |--------------------------------------------------------------------------
    | The watchdog logs a WARN line every 30s for any slot whose current job
    | has been running longer than this. Helps catch hung user jobs (e.g.
    | external HTTP calls without a timeout) before they age out the worker.
    */
    'stall_warn_seconds' => (int) env('TORQUE_STALL_WARN', 300),

    /*
    |--------------------------------------------------------------------------
    | Redis connection
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'uri' => env('TORQUE_REDIS_URI', 'redis://127.0.0.1:6379'),
        'prefix' => env('TORQUE_PREFIX', 'torque:'.Str::slug(env('APP_NAME', 'laravel'), '_').':'),
        'cluster' => (bool) env('TORQUE_CLUSTER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer group
    |--------------------------------------------------------------------------
    */
    'consumer_group' => env('TORQUE_CONSUMER_GROUP', 'torque'),

    /*
    |--------------------------------------------------------------------------
    | Streams (queues)
    |--------------------------------------------------------------------------
    | Each stream maps to a Redis Stream with its own consumer group.
    | Priority determines processing order when multiple streams have work.
    |
    | Optional per-stream `max_concurrency` caps how many of a stream's jobs a
    | single worker processes simultaneously (fleet-wide cap = workers x
    | max_concurrency). Use it to keep bulk queues from hammering external
    | APIs; the cap is approximate under bursts (bounded by fiber count).
    */
    'streams' => [
        'default' => [
            'priority' => 0,
            'retry_after' => 60,
            'max_retries' => 3,
            'backoff' => 'exponential',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection pools
    |--------------------------------------------------------------------------
    */
    'pools' => [
        'mysql' => [
            'size' => (int) env('TORQUE_MYSQL_POOL', 20),
            'idle_timeout' => 60,
        ],
        'redis' => [
            'size' => (int) env('TORQUE_REDIS_POOL', 30),
            'idle_timeout' => 60,
        ],
        'http' => [
            'size' => (int) env('TORQUE_HTTP_POOL', 15),
            'idle_timeout' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoscaling
    |--------------------------------------------------------------------------
    | Master can spin up/down workers based on queue pressure.
    */
    'autoscale' => [
        'enabled' => (bool) env('TORQUE_AUTOSCALE', false),
        'min_workers' => (int) env('TORQUE_MIN_WORKERS', 2),
        'max_workers' => (int) env('TORQUE_MAX_WORKERS', 8),
        'scale_up_threshold' => 0.85,
        'scale_down_threshold' => 0.20,
        'cooldown' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Delayed jobs
    |--------------------------------------------------------------------------
    | Delayed jobs are stored in a sorted set (ZADD with timestamp).
    | A timer coroutine checks every N seconds and moves ripe jobs to stream.
    */
    'delayed' => [
        'check_interval' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead letter
    |--------------------------------------------------------------------------
    | Jobs that exceed max_retries go to the dead letter stream.
    |
    | 'ttl'         : entries older than this are trimmed (XTRIM MINID).
    | 'max_entries' : hard cap enforced at write time (XADD MAXLEN ~), so a
    |                 failure storm can never fill Redis. 0 disables the cap
    |                 and leaves only the TTL. Sizing: the 2026-08-27 incident
    |                 averaged ~6 KB per entry (payload + trace), so the
    |                 default 100k entries is ~600 MB worst case. Halve it if
    |                 the Torque Redis has less than 1 GB of headroom.
    | 'prune_interval': seconds between master-driven housekeeping runs (TTL
    |                 trim, cap trim, stale consumer sweep). 0 disables it and
    |                 leaves pruning to a scheduled `torque:prune`.
    */
    'dead_letter' => [
        'ttl' => 604800,
        'max_entries' => (int) env('TORQUE_DEAD_LETTER_MAX', 100000),
        'prune_interval' => (int) env('TORQUE_PRUNE_INTERVAL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    | Stops a stream from being polled when its jobs are failing permanently
    | at a high rate (a dead upstream API, expired credentials). Instead of
    | burning through the whole backlog into the dead-letter stream, the queue
    | is paused for `cooldown` seconds and then probed again.
    |
    | Outcomes are recorded per stream in a sliding window shared by every
    | worker; only permanent failures count as failures, retries are neutral.
    |
    | Per-stream overrides live under `streams.<queue>.circuit_breaker` and are
    | merged over these defaults; set that key to `false` to opt a stream out.
    */
    'circuit_breaker' => [
        'enabled' => (bool) env('TORQUE_CIRCUIT_BREAKER', true),
        'window' => 100,        // outcomes per stream in the sliding window
        'min_samples' => 20,    // never trip below this many outcomes
        'threshold' => 0.9,     // permanent-failure ratio that opens the breaker
        'cooldown' => 300,      // seconds the stream stays paused before half-open
        'half_open_max' => 5,   // probe jobs allowed while half-open
        'retention' => 3600,    // TTL on the outcome window keys
    ],

    /*
    |--------------------------------------------------------------------------
    | Job event streams
    |--------------------------------------------------------------------------
    | Every job automatically gets a per-job Redis Stream recording lifecycle
    | events (queued, started, completed, failed). Jobs using the Streamable
    | trait can emit custom progress events via $this->emit().
    |
    | Streams auto-expire after `ttl` seconds following a terminal event.
    */
    'job_streams' => [
        'enabled' => true,
        'ttl' => 300,
        'max_events' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'enabled' => true,
        'publish_interval' => 1,

        /*
         * Retention of the minute-resolution history, in seconds. One field per
         * minute, so a day is 1440 fields.
         */
        'retention' => 86400,

        /*
         * Coarser tiers, so history can outlive the day without the storage
         * growing linearly. Every finished job lands in a minute, an hour and a
         * day bucket; each bucket is one hash field of roughly 20 bytes
         * ("1700000000" => "1500,3").
         *
         * Cluster-wide at the defaults below:
         *   1440 minute fields (24h) + 2160 hour fields (90d) + 730 day fields
         *   (2y) = ~4300 fields, about 60 KB with hash overhead.
         *
         * The same set exists per stream, so budget another ~60 KB per stream.
         *
         * `daily_days` at 0 keeps the daily tier forever (it costs 365 fields,
         * roughly 7 KB, per year).
         */
        'rollups' => [
            'hourly_days' => 90,
            'daily_days' => 730,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'enabled' => true,
        'path' => 'torque',
        'middleware' => ['web', 'auth'],
        'poll_intervals' => [0, 1000, 2000, 5000, 10000, 30000],
        'default_poll_interval' => 1000,

        /*
         * Ceiling of the overview throughput gauge, in jobs per minute.
         * Null scales it to the busiest minute of the last hour (rounded up to
         * a round number), so a queue that gets one big burst every few minutes
         * stays readable without pinning the needle. Set an integer to freeze
         * the scale.
         */
        'gauge_max' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Block timeout (ms)
    |--------------------------------------------------------------------------
    | How long XREADGROUP blocks waiting for new messages.
    */
    'block_for' => (int) env('TORQUE_BLOCK_FOR', 2000),

];
