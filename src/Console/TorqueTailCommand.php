<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Stream\JobStream;

/**
 * Tail a job's event stream in real-time.
 *
 * Usage:
 *   php artisan torque:tail --job=abc-123-def
 *   php artisan torque:tail --latest
 */
final class TorqueTailCommand extends Command
{
    protected $signature = 'torque:tail
        {--job= : Job UUID to tail}
        {--latest : Tail the most recently queued job}
        {--timeout=60 : Seconds to wait for events before stopping}';

    protected $description = 'Tail a job\'s event stream in real-time';

    public function handle(): int
    {
        $config = config('torque');
        $redisUri = $config['redis']['uri'] ?? 'redis://127.0.0.1:6379';
        $prefix = $config['redis']['prefix'] ?? 'torque:';

        $stream = new JobStream(redisUri: $redisUri, prefix: $prefix);

        $uuid = $this->option('job');

        if ($this->option('latest')) {
            $uuid = $this->findLatestJob($redisUri, $prefix);
        }

        if (! $uuid) {
            $this->components->error('Provide --job={uuid} or --latest.');

            return self::FAILURE;
        }

        $this->components->info("Tailing job: {$uuid}");
        $timeout = (float) $this->option('timeout');

        foreach ($stream->tail($uuid, $timeout) as $event) {
            $this->renderEvent($event);
        }

        $this->newLine();
        $this->components->info('Stream ended.');

        return self::SUCCESS;
    }

    /**
     * Render a single event to the console.
     */
    private function renderEvent(array $event): void
    {
        $type = $event['type'];
        $data = $event['data'];

        $icon = match ($type) {
            'queued' => '📦',
            'started' => '▶',
            'progress' => '⋯',
            'completed' => '✓',
            'failed', 'dead_lettered' => '✗',
            'exception' => '⚠',
            default => '·',
        };

        $color = match ($type) {
            'completed' => 'green',
            'failed', 'dead_lettered' => 'red',
            'exception' => 'yellow',
            'started' => 'cyan',
            default => 'white',
        };

        $timestamp = isset($data['timestamp'])
            ? date('H:i:s', (int) ((int) $data['timestamp'] / 1_000_000_000))
            : date('H:i:s');

        $detail = match ($type) {
            'queued' => ($data['displayName'] ?? '') . ' → ' . ($data['queue'] ?? 'default'),
            'started' => 'worker=' . ($data['worker'] ?? '?') . ' attempt=' . ($data['attempt'] ?? '1'),
            'progress' => ($data['message'] ?? '') . (isset($data['progress']) ? ' [' . round((float) $data['progress'] * 100) . '%]' : ''),
            'completed' => 'memory=' . (isset($data['memory_bytes']) ? round((int) $data['memory_bytes'] / 1024 / 1024, 1) . 'MB' : '?'),
            'failed' => ($data['exception_class'] ?? 'Exception') . ': ' . ($data['exception_message'] ?? ''),
            'exception' => 'attempt=' . ($data['attempt'] ?? '?') . ' ' . ($data['exception_message'] ?? ''),
            default => json_encode(array_diff_key($data, array_flip(['type', 'timestamp']))),
        };

        $this->output->writeln("  <fg={$color}>{$icon}</> <fg=gray>{$timestamp}</> <fg={$color};options=bold>{$type}</> {$detail}");
    }

    /**
     * Find the most recently created job stream key.
     */
    private function findLatestJob(string $redisUri, string $prefix): ?string
    {
        try {
            $redis = \Amp\Redis\createRedisClient($redisUri);
            $cursor = '0';
            $latest = null;
            $latestTime = 0;

            do {
                $result = $redis->execute('SCAN', $cursor, 'MATCH', $prefix . 'job:*', 'COUNT', '100');
                $cursor = (string) ($result[0] ?? '0');
                $keys = is_array($result[1] ?? null) ? $result[1] : [];

                foreach ($keys as $key) {
                    // Read the first entry to get its timestamp.
                    $entries = $redis->execute('XRANGE', (string) $key, '-', '+', 'COUNT', '1');

                    if (is_array($entries) && $entries !== []) {
                        $id = (string) $entries[0][0];
                        $ts = (int) explode('-', $id)[0];

                        if ($ts > $latestTime) {
                            $latestTime = $ts;
                            $latest = str_replace($prefix . 'job:', '', (string) $key);
                        }
                    }
                }
            } while ($cursor !== '0');

            return $latest;
        } catch (\Throwable) {
            return null;
        }
    }
}
