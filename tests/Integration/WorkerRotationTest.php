<?php

declare(strict_types=1);

use function Fledge\Async\Redis\createRedisClient;

/*
|--------------------------------------------------------------------------
| Worker Rotation Integration Test
|--------------------------------------------------------------------------
|
| End-to-end proof that drain_grace_seconds is a ceiling and not a wait.
|
| A worker that reaches max_worker_lifetime with no job in flight must exit
| immediately so the master can respawn it. Sleeping out the grace instead is
| what took scrpr down on 2026-08-31: all 16 workers reached their lifetime in
| the same second and then sat idle for the full 7200s grace while 48k jobs
| piled up behind them.
|
| The unit test for the predicate lives in tests/Unit/Worker; this one runs a
| real worker process, because the bug was never in the predicate.
|
| Requires a running Redis instance.
|
*/

$rotationPrefix = 'torque-rotation-'.bin2hex(random_bytes(4)).':';

beforeEach(function () use ($rotationPrefix) {
    $this->redisUri = env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15');
    $this->rotationPrefix = $rotationPrefix;

    try {
        createRedisClient($this->redisUri)->execute('PING');
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis not available: '.$e->getMessage());
    }
});

afterEach(function () use ($rotationPrefix) {
    try {
        $redis = createRedisClient(env('TORQUE_TEST_REDIS_URI', 'redis://127.0.0.1:6379/15'));
        $cursor = '0';
        do {
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $rotationPrefix.'*', 'COUNT', '100');
            $cursor = (string) $result[0];
            foreach ($result[1] ?? [] as $key) {
                $redis->execute('DEL', (string) $key);
            }
        } while ($cursor !== '0');
    } catch (Throwable) {
        // Best-effort cleanup.
    }
});

it('exits a rotated worker immediately instead of sleeping out the drain grace', function () {
    // A grace far larger than the test timeout: if the worker waits it out,
    // this test fails on the deadline rather than on the assertion, which is
    // exactly the production symptom.
    $graceSeconds = 600;
    $timeoutSeconds = 45.0;

    $env = [
        'TORQUE_REDIS_URI' => $this->redisUri,
        'TORQUE_PREFIX' => $this->rotationPrefix,
        'TORQUE_CONSUMER_GROUP' => 'rotation-test',
        'TORQUE_BLOCK_FOR' => '500',
        'TORQUE_COROUTINES' => '2',
        'TORQUE_MAX_JOBS' => '1000',
        'TORQUE_MAX_LIFETIME' => '2',
        'TORQUE_MAX_LIFETIME_JITTER' => '0',
        'TORQUE_DRAIN_GRACE' => (string) $graceSeconds,
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
    ];

    $envString = '';
    foreach ($env as $key => $value) {
        $envString .= "{$key}=".escapeshellarg($value).' ';
    }

    $cmd = $envString.'php '.dirname(__DIR__, 2).'/vendor/bin/testbench'.' torque:worker --queues=default --concurrency=2';

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start worker process');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $startedAt = microtime(true);
    $stderr = '';
    $deadline = $startedAt + $timeoutSeconds;
    $exited = false;

    while (microtime(true) < $deadline) {
        $stderr .= (string) stream_get_contents($pipes[2]);

        if (! proc_get_status($process)['running']) {
            $exited = true;

            break;
        }

        usleep(200_000);
    }

    $elapsed = microtime(true) - $startedAt;
    $stderr .= (string) stream_get_contents($pipes[2]);

    if (! $exited) {
        posix_kill(proc_get_status($process)['pid'], SIGKILL);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($exited)->toBeTrue(
        "Worker did not exit within {$timeoutSeconds}s while its drain grace was {$graceSeconds}s. stderr:\n".$stderr,
    )
        ->and($elapsed)->toBeLessThan((float) $graceSeconds)
        ->and($stderr)->toContain('Drain complete; no jobs in flight, exiting.')
        ->and($stderr)->not->toContain('Drain window expired; forcing exit.');
});
