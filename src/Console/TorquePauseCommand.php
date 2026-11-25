<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Console\Command;
use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeName;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Pause or resume Torque queue processing, globally or per scope.
 *
 * Without options this sets or removes the global `{prefix}paused` key.
 * Workers check it in their main loop; when set, they skip reading new jobs
 * but keep running so in-flight jobs can complete. Resuming globally also
 * force-closes any open circuit breaker, so an operator who has fixed the
 * upstream problem does not have to wait out the cooldown.
 *
 * `--node` and `--queue` pause or resume single scopes through
 * {@see PauseScopes} and leave the global key alone. `continue --all` lifts
 * every pause there is and closes the breakers; `pause --all` is the global
 * pause.
 */
final class TorquePauseCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:pause
        {action=toggle : Action to perform: pause, continue, or toggle}
        {--node=* : Only this node (repeatable, comma lists allowed)}
        {--queue=* : Only this queue (repeatable, comma lists allowed)}
        {--all : With continue: lift the global, every node and every queue pause and close the breakers. With pause: the global pause}';

    /** @var string */
    protected $description = 'Pause, resume, or toggle Torque queue processing';

    public function handle(): int
    {
        $config = Narrow::map(config('torque'));
        $redisConfig = Narrow::map($config['redis'] ?? null);

        $redisUri = Narrow::string($redisConfig['uri'] ?? null, 'redis://127.0.0.1:6379');
        $prefix = Narrow::string($redisConfig['prefix'] ?? null, 'torque:');
        $streams = array_map(
            static fn (int|string $queue): string => (string) $queue,
            array_keys((array) ($config['streams'] ?? [])),
        );

        $action = $this->argument('action');

        if (! in_array($action, ['pause', 'continue', 'toggle'], true)) {
            $this->components->error("Invalid action: {$action}. Must be one of: pause, continue, toggle.");

            return self::FAILURE;
        }

        $nodes = $this->listOption('node');
        $queues = $this->listOption('queue');
        $all = (bool) $this->option('all');

        if ($all && ($nodes !== [] || $queues !== [])) {
            $this->components->error('--all cannot be combined with --node or --queue.');

            return self::FAILURE;
        }

        if ($all && $action === 'toggle') {
            $this->components->error('--all works with pause or continue, not toggle.');

            return self::FAILURE;
        }

        foreach ($nodes as $node) {
            try {
                NodeName::validate($node);
            } catch (\InvalidArgumentException $e) {
                $this->components->error('Nothing was changed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        foreach ($queues as $queue) {
            if (! in_array($queue, $streams, true)) {
                $known = $streams === [] ? 'none' : implode(', ', $streams);
                $this->components->error("Nothing was changed: unknown queue [{$queue}]. Configured queues: {$known}.");

                return self::FAILURE;
            }
        }

        $redis = createRedisClient($redisUri);

        if ($nodes !== [] || $queues !== []) {
            return $this->changeScopes(new PauseScopes($redis, $prefix), $action, $nodes, $queues);
        }

        if ($all && $action === 'continue') {
            return $this->continueAll($redis, $prefix, $streams);
        }

        return $this->changeGlobal($redis, $prefix.'paused', $action, $streams);
    }

    /**
     * The global pause, exactly as it behaved before scopes existed.
     *
     * @param  list<string>  $streams
     */
    private function changeGlobal(RedisClient $redis, string $pausedKey, string $action, array $streams): int
    {
        $currentlyPaused = $this->isPaused($redis, $pausedKey);

        $shouldPause = match ($action) {
            'pause' => true,
            'continue' => false,
            default => ! $currentlyPaused,
        };

        // Resuming is also the operator override for a tripped circuit
        // breaker, and it applies even when the global pause flag was never
        // set: a stream can be paused by its breaker alone.
        if (! $shouldPause) {
            $this->closeBreakers($streams);
        }

        if ($shouldPause === $currentlyPaused) {
            $state = $currentlyPaused ? 'paused' : 'running';
            $this->components->info("Torque is already {$state}.");

            return self::SUCCESS;
        }

        if ($shouldPause) {
            $redis->execute('SET', $pausedKey, (string) time());
            $this->components->warn('Torque paused. In-flight jobs will complete, but no new jobs will be picked up.');
        } else {
            $redis->execute('DEL', $pausedKey);
            $this->components->info('Torque resumed. Workers will begin picking up new jobs.');
        }

        return self::SUCCESS;
    }

    /**
     * Lift the global pause and every node and queue pause, then close the
     * breakers.
     *
     * @param  list<string>  $streams
     */
    private function continueAll(RedisClient $redis, string $prefix, array $streams): int
    {
        $globalCleared = Narrow::int($redis->execute('DEL', $prefix.'paused')) > 0;
        $scopes = new PauseScopes($redis, $prefix);
        $before = $scopes->all();
        $scopes->clearAll();

        $this->components->info($globalCleared ? 'Global pause lifted.' : 'Global pause was not set.');

        foreach (array_keys($before['nodes']) as $node) {
            $this->components->info("Node [{$node}] resumed.");
        }

        foreach (array_keys($before['queues']) as $queue) {
            $this->components->info("Queue [{$queue}] resumed.");
        }

        if ($before['nodes'] === [] && $before['queues'] === []) {
            $this->components->info('No node or queue was paused.');
        }

        $this->closeBreakers($streams);

        return self::SUCCESS;
    }

    /**
     * Pause, resume or toggle each named node and queue on its own state.
     *
     * @param  list<string>  $nodes
     * @param  list<string>  $queues
     */
    private function changeScopes(PauseScopes $scopes, string $action, array $nodes, array $queues): int
    {
        $current = $scopes->all();

        foreach ($nodes as $node) {
            $this->changeScope('Node', $node, $action, isset($current['nodes'][$node]),
                fn () => $scopes->pauseNode($node),
                fn () => $scopes->resumeNode($node),
            );
        }

        foreach ($queues as $queue) {
            $this->changeScope('Queue', $queue, $action, isset($current['queues'][$queue]),
                fn () => $scopes->pauseQueue($queue),
                fn () => $scopes->resumeQueue($queue),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Closure(): mixed  $pause
     * @param  \Closure(): mixed  $resume
     */
    private function changeScope(string $kind, string $name, string $action, bool $paused, \Closure $pause, \Closure $resume): void
    {
        $shouldPause = match ($action) {
            'pause' => true,
            'continue' => false,
            default => ! $paused,
        };

        if ($shouldPause === $paused) {
            $this->components->info($paused
                ? "{$kind} [{$name}] is already paused."
                : "{$kind} [{$name}] is already running.");

            return;
        }

        if ($shouldPause) {
            $pause();
            $this->components->warn("{$kind} [{$name}] paused. In-flight jobs will complete, but no new jobs will be picked up.");
        } else {
            $resume();
            $this->components->info("{$kind} [{$name}] resumed.");
        }
    }

    /**
     * @param  list<string>  $streams
     */
    private function closeBreakers(array $streams): void
    {
        $closed = app(CircuitBreaker::class)->forceCloseAll($streams);

        if ($closed !== []) {
            $this->components->info('Closed the circuit breaker on: '.implode(', ', $closed).'.');
        }
    }

    /**
     * A repeatable option with comma lists flattened, trimmed and deduplicated.
     * Empty entries are kept so validation refuses them.
     *
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $values = [];

        foreach ((array) $this->option($name) as $raw) {
            foreach (explode(',', Narrow::string($raw, '')) as $value) {
                $values[] = trim($value);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Check whether the paused flag is currently set in Redis.
     */
    private function isPaused(RedisClient $redis, string $key): bool
    {
        try {
            $result = $redis->execute('EXISTS', $key);

            return Narrow::int($result) === 1;
        } catch (RedisException) {
            return false;
        }
    }
}
