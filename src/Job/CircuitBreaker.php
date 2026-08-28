<?php

declare(strict_types=1);

namespace Webpatser\Torque\Job;

use Fledge\Async\Redis\RedisClient;
use Illuminate\Contracts\Events\Dispatcher;
use Webpatser\Torque\Events\QueueCircuitClosed;
use Webpatser\Torque\Events\QueueCircuitOpened;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Per-stream failure-storm breaker, shared by every worker through Redis.
 *
 * When a dependency dies (the 2026-08-27 incident: the KvK API went down and
 * every job failed permanently), Torque would burn through the entire backlog
 * at full speed and write all of it to the dead-letter stream. The breaker
 * stops that: once the permanent-failure ratio of a stream's recent outcomes
 * crosses `threshold`, the stream is paused for `cooldown` seconds, probed
 * with a handful of jobs, and only fully resumed when a probe succeeds.
 *
 * State lives in three keys per stream, all of which carry an expiry so the
 * breaker can never accumulate the way the dead-letters did:
 *
 *   {prefix}cb:{queue}:window  capped list of recent outcomes (1 fail, 0 ok)
 *   {prefix}cb:{queue}:state   `open` while paused, TTL = cooldown
 *   {prefix}cb:{queue}:probes  half-open probe failures, TTL = 2 x cooldown
 *
 * Half-open is simply "state expired but probes has not": the pause is gone,
 * so jobs flow again, and the next outcomes decide. Recording an outcome is a
 * single EVAL round trip so the hot path stays at one extra Redis call.
 *
 * Only permanent failures (the dead-letter branch of the worker) count as
 * failures; a release/retry is neutral and is not recorded at all.
 */
final class CircuitBreaker
{
    /**
     * Record one outcome and apply the state machine atomically.
     *
     * KEYS[1] state, KEYS[2] probes, KEYS[3] window
     * ARGV[1] outcome (1 = permanent failure, 0 = success)
     * ARGV[2] window size   ARGV[3] min samples   ARGV[4] threshold
     * ARGV[5] cooldown      ARGV[6] half_open_max ARGV[7] window retention
     * ARGV[8] probes ttl
     *
     * Returns {verb, failures, samples} where verb is noop, opened, or closed.
     */
    private const LUA_RECORD = <<<'LUA'
local state = redis.call('GET', KEYS[1])
local probes = redis.call('GET', KEYS[2])
local outcome = ARGV[1]

local function trip(failures, samples)
    redis.call('SET', KEYS[1], 'open', 'EX', ARGV[5])
    redis.call('SET', KEYS[2], '0', 'EX', ARGV[8])
    redis.call('DEL', KEYS[3])
    return {'opened', tostring(failures), tostring(samples)}
end

if state == false and probes ~= false then
    if outcome == '0' then
        redis.call('DEL', KEYS[1], KEYS[2], KEYS[3])
        return {'closed', '0', '0'}
    end

    local n = redis.call('INCR', KEYS[2])
    redis.call('EXPIRE', KEYS[2], ARGV[8])

    if n >= tonumber(ARGV[6]) then
        return trip(n, n)
    end

    return {'noop', tostring(n), tostring(n)}
end

redis.call('LPUSH', KEYS[3], outcome)
redis.call('LTRIM', KEYS[3], 0, tonumber(ARGV[2]) - 1)
redis.call('EXPIRE', KEYS[3], ARGV[7])

if state ~= false or outcome == '0' then
    return {'noop', '0', '0'}
end

local entries = redis.call('LRANGE', KEYS[3], 0, -1)
local samples = #entries

if samples < tonumber(ARGV[3]) then
    return {'noop', '0', tostring(samples)}
end

local failures = 0
for i = 1, samples do
    if entries[i] == '1' then failures = failures + 1 end
end

if failures / samples >= tonumber(ARGV[4]) then
    return trip(failures, samples)
end

return {'noop', tostring(failures), tostring(samples)}
LUA;

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'enabled' => true,
        'window' => 100,
        'min_samples' => 20,
        'threshold' => 0.9,
        'cooldown' => 300,
        'half_open_max' => 5,
        'retention' => 3600,
    ];

    private ?RedisClient $redis = null;

    /**
     * @param  array<string, mixed>  $config  The global `circuit_breaker` block.
     * @param  array<string, mixed>  $streams  Stream configs, for `streams.<queue>.circuit_breaker` overrides.
     * @param  (\Closure(string): void)|null  $logger  Defaults to a stderr WARN line.
     * @param  RedisClient|null  $client  Reuse an open connection (short-lived CLI commands).
     */
    public function __construct(
        private readonly string $redisUri,
        private readonly string $prefix = 'torque:',
        private readonly array $config = [],
        private readonly array $streams = [],
        private readonly bool $cluster = false,
        private readonly ?Dispatcher $events = null,
        private readonly ?\Closure $logger = null,
        private readonly ?RedisClient $client = null,
    ) {}

    /**
     * Build a breaker from a merged Torque config array.
     *
     * @param  array<string, mixed>  $config
     */
    #[\NoDiscard]
    public static function fromConfig(
        array $config,
        ?Dispatcher $events = null,
        ?\Closure $logger = null,
        ?RedisClient $client = null,
    ): self {
        return new self(
            redisUri: (string) ($config['redis']['uri'] ?? 'redis://127.0.0.1:6379'),
            prefix: (string) ($config['redis']['prefix'] ?? 'torque:'),
            config: (array) ($config['circuit_breaker'] ?? []),
            streams: (array) ($config['streams'] ?? []),
            cluster: (bool) ($config['redis']['cluster'] ?? false),
            events: $events,
            logger: $logger,
            client: $client,
        );
    }

    /**
     * Merge a per-stream override over the global block.
     *
     * Returns null when the breaker is off for this stream: either globally
     * disabled, or opted out with `'circuit_breaker' => false`. Static and
     * pure so the precedence rules are unit testable.
     *
     * @param  array<string, mixed>  $global
     * @return array<string, mixed>|null
     */
    #[\NoDiscard]
    public static function resolveConfig(array $global, mixed $override): ?array
    {
        if ($override === false) {
            return null;
        }

        $settings = array_merge(self::DEFAULTS, $global, is_array($override) ? $override : []);

        if (! (bool) $settings['enabled']) {
            return null;
        }

        return $settings;
    }

    /**
     * Record a completed job. A success while half-open closes the breaker.
     */
    public function recordSuccess(string $queue): void
    {
        $this->record($queue, failure: false);
    }

    /**
     * Record a permanently failed job (the dead-letter branch).
     */
    public function recordFailure(string $queue): void
    {
        $this->record($queue, failure: true);
    }

    /**
     * Read a stream's breaker state.
     *
     * Returns null when the breaker is off for this stream, closed, or when
     * Redis cannot be reached. `resumes_at` is the unix timestamp at which an
     * open breaker goes half-open.
     *
     * @return array{state: string, resumes_at: int|null}|null
     */
    #[\NoDiscard]
    public function state(string $queue): ?array
    {
        if ($this->settingsFor($queue) === null) {
            return null;
        }

        try {
            $ttl = (int) $this->redis()->execute('TTL', $this->key($queue, 'state'));

            if ($ttl !== -2) {
                return ['state' => 'open', 'resumes_at' => $ttl > 0 ? time() + $ttl : null];
            }

            if ((int) $this->redis()->execute('EXISTS', $this->key($queue, 'probes')) === 1) {
                return ['state' => 'half-open', 'resumes_at' => null];
            }
        } catch (\Throwable) {
            // A breaker that cannot be read must never stop the queue.
        }

        return null;
    }

    /**
     * The subset of the given queues whose breaker is currently open.
     *
     * This is what the worker folds into its paused-queue set, so an open
     * breaker takes exactly the same route as `queue:pause <name>`.
     *
     * @param  list<string>  $queues
     * @return list<string>
     */
    #[\NoDiscard]
    public function openQueues(array $queues): array
    {
        $open = [];

        foreach ($queues as $queue) {
            if ($this->settingsFor($queue) === null) {
                continue;
            }

            try {
                if ((int) $this->redis()->execute('EXISTS', $this->key($queue, 'state')) === 1) {
                    $open[] = $queue;
                }
            } catch (\Throwable) {
                // Redis unreachable: report nothing rather than pausing blind.
                return $open;
            }
        }

        return $open;
    }

    /**
     * Force a breaker closed and reset its window (operator override).
     *
     * Returns true when the stream actually had an open or half-open breaker.
     */
    public function forceClose(string $queue, string $reason = 'manual'): bool
    {
        try {
            $removed = (int) $this->redis()->execute(
                'DEL',
                $this->key($queue, 'state'),
                $this->key($queue, 'probes'),
                $this->key($queue, 'window'),
            );
        } catch (\Throwable) {
            return false;
        }

        if ($removed === 0) {
            return false;
        }

        $this->dispatch(new QueueCircuitClosed($queue, $reason));
        $this->log("Circuit breaker for [{$queue}] closed ({$reason}).");

        return true;
    }

    /**
     * Force every given stream's breaker closed.
     *
     * @param  list<string>  $queues
     * @return list<string> The queues that had a breaker to close.
     */
    public function forceCloseAll(array $queues, string $reason = 'manual'): array
    {
        return array_values(array_filter($queues, fn (string $queue): bool => $this->forceClose($queue, $reason)));
    }

    /**
     * Resolved settings for a stream, or null when the breaker is off for it.
     *
     * @return array<string, mixed>|null
     */
    #[\NoDiscard]
    public function settingsFor(string $queue): ?array
    {
        return self::resolveConfig(
            $this->config,
            $this->streams[$queue]['circuit_breaker'] ?? null,
        );
    }

    /**
     * Apply one outcome to the state machine in a single round trip.
     */
    private function record(string $queue, bool $failure): void
    {
        $settings = $this->settingsFor($queue);

        if ($settings === null) {
            return;
        }

        $cooldown = max(1, (int) $settings['cooldown']);

        try {
            /** @var array<int, mixed>|null $result */
            $result = $this->redis()->execute(
                'EVAL',
                self::LUA_RECORD,
                '3',
                $this->key($queue, 'state'),
                $this->key($queue, 'probes'),
                $this->key($queue, 'window'),
                $failure ? '1' : '0',
                (string) max(1, (int) $settings['window']),
                (string) max(1, (int) $settings['min_samples']),
                (string) (float) $settings['threshold'],
                (string) $cooldown,
                (string) max(1, (int) $settings['half_open_max']),
                (string) max(1, (int) $settings['retention']),
                (string) ($cooldown * 2),
            );
        } catch (\Throwable $e) {
            // The breaker is a safety net, never a failure mode of its own.
            $this->log("Circuit breaker for [{$queue}] could not record an outcome: {$e->getMessage()}");

            return;
        }

        if (! is_array($result) || $result === []) {
            return;
        }

        $verb = (string) $result[0];
        $failures = (int) ($result[1] ?? 0);
        $samples = max(1, (int) ($result[2] ?? 0));

        if ($verb === 'opened') {
            $ratio = round($failures / $samples, 3);

            $this->dispatch(new QueueCircuitOpened(
                queue: $queue,
                failures: $failures,
                samples: $samples,
                ratio: $ratio,
                cooldown: $cooldown,
                resumesAt: time() + $cooldown,
            ));

            $this->log(
                "WARN circuit breaker OPEN for [{$queue}]: {$failures}/{$samples} jobs failed permanently; "
                ."pausing pickup for {$cooldown}s."
            );

            return;
        }

        if ($verb === 'closed') {
            $this->dispatch(new QueueCircuitClosed($queue, 'probe'));
            $this->log("Circuit breaker for [{$queue}] closed (probe succeeded).");
        }
    }

    /**
     * Build a breaker key. In cluster mode the queue name is hash tagged so
     * all three keys of a stream live in the same slot (the EVAL touches them
     * together), matching StreamQueue's stream-key convention.
     */
    private function key(string $queue, string $suffix): string
    {
        if ($this->cluster && ! str_contains($queue, '{')) {
            $queue = '{'.$queue.'}';
        }

        return $this->prefix.'cb:'.$queue.':'.$suffix;
    }

    private function redis(): RedisClient
    {
        return $this->redis ??= $this->client ?? createRedisClient($this->redisUri);
    }

    private function dispatch(object $event): void
    {
        try {
            $dispatcher = $this->events;

            if ($dispatcher === null && function_exists('app') && app()->bound('events')) {
                $dispatcher = app('events');
            }

            $dispatcher?->dispatch($event);
        } catch (\Throwable) {
            // Listener problems must not break job processing.
        }
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);

            return;
        }

        fwrite(STDERR, "[torque:worker] {$message}\n");
    }
}
