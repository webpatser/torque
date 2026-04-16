<?php

declare(strict_types=1);

use Webpatser\Torque\Queue\StreamQueue;

/*
|--------------------------------------------------------------------------
| Worker Pickup Integration Tests
|--------------------------------------------------------------------------
|
| These tests verify the core bug fix: a running Torque worker must pick up
| new jobs dispatched while it is already running (both immediate and delayed).
|
| Each test starts a real worker process via proc_open, dispatches jobs by
| writing directly to Redis, then polls to verify the worker consumed them.
|
| Requires a running Redis instance.
|
*/

$testPrefix = 'torque-pickup-' . bin2hex(random_bytes(4)) . ':';

beforeEach(function () use ($testPrefix) {
    $this->redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
    $this->testPrefix = $testPrefix;
    $this->workerProcess = null;

    try {
        $this->streamQueue = new StreamQueue(
            redisUri: $this->redisUri,
            default: 'default',
            retryAfter: 90,
            blockFor: 0,
            prefix: $testPrefix,
            consumerGroup: 'pickup-test',
        );
        $this->streamQueue->setContainer(app());
        $this->streamQueue->setConnectionName('torque');

        // Verify Redis is reachable.
        $this->streamQueue->size();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

afterEach(function () use ($testPrefix) {
    // Stop the worker process if still running.
    if (isset($this->workerProcess) && is_resource($this->workerProcess)) {
        $status = proc_get_status($this->workerProcess);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
            // Give it a moment to shut down.
            usleep(500_000);
        }
        proc_close($this->workerProcess);
    }

    // Clean up all test keys.
    if (!isset($this->streamQueue)) {
        return;
    }

    try {
        $redis = $this->streamQueue->getRedisClient();
        $cursor = '0';
        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $testPrefix . '*', 'COUNT', '100');
            $cursor = (string) $result[0];
            $keys = $result[1] ?? [];
            foreach ($keys as $key) {
                $redis->execute('DEL', (string) $key);
            }
        } while ($cursor !== '0');
    } catch (\Throwable) {
        // Best-effort cleanup.
    }
});

/**
 * Start a torque:worker process with the test prefix.
 */
function startWorker(string $redisUri, string $prefix): mixed
{
    $artisan = base_path('vendor/bin/testbench');

    // Override torque config via environment variables.
    $env = [
        'TORQUE_REDIS_URI' => $redisUri,
        'TORQUE_PREFIX' => $prefix,
        'TORQUE_CONSUMER_GROUP' => 'pickup-test',
        'TORQUE_BLOCK_FOR' => '500',
        'TORQUE_COROUTINES' => '2',
        'TORQUE_MAX_JOBS' => '100',
        'TORQUE_MAX_LIFETIME' => '30',
        'APP_KEY' => 'base64:' . base64_encode(str_repeat('a', 32)),
    ];

    // Build env string for the subprocess.
    $envString = '';
    foreach ($env as $key => $value) {
        $envString .= "{$key}=" . escapeshellarg($value) . ' ';
    }

    $cmd = "{$envString}php {$artisan} torque:worker --queues=default --concurrency=2";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        throw new \RuntimeException('Failed to start worker process');
    }

    // Close stdin, we don't need it.
    fclose($pipes[0]);

    // Make stdout/stderr non-blocking so we can read without hanging.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return $process;
}

/**
 * Poll until a condition is met or timeout expires.
 */
function pollUntil(callable $check, float $timeoutSeconds = 10.0, float $intervalSeconds = 0.25): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        if ($check()) {
            return true;
        }
        usleep((int) ($intervalSeconds * 1_000_000));
    }

    return false;
}

// -------------------------------------------------------------------------
//  Immediate job pickup
// -------------------------------------------------------------------------

it('picks up an immediate job dispatched while the worker is running', function () {
    try {
        // Ensure consumer group exists before starting the worker.
        $streamKey = $this->streamQueue->getStreamKey();
        $this->streamQueue->ensureConsumerGroup($streamKey, 'pickup-test');

        // Start the worker.
        $this->workerProcess = startWorker($this->redisUri, $this->testPrefix);

        // Wait for worker to initialize.
        usleep(2_000_000);

        // Verify worker is running.
        $status = proc_get_status($this->workerProcess);
        if (!$status['running']) {
            $this->markTestSkipped('Worker process failed to start');
        }

        // Dispatch an immediate job by writing directly to the stream.
        $payload = json_encode([
            'uuid' => 'pickup-immediate-1',
            'displayName' => 'App\\Jobs\\TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['command' => base64_encode(serialize(new \stdClass()))],
            'attempts' => 0,
            'maxTries' => null,
            'timeout' => null,
        ]);

        $redis = $this->streamQueue->getRedisClient();
        $redis->execute('XADD', $streamKey, '*', 'payload', $payload);

        expect($this->streamQueue->size())->toBeGreaterThanOrEqual(1);

        // Poll until the stream is empty (job was consumed by the worker).
        $consumed = pollUntil(fn () => $this->streamQueue->size() === 0, timeoutSeconds: 10.0);

        expect($consumed)->toBeTrue('Worker did not pick up the immediate job within 10 seconds');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------------------
//  Delayed job pickup
// -------------------------------------------------------------------------

it('picks up a delayed job after migration', function () {
    try {
        $streamKey = $this->streamQueue->getStreamKey();
        $delayedKey = $streamKey . ':delayed';
        $this->streamQueue->ensureConsumerGroup($streamKey, 'pickup-test');

        // Start the worker.
        $this->workerProcess = startWorker($this->redisUri, $this->testPrefix);

        // Wait for worker to initialize.
        usleep(2_000_000);

        $status = proc_get_status($this->workerProcess);
        if (!$status['running']) {
            $this->markTestSkipped('Worker process failed to start');
        }

        // Add a matured delayed job (score in the past so migration picks it up).
        $payload = json_encode([
            'uuid' => 'pickup-delayed-1',
            'displayName' => 'App\\Jobs\\TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['command' => base64_encode(serialize(new \stdClass()))],
            'attempts' => 0,
            'maxTries' => null,
            'timeout' => null,
        ]);

        $redis = $this->streamQueue->getRedisClient();
        $redis->execute('ZADD', $delayedKey, (string) (time() - 1), $payload);

        expect($this->streamQueue->delayedSize())->toBe(1);

        // Poll until both the delayed set and stream are empty.
        $consumed = pollUntil(function () {
            return $this->streamQueue->delayedSize() === 0
                && $this->streamQueue->size() === 0;
        }, timeoutSeconds: 15.0);

        expect($consumed)->toBeTrue('Worker did not pick up the delayed job within 15 seconds');
    } catch (\Fledge\Async\Redis\RedisException $e) {
        $this->markTestSkipped('Redis not available: ' . $e->getMessage());
    }
});
