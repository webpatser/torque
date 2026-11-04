<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Webpatser\Torque\Job\DeadLetterHandler;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\DeadLetterRetryProbeJob;
use Webpatser\Torque\Tests\Fixtures\EncryptedDeadLetterRetryProbeJob;
use Webpatser\Torque\Tests\Fixtures\JobLimitsProbeJob;

/*
|--------------------------------------------------------------------------
| Retrying a dead-lettered job
|--------------------------------------------------------------------------
|
| A retried job is a new job: attempts back to 0, retryUntil refreshed, the
| maxExceptions count cleared. Each test dead-letters a job (through a real
| worker, or by writing the entry the worker would have written), retries it
| through DeadLetterHandler and runs a real WorkerProcess
| (tests/Fixtures/dead-letter-retry-worker.php) on what comes back.
|
| Requires a running Redis instance.
|
*/

/**
 * @return array{prefix: string, group: string, queue: string, dir: string, token: string, serializer: string, streamQueue: StreamQueue, handler: DeadLetterHandler}
 */
function dlr_context(string $serializer = 'json'): array
{
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-dlr-{$token}:";
    $group = 'dlr-test';
    $queue = 'dlr';
    $dir = sys_get_temp_dir()."/torque-dlr-{$token}";
    mkdir($dir.'/cache', 0777, true);

    $GLOBALS['dlr_cleanup'][] = [$prefix, $dir];

    $streamQueue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: $queue,
        retryAfter: 90,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: $group,
        serializer: $serializer,
    );
    $streamQueue->setContainer(app());
    $streamQueue->setConnectionName('torque');
    $streamQueue->ensureConsumerGroup($streamQueue->getStreamKey(), $group);

    // The worker and this process share the maxExceptions counter through files.
    config(['cache.default' => 'file', 'cache.stores.file.path' => $dir.'/cache', 'torque.serializer' => $serializer]);

    return [
        'prefix' => $prefix,
        'group' => $group,
        'queue' => $queue,
        'dir' => $dir,
        'token' => $token,
        'serializer' => $serializer,
        'streamQueue' => $streamQueue,
        'handler' => new DeadLetterHandler(redisUri: torqueRedisUri(), prefix: $prefix),
    ];
}

/**
 * Push a job and write it to the dead-letter stream with the given payload
 * fields changed, in the wire format it was pushed in.
 *
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed> The dead-lettered payload.
 */
function dlr_deadLetter(array $ctx, object $job, array $changes): array
{
    $redis = torqueRedis();
    $streamKey = $ctx['streamQueue']->getStreamKey();

    (void) $ctx['streamQueue']->push($job, '', $ctx['queue']);

    $entry = $redis->execute('XRANGE', $streamKey, '-', '+')[0];
    $redis->execute('XDEL', $streamKey, (string) $entry[0]);

    $payload = StreamQueue::decodePayload((string) $entry[1][1], $ctx['serializer']);
    $payload = $changes + $payload;

    $raw = $ctx['serializer'] === 'igbinary'
        ? igbinary_serialize($payload)
        : json_encode($payload, JSON_THROW_ON_ERROR);

    $ctx['handler']->handle($ctx['queue'], $raw, '0-0', new RuntimeException('probe threw'));

    return $payload;
}

/**
 * Run a real worker until max_jobs attempts have ended.
 *
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $settings
 * @return array{attempts: list<int>, failed: int, output: string, deadLetters: int}
 */
function dlr_run(array $ctx, array $settings): array
{
    $logFile = $ctx['dir'].'/'.bin2hex(random_bytes(3)).'.jsonl';
    $outputFile = $logFile.'.out';

    $settings += ['serializer' => $ctx['serializer'], 'cache_path' => $ctx['dir'].'/cache', 'stream' => ['max_retries' => 2]];

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Fixtures/dead-letter-retry-worker.php', torqueRedisUri(), $ctx['prefix'], $ctx['group'], $ctx['queue'], $logFile, json_encode($settings, JSON_THROW_ON_ERROR)],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start the worker.');
    }

    $deadline = microtime(true) + 30;

    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        usleep(50_000);
    }

    if ($timedOut = proc_get_status($process)['running']) {
        proc_terminate($process, SIGKILL);
    }

    proc_close($process);

    $output = (string) @file_get_contents($outputFile);

    if ($timedOut) {
        throw new RuntimeException("The worker timed out:\n{$output}");
    }

    $rows = array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [],
    );
    $ofType = static fn (string $type): array => array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === $type));

    return [
        'attempts' => array_column($ofType('handled'), 'attempt'),
        'failed' => count($ofType('failed')),
        'output' => $output,
        'deadLetters' => $ctx['handler']->count(),
    ];
}

afterEach(function () {
    $redis = torqueRedis();

    $cleanup = $GLOBALS['dlr_cleanup'] ?? [];
    $GLOBALS['dlr_cleanup'] = [];

    foreach ($cleanup as [$prefix, $dir]) {
        $cursor = '0';

        do {
            [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');
            $cursor = (string) $cursor;

            foreach ($keys ?? [] as $key) {
                $redis->execute('DEL', (string) $key);
            }
        } while ($cursor !== '0');

        foreach (glob($dir.'/cache/*') ?: [] as $entry) {
            is_dir($entry) ? @array_map('unlink', glob($entry.'/*') ?: []) : @unlink($entry);
        }

        array_map('unlink', glob($dir.'/*.*') ?: []);
    }
});

it('runs a job dead-lettered at its limit again, and lets it fail and retry up to the limit', function () {
    $ctx = dlr_context();
    (void) $ctx['streamQueue']->push(new JobLimitsProbeJob, '', $ctx['queue']);

    $first = dlr_run($ctx, ['max_jobs' => 2]);

    expect($first['attempts'])->toBe([1, 2])
        ->and($first['deadLetters'])->toBe(1);

    $id = $ctx['handler']->list(1)[0]['id'];
    $ctx['handler']->retry($id);

    expect($ctx['handler']->count())->toBe(0);

    $second = dlr_run($ctx, ['max_jobs' => 2]);

    expect($second['attempts'])->toBe([1, 2])
        ->and($second['deadLetters'])->toBe(1);
});

it('runs a job past its retryUntil under strict_attempts after a retry', function () {
    $ctx = dlr_context();
    dlr_deadLetter($ctx, new DeadLetterRetryProbeJob(until: 1), ['retryUntil' => time() - 60, 'attempts' => 2]);

    $ctx['handler']->retry($ctx['handler']->list(1)[0]['id']);

    $run = dlr_run($ctx, ['max_jobs' => 1, 'strict_attempts' => true]);

    expect($run['attempts'])->toBe([1])
        ->and($run['failed'])->toBe(0)
        ->and($run['deadLetters'])->toBe(0);
});

it('retries an igbinary entry and keeps it igbinary', function () {
    if (! extension_loaded('igbinary')) {
        $this->markTestSkipped('ext-igbinary is not loaded.');
    }

    $ctx = dlr_context('igbinary');
    dlr_deadLetter($ctx, new JobLimitsProbeJob, ['attempts' => 5]);

    $ctx['handler']->retry($ctx['handler']->list(1)[0]['id']);

    $stored = (string) torqueRedis()->execute('XRANGE', $ctx['streamQueue']->getStreamKey(), '-', '+')[0][1][1];

    expect(StreamQueue::decodePayload($stored, 'igbinary')['attempts'])->toBe(0)
        ->and(str_starts_with($stored, "\x00\x00\x00\x02"))->toBeTrue();

    $run = dlr_run($ctx, ['max_jobs' => 2]);

    expect($run['attempts'])->toBe([1, 2])
        ->and($run['deadLetters'])->toBe(1);
});

it('does not fail a retried job on a used-up maxExceptions count', function () {
    $ctx = dlr_context();
    $payload = dlr_deadLetter($ctx, new JobLimitsProbeJob(tries: 5, maxExceptions: 2), ['attempts' => 1]);

    app('cache')->put('job-exceptions:'.$payload['uuid'], 1, now()->addDay());

    $ctx['handler']->retry($ctx['handler']->list(1)[0]['id']);

    expect(app('cache')->has('job-exceptions:'.$payload['uuid']))->toBeFalse();

    $run = dlr_run($ctx, ['max_jobs' => 2, 'stream' => ['max_retries' => 5]]);

    expect($run['attempts'])->toBe([1, 2]);
});

it('refreshes retryUntil and resets attempts for an encrypted command, and keeps it encrypted', function () {
    $ctx = dlr_context();
    dlr_deadLetter($ctx, new EncryptedDeadLetterRetryProbeJob(until: 1), ['retryUntil' => time() - 60, 'attempts' => 4]);

    $dead = StreamQueue::decodePayload($ctx['handler']->list(1)[0]['payload']);
    expect(str_starts_with($dead['data']['command'], 'O:'))->toBeFalse();

    $ctx['handler']->retry($ctx['handler']->list(1)[0]['id']);

    $stored = StreamQueue::decodePayload((string) torqueRedis()->execute('XRANGE', $ctx['streamQueue']->getStreamKey(), '-', '+')[0][1][1]);

    expect($stored['attempts'])->toBe(0)
        ->and($stored['retryUntil'])->toBeGreaterThan(time())
        ->and(str_starts_with($stored['data']['command'], 'O:'))->toBeFalse()
        ->and(unserialize(app(Encrypter::class)->decrypt($stored['data']['command'])))->toBeInstanceOf(EncryptedDeadLetterRetryProbeJob::class)
        ->and($ctx['handler']->count())->toBe(0);
});

/**
 * An in-memory failed_jobs table behind the database-uuids failer, bound as
 * the app's failer the way queue:failed and the worker's JobFailed listener see it.
 */
function dlr_failedJobsTable(): DatabaseUuidFailedJobProvider
{
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    Schema::dropIfExists('failed_jobs');
    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    $failer = new DatabaseUuidFailedJobProvider(app('db'), 'sqlite', 'failed_jobs');
    app()->instance('queue.failer', $failer);

    return $failer;
}

it('forgets the failed_jobs row on retry, so a retried job that fails again is logged with its new exception', function () {
    $failer = dlr_failedJobsTable();
    $ctx = dlr_context();
    $payload = dlr_deadLetter($ctx, new JobLimitsProbeJob, ['attempts' => 2]);

    $failer->log('torque', $ctx['queue'], json_encode($payload, JSON_THROW_ON_ERROR), new RuntimeException('first failure'));
    expect(DB::table('failed_jobs')->where('uuid', $payload['uuid'])->count())->toBe(1);

    $ctx['handler']->retry($ctx['handler']->list(1)[0]['id']);

    // Without the forget the unique uuid column rejects the second log (duplicate key)
    // and the row would keep "first failure".
    expect(DB::table('failed_jobs')->where('uuid', $payload['uuid'])->count())->toBe(0);

    $failer->log('torque', $ctx['queue'], json_encode($payload, JSON_THROW_ON_ERROR), new RuntimeException('second failure'));

    expect((string) DB::table('failed_jobs')->where('uuid', $payload['uuid'])->value('exception'))->toContain('second failure');
});

it('retries cleanly when no database-uuids failer is bound', function () {
    app()->instance('queue.failer', new class
    {
        public function log(): void {}
    });
    $ctx = dlr_context();
    dlr_deadLetter($ctx, new JobLimitsProbeJob, ['attempts' => 2]);

    $ctx['handler']->retry($ctx['handler']->list(1)[0]['id']);

    expect($ctx['handler']->count())->toBe(0);
});

it('keeps the dead-letter entry when the job class no longer exists', function () {
    $ctx = dlr_context();
    $payload = [
        'uuid' => 'missing-class',
        'attempts' => 3,
        'data' => ['commandName' => 'No\\Such\\Class', 'command' => 'O:13:"No\\Such\\Class":0:{}'],
    ];

    $ctx['handler']->handle($ctx['queue'], json_encode($payload, JSON_THROW_ON_ERROR), '0-0', new RuntimeException('gone'));

    expect(fn () => $ctx['handler']->retry($ctx['handler']->list(1)[0]['id']))
        ->toThrow(RuntimeException::class, 'no longer exists')
        ->and($ctx['handler']->count())->toBe(1)
        ->and(torqueRedis()->execute('XLEN', $ctx['streamQueue']->getStreamKey()))->toBe(0);
});

it('renders a stream backoff given as a list on the settings page', function () {
    config(['torque.streams' => ['default' => ['backoff' => [1, 5, 10]]]]);

    Livewire::addComponent('torque.settings-page', viewPath: dirname(__DIR__, 2).'/src/Dashboard/resources/views/livewire/pages/settings-page.wire.php');

    Gate::define('viewTorque', fn ($user = null): bool => true);

    $html = Livewire::actingAs(torqueTestUser())->test('torque.settings-page')->html();

    expect(substr($html, (int) strpos($html, 'Configured Streams'), 1500))->toContain('1,5,10');
});
