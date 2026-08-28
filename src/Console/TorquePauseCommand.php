<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Console\Command;
use Webpatser\Torque\Job\CircuitBreaker;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Pause or resume the Torque queue worker.
 *
 * Sets or removes the `{prefix}paused` key in Redis. Workers check this key
 * in their main loop — when set, they skip reading new jobs but keep running
 * so in-flight jobs can complete.
 *
 * Resuming additionally force-closes any open circuit breaker, so an operator
 * who has fixed the upstream problem does not have to wait out the cooldown.
 */
final class TorquePauseCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:pause
        {action=toggle : Action to perform: pause, continue, or toggle}';

    /** @var string */
    protected $description = 'Pause, resume, or toggle Torque queue processing';

    public function handle(): int
    {
        /** @var array<string, mixed> $config */
        $config = config('torque');

        $redisUri = $config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
        $prefix = $config['redis']['prefix'] ?? 'torque:';

        $redis = createRedisClient($redisUri);

        $pausedKey = $prefix.'paused';
        $action = $this->argument('action');

        if (! in_array($action, ['pause', 'continue', 'toggle'], true)) {
            $this->components->error("Invalid action: {$action}. Must be one of: pause, continue, toggle.");

            return self::FAILURE;
        }

        $currentlyPaused = $this->isPaused($redis, $pausedKey);

        $shouldPause = match ($action) {
            'pause' => true,
            'continue' => false,
            'toggle' => ! $currentlyPaused,
        };

        // Resuming is also the operator override for a tripped circuit
        // breaker, and it applies even when the global pause flag was never
        // set: a stream can be paused by its breaker alone.
        if (! $shouldPause) {
            $closed = app(CircuitBreaker::class)->forceCloseAll(
                array_keys((array) ($config['streams'] ?? [])),
            );

            if ($closed !== []) {
                $this->components->info('Closed the circuit breaker on: '.implode(', ', $closed).'.');
            }
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
     * Check whether the paused flag is currently set in Redis.
     */
    private function isPaused(RedisClient $redis, string $key): bool
    {
        try {
            $result = $redis->execute('EXISTS', $key);

            return (int) $result === 1;
        } catch (RedisException) {
            return false;
        }
    }
}
