<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Amp\Redis\RedisClient;
use Illuminate\Console\Command;

use function Amp\Redis\createRedisClient;

/**
 * Pause or resume the Torque queue worker.
 *
 * Sets or removes the `{prefix}paused` key in Redis. Workers check this key
 * in their main loop — when set, they skip reading new jobs but keep running
 * so in-flight jobs can complete.
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

        $pausedKey = $prefix . 'paused';
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
        } catch (\Amp\Redis\RedisException) {
            return false;
        }
    }
}
