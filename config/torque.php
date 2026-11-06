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
    | How many worker processes the master forks. With `autoscale.enabled` this
    | is the starting size.
    */
    'workers' => (int) env('TORQUE_WORKERS', 4),

    /*
    |--------------------------------------------------------------------------
    | Slots per worker
    |--------------------------------------------------------------------------
    | How many jobs each worker process can handle concurrently via Fibers.
    | Higher = more concurrent I/O-bound jobs. Lower = less memory per worker.
    | Rule of thumb: 50 for I/O-heavy, 10-20 for CPU-heavy jobs.
    */
    'slots_per_worker' => (int) env('TORQUE_SLOTS_PER_WORKER', env('TORQUE_COROUTINES', 50)),

    /*
    |--------------------------------------------------------------------------
    | Max jobs before worker restart
    |--------------------------------------------------------------------------
    | Prevents memory leaks from long-running processes. The worker drains and
    | the master starts a replacement.
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
    | Null derives it: the longest stream `timeout`, or `retry_after` for a
    | stream without one, 300 at most (60 at package defaults). An explicit value
    | wins. A worker logs a WARN at boot when the effective value is below the
    | derived one. Keep it below the stream `retry_after`, so a takeover's brief
    | two-fleet overlap cannot double-claim jobs. Regenerate a Supervisor config
    | (`torque:supervisor`) after changing it: `stopwaitsecs` derives from it.
    */
    'drain_grace_seconds' => env('TORQUE_DRAIN_GRACE'),

    /*
    |--------------------------------------------------------------------------
    | Reload pause (seconds)
    |--------------------------------------------------------------------------
    | When the master receives SIGUSR2 (`torque:reload`), it pauses pickup, waits
    | up to this many seconds for its fleet to go idle, and then signals workers
    | to stop. Each worker then gets `drain_grace_seconds`. The drain pause key
    | expires after this + `drain_grace_seconds` + 60 seconds.
    */
    'reload_pause_seconds' => (int) env('TORQUE_RELOAD_PAUSE', 10),

    /*
    |--------------------------------------------------------------------------
    | Takeover readiness timeout (seconds)
    |--------------------------------------------------------------------------
    | During a self-spawn `torque:reload`, how long the replacement master
    | waits for the first metrics heartbeat from its own workers before
    | aborting the takeover and leaving the old master untouched. Keep it
    | below torque:reload's --health-timeout.
    */
    'takeover_ready_timeout_seconds' => (int) env('TORQUE_TAKEOVER_READY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Stall warning threshold (seconds)
    |--------------------------------------------------------------------------
    | The watchdog logs a WARN line every 30s for any slot whose current job
    | has been running longer than this. Helps catch hung user jobs (e.g.
    | external HTTP calls without a timeout) before they age out the worker.
    | The master also logs a stall warning at boot on database-cache apps, where
    | the cache cannot carry the heartbeat.
    */
    'stall_warn_seconds' => (int) env('TORQUE_STALL_WARN', 300),

    /*
    |--------------------------------------------------------------------------
    | Blocked event loop kill (seconds)
    |--------------------------------------------------------------------------
    | A job that blocks the whole event loop (`sleep()`, a blocking PDO query, a
    | blocking C extension) freezes every slot and timer of its worker, so job
    | timeouts cannot fire. Above 0, workers touch a heartbeat file in
    | storage/framework/torque-heartbeats about once a second and the master
    | sends SIGKILL to a worker whose heartbeat is older than this, then replaces
    | it. Set it above the longest blocking call you accept: it also kills healthy
    | slots that happen to share the worker. Values of 1 count as 2. 0 is off: no
    | file and no check. Null (the default) derives max(300, longest stream
    | `retry_after`), 300 at package defaults; an integer overrides it.
    |
    | No Redis is involved, so an outage cannot kill workers. The killed worker's
    | jobs stay pending and are redelivered after `retry_after`; with
    | `strict_attempts` (on by default) those redeliveries count against the
    | limit, with it off a job that always blocks loops. A worker that never reaches its first heartbeat (a hang during
    | boot) is not killed. storage must be on a local disk: the file's mtime is
    | compared to this host's clock.
    */
    'loop_stall_kill_seconds' => env('TORQUE_LOOP_STALL_KILL') === null ? null : (int) env('TORQUE_LOOP_STALL_KILL'),

    /*
    |--------------------------------------------------------------------------
    | Memory limit (MB)
    |--------------------------------------------------------------------------
    | A worker whose real memory usage (memory_get_usage(true)) reaches this
    | many megabytes drains like at max_jobs_per_worker and exits with status
    | 12, as queue:work does; the master starts a replacement. Checked once a
    | second. Only 0 turns it off (null means 512). Keep PHP's ini `memory_limit` above this,
    | or PHP fatals before the check; a worker logs a WARN at boot when it is not.
    */
    'memory_limit' => (int) env('TORQUE_MEMORY_LIMIT', 512),

    /*
    |--------------------------------------------------------------------------
    | Force (run while in maintenance mode)
    |--------------------------------------------------------------------------
    | While `artisan down` is active, slots neither poll nor claim; in-flight
    | jobs finish and polling resumes after `artisan up`. The state is read
    | once a second per worker, not per slot. Set to true to keep processing
    | while down, like `queue:work --force`.
    */
    'force' => (bool) env('TORQUE_FORCE', false),

    /*
    |--------------------------------------------------------------------------
    | Redis connection
    |--------------------------------------------------------------------------
    | uri     : Redis or Valkey connection string.
    | prefix  : prepended to every key Torque writes.
    | cluster : Redis Cluster hash tag support.
    | read_timeout_seconds : see below.
    */
    'redis' => [
        'uri' => env('TORQUE_REDIS_URI', 'redis://127.0.0.1:6379'),
        'prefix' => env('TORQUE_PREFIX', 'torque:'.Str::slug(env('APP_NAME', 'laravel'), '_').':'),
        'cluster' => (bool) env('TORQUE_CLUSTER', false),
        // Seconds a worker slot's read waits for a reply before it reconnects, so
        // a half-open socket cannot hold the slot. Zero or less waits forever.
        'read_timeout_seconds' => (float) env('TORQUE_REDIS_READ_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer group
    |--------------------------------------------------------------------------
    | Name of the consumer group every worker joins on each stream.
    */
    'consumer_group' => env('TORQUE_CONSUMER_GROUP', 'torque'),

    /*
    |--------------------------------------------------------------------------
    | Streams (queues)
    |--------------------------------------------------------------------------
    | Each stream maps to a Redis Stream with its own consumer group.
    | Priority determines processing order when multiple streams have work.
    | The shipped `default` stream is keyed by TORQUE_QUEUE; its `retry_after`,
    | `max_retries`, `backoff` and `timeout` read TORQUE_RETRY_AFTER,
    | TORQUE_MAX_RETRIES, TORQUE_BACKOFF and TORQUE_STREAM_TIMEOUT. A published
    | config replaces this whole block, so other stream names live in your file.
    |
    | `retry_after` is the seconds a delivered job may stay unacknowledged before
    | another worker claims it (XAUTOCLAIM).
    |
    | Optional per-stream `release_limit_seconds` overrides the global one (see
    | `release_limit_seconds` below).
    |
    | Optional per-stream `max_concurrency` caps how many of a stream's jobs a
    | single worker processes simultaneously (fleet-wide cap = workers x
    | max_concurrency). Use it to keep bulk queues from hammering external
    | APIs; the cap is approximate under bursts (bounded by fiber count).
    |
    | `max_retries` and `backoff` apply to jobs that declare no limit of their
    | own (see `honour_job_limits` below). `max_retries` counts runs: 3 is three runs,
    | 0 or 1 is one run and no retry. `backoff` is `exponential` (2, 4, 8, ...),
    | seconds, or seconds per attempt as a list or "1,5,10", the last step
    | repeating. Anything else logs a WARN at boot and is `exponential`.
    | Backoff counts tries used, not self-releases.
    |
    | `timeout` is the seconds a job that declares none may run (see `timeouts`
    | below). Keep it below `retry_after`, or another worker can steal the job
    | while it still runs. The worker logs a WARN at boot if it is not.
    */
    'streams' => [
        env('TORQUE_QUEUE', 'default') => [
            'priority' => 0,
            'retry_after' => (int) env('TORQUE_RETRY_AFTER', 60),
            'max_retries' => (int) env('TORQUE_MAX_RETRIES', 3),
            'backoff' => env('TORQUE_BACKOFF', 'exponential'),
            'timeout' => env('TORQUE_STREAM_TIMEOUT') === null ? null : (int) env('TORQUE_STREAM_TIMEOUT'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job limits
    |--------------------------------------------------------------------------
    | true  : what a job declares wins over its stream, as with queue:work:
    |         `retryUntil`, then `$tries` (0 is no limit), `maxExceptions` and
    |         `$backoff`.
    | false : kill switch. Only `max_retries` and 2**attempts count, for every
    |         job. Also ignores a stream's `backoff`, `strict_attempts` and
    |         `release_limit_seconds`; a worker logs a WARN at every boot.
    */
    'honour_job_limits' => (bool) env('TORQUE_HONOUR_JOB_LIMITS', env('TORQUE_JOB_LIMITS', 'job') !== 'stream'),

    /*
    |--------------------------------------------------------------------------
    | Strict attempts
    |--------------------------------------------------------------------------
    | Off, limits are only checked after a thrown exception, and only
    | `release_limit_seconds` stops a job that keeps calling release(). Each
    | worker logs one line at boot to say so.
    |
    | On, a job is checked before it runs, as with queue:work, and fails once it
    | is past its tries or `retryUntil`. Self-releases (RateLimited,
    | WithoutOverlapping, ThrottlesExceptions, your own release()) do not use up
    | tries; they count against `release_limit_seconds` instead.
    |
    | On, a delivery after the first also counts against `$tries` or `max_retries`,
    | so a job that kills its worker reaches the dead letter (reason
    | `redelivery_limit`) instead of looping forever. Any worker going away counts:
    | a job left running past `drain_grace_seconds` by a restart, a rotation or
    | another job's timeout uses up a try as well, so leave room in the limit.
    */
    'strict_attempts' => (bool) env('TORQUE_STRICT_ATTEMPTS', true),

    /*
    |--------------------------------------------------------------------------
    | Release limit
    |--------------------------------------------------------------------------
    | Seconds a job may keep calling release(), counted from the available-at of
    | its first release; 0 is off. The deadline is checked at pickup, so a
    | released job that waits in a backlog past it also ends here. Past it the job reaches the dead letter (reason
    | `release_limit`), also with `strict_attempts` off. A stream's own
    | `release_limit_seconds` wins over this one. A job's `retryUntil` replaces the
    | limit, `$tries = 0` opts out, and `honour_job_limits: false` ignores it.
    */
    'release_limit_seconds' => (int) env('TORQUE_RELEASE_LIMIT', 86400),

    /*
    |--------------------------------------------------------------------------
    | Job timeouts
    |--------------------------------------------------------------------------
    | Off, `$timeout` and a stream's `timeout` mean nothing and a job runs for as
    | long as it runs. On, a job gets its `$timeout`, else its stream's `timeout`,
    | else no limit; 0 is no limit. Independent of `honour_job_limits`.
    |
    | A job past its limit is told through interrupted(SIGALRM), then fails if
    | `$failOnTimeout` is set or its attempts are up, and is otherwise released
    | with a delay of at least `release_delay_seconds`. The whole worker then
    | rotates: the other slots get `drain_grace_seconds`, and what is still
    | running after that is redelivered. A hang-prone stream is best off with a
    | low `slots_per_worker`.
    |
    | The limit can only hit a job that is waiting on the event loop. Code that
    | blocks the process never lets the timer run.
    |
    | The timer spans the job's handle() only. The stock worker's alarm also covers
    | the JobProcessing and JobProcessed listeners; here those are not timed.
    | interrupted() and the JobInterrupted, JobTimedOut and JobExceptionOccurred
    | listeners share `settle_seconds`, at most half of `drain_grace_seconds`. The
    | job is failed or released after that, also when one of them has not returned.
    |
    | enabled               : TORQUE_TIMEOUTS. Only jobs that declare a `$timeout`
    |                         (or a stream with `timeout`) are limited.
    | release_delay_seconds : minimum delay when a timed-out job is released.
    | settle_seconds        : see above.
    */
    'timeouts' => [
        'enabled' => (bool) env('TORQUE_TIMEOUTS', true),
        'release_delay_seconds' => 10,
        'settle_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection pools
    |--------------------------------------------------------------------------
    | Per-worker pools shared by the slots. `size` is the maximum open
    | connections; `idle_timeout` is the seconds an unused one is kept.
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
    |
    | enabled / min_workers / max_workers : bounds of the fleet.
    | scale_up_threshold / scale_down_threshold : slot utilisation (0 to 1)
    |     above or below which the master adds or removes a worker.
    | cooldown : seconds between two scaling decisions.
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
    | A timer coroutine checks every `check_interval` seconds and moves ripe jobs
    | to their stream.
    */
    'delayed' => [
        'check_interval' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead letter
    |--------------------------------------------------------------------------
    | Jobs that use up their limits go to the dead letter stream.
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
    | Long-wait alerts
    |--------------------------------------------------------------------------
    | Per-queue threshold in seconds for the oldest job still waiting to be
    | picked up, e.g. ['emails' => 60]. The master checks every 30 seconds,
    | and only the queues listed here. A queue over its threshold dispatches
    | Webpatser\Torque\Events\LongWaitDetected and sends notifications once;
    | it alerts again after it recovered, or after 15 minutes still over.
    | 0 or absent turns a queue off, and the default is off for all.
    |
    | Route the notifications in a service provider with
    | Torque::routeMailNotificationsTo('ops@example.com') and
    | Torque::routeSlackNotificationsTo($url) (Slack needs
    | laravel/slack-notification-channel).
    */
    'waits' => [
        // 'default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Long-wait alert limit (seconds)
    |--------------------------------------------------------------------------
    | How long one alert may take, booting the app included, shared across the
    | event and the notifications. Each runs in a process of its own and is killed
    | at its share, so a hanging listener or mail server costs the next one nothing.
    */
    'long_wait_alert_seconds' => (int) env('TORQUE_LONG_WAIT_ALERT_SECONDS', 60),

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
    | `enabled` turns recording off; `max_events` caps the events kept per job.
    */
    'job_events' => [
        'enabled' => true,
        'ttl' => 300,
        'max_events' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced jobs
    |--------------------------------------------------------------------------
    | Successful runs of silenced jobs are left out of the dashboard's job
    | records (queued and running states show until they finish). Failures are
    | always recorded. A job is silenced when it implements
    | Webpatser\Torque\Contracts\Silenced, is listed in `silenced`, or carries
    | a tag listed in `silenced_tags`.
    |
    | silenced:      list of job class names, e.g. [App\Jobs\Heartbeat::class].
    | silenced_tags: list of tags (explicit tags() or auto-tags such as
    |                'App\Models\User:42'); any match silences the job. Tags are
    |                worked out at dispatch and kept in the payload; encrypted
    |                jobs have none.
    */
    'silenced' => [],
    'silenced_tags' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    | `enabled` turns the master's fleet metrics off; `publish_interval` is the
    | seconds between publishes.
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
         *   (2y) = ~4300 fields. Hashes this size are never listpack-encoded,
         *   so the real cost is the dictEntry plus two sds allocations per
         *   field, roughly 100 bytes: budget ~430 KB, not the ~60 KB a payload
         *   count suggests.
         *
         * The same set exists per stream and per job class, so budget another
         * ~430 KB for each.
         *
         * Per host it is the same 4300 counter fields plus five gauge series,
         * so roughly 3 MB per machine. Hosts share one hash per tier (field
         * `{bucket}:{host}`), which keeps the write cost at seven round trips a
         * tick whatever the fleet size (three tiers of counters, three of
         * gauges, one index touch), but the storage still scales with the
         * number of distinct hostnames seen inside the retention window. On
         * Kubernetes the hostname is the pod name and changes on every rollout,
         * so that window can hold a lot more hosts than there are machines.
         *
         * `daily_days` at 0 keeps the daily tier forever (it costs 365 fields,
         * roughly 36 KB, per year).
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
    | Off by default (TORQUE_DASHBOARD=true turns it on). `path` and
    | `middleware` set the route; the `viewTorque` gate still applies.
    | `poll_intervals` (ms, 0 is paused) fill the refresh picker and
    | `default_poll_interval` is the one it opens on.
    */
    'dashboard' => [
        'enabled' => (bool) env('TORQUE_DASHBOARD', false),
        'path' => 'torque',
        'middleware' => ['web', 'auth'],
        'poll_intervals' => [0, 1000, 2000, 5000, 10000, 30000],
        'default_poll_interval' => 1000,

        /*
         * Time range the dashboard opens on: 1h, 24h, 7d or 90d. One global
         * window drives every screen (the topbar picker sets it and the session
         * remembers it), so the throughput chart, the queues table and the
         * workers cards always show the same period.
         */
        'default_range' => '1h',

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
    | Poll interval (ms)
    |--------------------------------------------------------------------------
    | How long an idle slot waits before it polls its stream again. Polling does
    | not block Redis, so timers keep firing. Not the connection's `block_for`.
    */
    'poll_interval_ms' => (int) env('TORQUE_POLL_INTERVAL_MS', env('TORQUE_BLOCK_FOR', 2000)),

    /*
    |--------------------------------------------------------------------------
    | Internal timer periods
    |--------------------------------------------------------------------------
    | Internal: leave these alone in production. They exist so the test suite can
    | run real workers without waiting out real timers. Workers read them once at
    | start. The `_check_` and `_poll_` values are floored at 0.05 seconds, so a
    | bad value cannot spin the loop, but a low one still means more cache and
    | Redis reads per worker, and `drain_grace_seconds` counts whole seconds, so a
    | stop check well under a second can end a drain almost a second early.
    |
    | stop_check_seconds          : stop, job and lifetime limits, memory limit, drain end.
    | down_check_seconds          : reads `artisan down`.
    | paused_poll_seconds         : how soon a slot polls again after a pause or `artisan down`.
    | pause_check_seconds         : queue:pause, circuit breaker and Torque's own pause key.
    | watchdog_check_seconds      : master gone, and queue:restart.
    | delayed_check_seconds       : moves ripe delayed jobs to their stream.
    | drain_slack_seconds         : added to pause + grace by torque:stop and torque:reload (at least 1).
    | reload_signal_slack_seconds : added to torque:reload's explicit --timeout (at least 0).
    */
    'intervals' => [
        'stop_check_seconds' => 1.0,
        'down_check_seconds' => 1.0,
        'paused_poll_seconds' => 1.0,
        'pause_check_seconds' => 2.0,
        'watchdog_check_seconds' => 2.0,
        'delayed_check_seconds' => 1.0,
        'drain_slack_seconds' => 15,
        'reload_signal_slack_seconds' => 5,
    ],

];
