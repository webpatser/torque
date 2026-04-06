<?php

return [

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
    | Redis connection
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'uri' => env('TORQUE_REDIS_URI', 'redis://127.0.0.1:6379'),
        'prefix' => env('TORQUE_PREFIX', 'torque:'),
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
    */
    'streams' => [
        'default' => [
            'stream' => 'torque:stream:default',
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
    */
    'dead_letter' => [
        'stream' => 'torque:stream:dead-letter',
        'ttl' => 604800,
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
        'retention' => 3600,
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
        'poll_intervals' => [0, 1000, 2000, 5000, 10000, 30000, 60000],
        'default_poll_interval' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Block timeout (ms)
    |--------------------------------------------------------------------------
    | How long XREADGROUP blocks waiting for new messages.
    */
    'block_for' => (int) env('TORQUE_BLOCK_FOR', 2000),

];
