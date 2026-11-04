<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\Job as IlluminateJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Context;
use Webpatser\Torque\Job\AttemptContextRepository;
use Webpatser\Torque\Job\AttemptScope;
use Webpatser\Torque\Job\QuiescentReset;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Tests\Fixtures\InterruptProbeJob;
use Webpatser\Torque\Tests\Fixtures\IsolationProbeJob;
use Webpatser\Torque\Tests\Fixtures\ResetProbeJob;
use Webpatser\Torque\Tests\Fixtures\ScopedProbe;
use Webpatser\Torque\Worker\WorkerProcess;

use function Fledge\Async\async;

/*
|--------------------------------------------------------------------------
| Attempt Isolation Integration Tests
|--------------------------------------------------------------------------
|
| A real WorkerProcess (tests/Fixtures/attempt-isolation-worker.php) runs real
| jobs from a real stream in real Fibers, against stock laravel/framework. Jobs
| log what they see to a file; the children they dispatch stay in their stream,
| so the tests read the raw payloads a later worker would hydrate from.
|
| Requires a running Redis instance.
|
*/

/**
 * Run a real worker over the given jobs until it exits by itself.
 *
 * @param  list<object>  $jobs
 * @param  int|null  $maxJobs  How many jobs the worker runs before it exits, when jobs dispatch more of them.
 * @param  bool  $slowReset  Whether the worker's reset closure suspends.
 * @return array{rows: list<array<string, mixed>>, children: array<string, array<string, mixed>>, output: string}
 */
function torque_run_isolation_worker(array $jobs, int $concurrency, ?int $maxJobs = null, bool $slowReset = false): array
{
    $redis = torqueRedis();
    $token = bin2hex(random_bytes(4));
    $prefix = "torque-isolation-{$token}:";
    $group = 'isolation-test';
    $queueName = 'isolation';
    $directory = sys_get_temp_dir();
    $logFile = "{$directory}/torque-isolation-{$token}.jsonl";
    $outputFile = "{$directory}/torque-isolation-{$token}.out";

    $queue = new StreamQueue(
        redisUri: torqueRedisUri(),
        default: $queueName,
        retryAfter: 90,
        blockFor: 0,
        prefix: $prefix,
        consumerGroup: $group,
    );
    $queue->setContainer(app());
    $queue->setConnectionName('torque');
    $queue->ensureConsumerGroup($queue->getStreamKey(), $group);

    foreach ($jobs as $job) {
        (void) $queue->push($job, '', $queueName);
    }

    try {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/attempt-isolation-worker.php', torqueRedisUri(), $prefix, $group, $queueName, $logFile, (string) ($maxJobs ?? count($jobs)), (string) $concurrency, $slowReset ? '1' : '0'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        $deadline = microtime(true) + 45;

        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(100_000);
        }

        $timedOut = proc_get_status($process)['running'];

        if ($timedOut) {
            proc_terminate($process, SIGKILL);
        }

        proc_close($process);

        $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        if ($timedOut || $lines === []) {
            throw new RuntimeException('The worker '.($timedOut ? 'timed out' : 'logged nothing').":\n".@file_get_contents($outputFile));
        }

        $children = [];

        foreach ($redis->execute('XRANGE', $queue->getStreamKey('isolation-children'), '-', '+') ?? [] as [, $fields]) {
            $payload = json_decode((string) $fields[array_search('payload', $fields, true) + 1], true, flags: JSON_THROW_ON_ERROR);
            $children[$payload['displayName']] = $payload;
        }

        return [
            'rows' => array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines),
            'children' => $children,
            'output' => (string) file_get_contents($outputFile),
        ];
    } finally {
        @unlink($logFile);
        @unlink($outputFile);

        $cursor = '0';

        do {
            [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '100');
            $cursor = (string) $cursor;

            foreach ($keys ?? [] as $key) {
                $redis->execute('DEL', (string) $key);
            }
        } while ($cursor !== '0');
    }
}

/**
 * Two jobs in two slots of one worker: each adds its tenant, waits until the
 * other one has too, then dispatches a child.
 *
 * @return array{rows: list<array<string, mixed>>, children: array<string, array<string, mixed>>}
 */
function torque_interleaved_isolation_run(): array
{
    static $run = null;

    return $run ??= torque_run_isolation_worker([
        new IsolationProbeJob('tenant-a', waitFor: ['tenant-b'], dispatchesChild: true, probesAsyncChild: true),
        new IsolationProbeJob('tenant-b', waitFor: ['tenant-a'], dispatchesChild: true, probesAsyncChild: true),
    ], concurrency: 2);
}

/**
 * Two jobs one after the other on one slot of one worker.
 *
 * @return list<array<string, mixed>>
 */
function torque_sequential_isolation_log(): array
{
    static $rows = null;

    return $rows ??= torque_run_isolation_worker([
        new IsolationProbeJob('first'),
        new IsolationProbeJob('second'),
    ], concurrency: 1)['rows'];
}

/**
 * The position of a job's log line of the given type.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function torque_isolation_position(array $rows, string $type, string $job): int
{
    foreach ($rows as $position => $row) {
        if ($row['type'] === $type && $row['job'] === $job) {
            return $position;
        }
    }

    throw new RuntimeException("No {$type} line for {$job}.");
}

// The other tests in this file say nothing unless this one holds.
it('really interleaves two jobs in two slots of one worker', function () {
    ['rows' => $rows] = torque_interleaved_isolation_run();

    $position = static fn (string $type, string $job): int => torque_isolation_position($rows, $type, $job);

    expect(array_filter($rows, static fn (array $row): bool => $row['type'] === 'rendezvous-timeout'))->toBe([])
        ->and($position('context-added', 'tenant-a'))->toBeLessThan($position('handle-end', 'tenant-b'))
        ->and($position('context-added', 'tenant-b'))->toBeLessThan($position('handle-end', 'tenant-a'))
        ->and($position('context-added', 'tenant-a'))->toBeLessThan($position('resumed', 'tenant-b'))
        ->and($position('context-added', 'tenant-b'))->toBeLessThan($position('resumed', 'tenant-a'));
});

it('gives each child of interleaved jobs the Context of its own parent', function () {
    ['children' => $children] = torque_interleaved_isolation_run();

    $tenantOf = static function (string $parent) use ($children): ?string {
        $serialized = $children["child-of:{$parent}"]['illuminate:log:context']['data']['tenant'] ?? null;

        return $serialized === null ? null : unserialize($serialized);
    };

    expect(array_keys($children))->toEqualCanonicalizing(['child-of:tenant-a', 'child-of:tenant-b']);

    expect(['tenant-a' => $tenantOf('tenant-a'), 'tenant-b' => $tenantOf('tenant-b')])
        ->toBe(['tenant-a' => 'tenant-a', 'tenant-b' => 'tenant-b']);
});

it('keeps a running job\'s Context when another job starts in a second slot', function () {
    // Different keys on purpose: only the second job's hydrate can take the
    // first job's value away, an overwrite of the same key would prove less.
    ['rows' => $rows] = torque_run_isolation_worker([
        new IsolationProbeJob('long-running', waitFor: ['late-starter']),
        new IsolationProbeJob('late-starter', contextKey: 'other'),
    ], concurrency: 2);

    $position = static fn (string $type, string $job): int => torque_isolation_position($rows, $type, $job);

    expect(array_filter($rows, static fn (array $row): bool => $row['type'] === 'rendezvous-timeout'))->toBe([])
        ->and($position('context-added', 'long-running'))->toBeLessThan($position('handle-start', 'late-starter'))
        ->and($position('handle-start', 'late-starter'))->toBeLessThan($position('resumed', 'long-running'));

    expect($rows[$position('resumed', 'long-running')]['data'])->toBe(['tenant' => 'long-running'])
        ->and($rows[$position('handle-start', 'late-starter')]['data'])->toBe([]);
});

it('shares a job\'s Context with its own async() children and nobody else', function () {
    ['rows' => $rows] = torque_interleaved_isolation_run();

    $probe = static fn (string $job): array => $rows[torque_isolation_position($rows, 'async-child', $job)];

    expect($probe('tenant-a')['seen'])->toBe('tenant-a')
        ->and($probe('tenant-a')['parent_sees'])->toBe('tenant-a')
        ->and($probe('tenant-b')['seen'])->toBe('tenant-b')
        ->and($probe('tenant-b')['parent_sees'])->toBe('tenant-b');
});

it('reaches the routed repository through a facade and a listener bound before the install', function () {
    // Caches the framework's instance in the facade, as a booted app may have.
    Context::add('booted', 'yes');

    AttemptContextRepository::install(app());

    $payload = (new Repository(app('events')))->add('tenant', 'from-payload')->dehydrate();
    $job = new SyncJob(app(), json_encode(['illuminate:log:context' => $payload], JSON_THROW_ON_ERROR), 'torque', 'default');

    $inAttempt = async(static function () use ($job): array {
        AttemptScope::begin();
        event(new JobProcessing('torque', $job));
        $all = Context::all();
        AttemptScope::end();

        return $all;
    })->await();

    expect(Context::getFacadeRoot())->toBeInstanceOf(AttemptContextRepository::class)
        ->and($inAttempt)->toBe(['tenant' => 'from-payload'])
        ->and(Context::all())->toBe(['booted' => 'yes']);
});

it('starts a job with empty Context after another job added to it on the same slot', function () {
    $rows = torque_sequential_isolation_log();

    expect(torque_isolation_position($rows, 'handle-end', 'first'))
        ->toBeLessThan(torque_isolation_position($rows, 'handle-start', 'second'));

    $start = $rows[torque_isolation_position($rows, 'handle-start', 'second')];

    expect($start['data'])->toBe([])
        ->and($start['hidden'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Shutdown signals
|--------------------------------------------------------------------------
*/

it('runs interrupted() and JobInterrupted in the scope of the job they belong to', function () {
    ['rows' => $rows, 'children' => $children] = torque_run_isolation_worker([
        new InterruptProbeJob('tenant-a', waitFor: ['tenant-b'], sendsSignal: true),
        new InterruptProbeJob('tenant-b', waitFor: ['tenant-a']),
    ], concurrency: 2);

    $seen = static fn (string $type): array => array_column(array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === $type)), 'tenant', 'job');

    expect($seen('interrupted'))->toEqualCanonicalizing(['tenant-a' => 'tenant-a', 'tenant-b' => 'tenant-b'])
        ->and(array_column(array_filter($rows, static fn (array $row): bool => $row['type'] === 'job-interrupted-event'), 'tenant'))
        ->toEqualCanonicalizing(['tenant-a', 'tenant-b']);

    foreach (['tenant-a', 'tenant-b'] as $tenant) {
        $data = $children["child-of:{$tenant}"]['illuminate:log:context']['data'] ?? [];

        expect(array_keys($data))->toBe(['tenant'])
            ->and(unserialize($data['tenant']))->toBe($tenant);
    }
});

it('leaves the signal callback in its own scope, past a throwing interrupted() and a slot without a scope', function () {
    AttemptContextRepository::install(app());

    $stream = static function (?object $command): StreamJob {
        $job = new StreamJob(
            container: app(),
            streamQueue: new StreamQueue(redisUri: torqueRedisUri(), default: 'default', retryAfter: 90, blockFor: 0, prefix: 'torque-test:', consumerGroup: 'torque-test'),
            rawBody: '{"displayName":"Test","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","data":{"commandName":"Test","command":"serialized"}}',
            messageId: '1-0',
            connectionName: 'torque',
            queue: 'default',
        );

        if ($command !== null) {
            $handler = new CallQueuedHandler(app(BusDispatcher::class), app());
            (new ReflectionProperty(CallQueuedHandler::class, 'runningCommand'))->setValue($handler, $command);
            (new ReflectionProperty(IlluminateJob::class, 'instance'))->setValue($job, $handler);
        }

        return $job;
    };

    $seen = new ArrayObject;

    $command = static fn (bool $throws): Interruptible => new class($seen, $throws) implements Interruptible
    {
        public function __construct(private ArrayObject $seen, private bool $throws) {}

        public function interrupted(int $signal): void
        {
            $this->seen->append(Context::get('tenant'));

            if ($this->throws) {
                throw new RuntimeException('interrupted threw');
            }
        }
    };

    $scopeOf = static function (string $tenant): AttemptScope {
        $scope = new AttemptScope;
        $scope->context['data']['tenant'] = $tenant;

        return $scope;
    };

    $worker = new WorkerProcess(['redis' => ['uri' => torqueRedisUri()]]);

    $after = async(static function () use ($worker, $stream, $command, $scopeOf): array {
        // Slot 3 has a command but no scope, so it runs in this one.
        AttemptScope::within($scopeOf('tenant-caller'), static function () use ($worker, $stream, $command, $scopeOf): void {
            $worker->notifyInterrupted(SIGTERM, [
                0 => $stream($command(true)),
                1 => $stream(null),
                2 => $stream($command(false)),
                3 => $stream($command(false)),
            ], app(EventDispatcher::class), 'torque', 'default', [
                0 => $scopeOf('tenant-a'),
                2 => $scopeOf('tenant-b'),
            ]);
        });

        return Context::all();
    })->await();

    expect($seen->getArrayCopy())->toBe(['tenant-a', 'tenant-b', 'tenant-caller'])
        ->and($after)->not->toHaveKey('tenant');
});

it('restores the previous scope after within(), also on a throw', function (bool $hadScope) {
    AttemptContextRepository::install(app());

    $seen = async(static function () use ($hadScope): array {
        $previous = $hadScope ? AttemptScope::begin() : AttemptScope::ambient();
        $entered = new AttemptScope;

        $name = static fn (): string => match (AttemptScope::current()) {
            $entered => 'entered',
            $previous => 'previous',
            AttemptScope::ambient() => 'ambient',
            default => 'other',
        };

        $returned = AttemptScope::within($entered, $name);
        $afterReturn = $name();
        $thrownInside = null;

        try {
            AttemptScope::within($entered, static function () use (&$thrownInside, $name): never {
                $thrownInside = $name();

                throw new RuntimeException('inside the scope');
            });
        } catch (RuntimeException) {
            // Expected: within() does not swallow it.
        }

        $afterThrow = $name();

        // Revolt reuses this Fiber for the next dataset.
        AttemptScope::end();

        return [$returned, $afterReturn, $thrownInside, $afterThrow];
    })->await();

    expect($seen)->toBe(['entered', 'previous', 'entered', 'previous']);
})->with(['from an attempt' => [true], 'from ambient' => [false]]);

/*
|--------------------------------------------------------------------------
| Quiescent reset
|--------------------------------------------------------------------------
*/

/**
 * Two jobs one after the other on one slot of one worker.
 *
 * @return array{rows: list<array<string, mixed>>, children: array<string, array<string, mixed>>, output: string}
 */
function torque_sequential_reset_run(): array
{
    static $run = null;

    return $run ??= torque_run_isolation_worker([
        new ResetProbeJob('first'),
        new ResetProbeJob('second'),
    ], concurrency: 1);
}

it('gives every job a fresh scoped binding and Cache::memo at one slot', function () {
    ['rows' => $rows] = torque_sequential_reset_run();

    $first = $rows[torque_isolation_position($rows, 'start', 'first')];
    $second = $rows[torque_isolation_position($rows, 'start', 'second')];

    expect($second['scoped'])->toBeGreaterThan($first['scoped'])
        ->and($first['memo'])->toBeNull()
        ->and($second['memo'])->toBe('first');
});

it('flushes Context written outside an attempt at a reset', function () {
    ['rows' => $rows] = torque_sequential_reset_run();

    expect($rows[torque_isolation_position($rows, 'start', 'second')]['ambient'])->toBe([]);
});

it('never resets with a job in flight and resets once the worker is idle', function () {
    // "long" keeps a slot while "short-1" finishes on the other, so "short-2"
    // starts with an attempt finished and another still in flight.
    ['rows' => $rows] = torque_run_isolation_worker([
        new ResetProbeJob('long', waitFor: ['short-2'], dispatchesDelayed: [new ResetProbeJob('after-idle')]),
        new ResetProbeJob('short-1'),
        new ResetProbeJob('short-2'),
    ], concurrency: 2, maxJobs: 4);

    $position = static fn (string $type, string $job): int => torque_isolation_position($rows, $type, $job);
    $scoped = static fn (string $type, string $job): int => $rows[$position($type, $job)]['scoped'];

    expect(array_filter($rows, static fn (array $row): bool => $row['type'] === 'rendezvous-timeout'))->toBe([])
        ->and($position('end', 'short-1'))->toBeLessThan($position('start', 'short-2'))
        ->and($position('start', 'short-2'))->toBeLessThan($position('end', 'long'));

    expect($scoped('start', 'short-1'))->toBe($scoped('start', 'long'))
        ->and($scoped('start', 'short-2'))->toBe($scoped('start', 'long'))
        ->and($scoped('end', 'long'))->toBe($scoped('start', 'long'))
        ->and($scoped('start', 'after-idle'))->toBeGreaterThan($scoped('start', 'long'));
});

it('holds a second slot back while a reset that suspends is running', function () {
    ['rows' => $rows] = torque_run_isolation_worker([
        new ResetProbeJob('warmup', dispatchesDelayed: [new ResetProbeJob('one'), new ResetProbeJob('two')]),
    ], concurrency: 2, maxJobs: 3, slowReset: true);

    $position = static fn (string $type, string $job): int => torque_isolation_position($rows, $type, $job);

    expect(array_filter($rows, static fn (array $row): bool => $row['type'] === 'reset-start'))->toHaveCount(1)
        ->and($position('reset-end', 'worker'))->toBeLessThan($position('start', 'one'))
        ->and($position('reset-end', 'worker'))->toBeLessThan($position('start', 'two'));
});

it('keeps Context isolated between jobs after a reset', function () {
    ['rows' => $rows, 'children' => $children] = torque_run_isolation_worker([
        new ResetProbeJob('warmup', dispatchesDelayed: [
            new ResetProbeJob('tenant-a', waitFor: ['tenant-b'], dispatchesChild: true),
            new ResetProbeJob('tenant-b', waitFor: ['tenant-a'], dispatchesChild: true),
        ]),
    ], concurrency: 2, maxJobs: 3);

    $row = static fn (string $type, string $job): array => $rows[torque_isolation_position($rows, $type, $job)];

    $childTenant = static fn (string $parent): mixed => unserialize($children["child-of:{$parent}"]['illuminate:log:context']['data']['tenant']);

    expect($row('start', 'tenant-a')['scoped'])->toBeGreaterThan($row('start', 'warmup')['scoped'])
        ->and($row('end', 'tenant-a')['tenant'])->toBe('tenant-a')
        ->and($row('end', 'tenant-b')['tenant'])->toBe('tenant-b')
        ->and($row('end', 'tenant-a')['repository'])->toBe(AttemptContextRepository::class)
        ->and($row('end', 'tenant-b')['repository'])->toBe(AttemptContextRepository::class)
        ->and($childTenant('tenant-a'))->toBe('tenant-a')
        ->and($childTenant('tenant-b'))->toBe('tenant-b');
});

it('names the user scoped bindings in a boot WARN when slots share them', function () {
    ['output' => $output] = torque_run_isolation_worker([new ResetProbeJob('only')], concurrency: 2);

    $warning = collect(explode("\n", $output))->first(static fn (string $line): bool => str_contains($line, 'WARN') && str_contains($line, 'scoped bindings'));

    expect($warning)->toContain(ScopedProbe::class)
        ->and($warning)->toContain('isolation.named-binding')
        ->and($warning)->toContain('no job is in flight')
        ->and($warning)->not->toContain('Illuminate\\');
});

it('stays silent about scoped bindings at one slot', function () {
    ['output' => $output] = torque_sequential_reset_run();

    expect($output)->not->toContain('scoped bindings');
});

it('lists only non-framework scoped bindings', function () {
    $container = new Container;

    $container->scoped(ScopedProbe::class);
    $container->scoped('app.report-builder');
    $container->scoped(static fn (): ResetProbeJob => new ResetProbeJob('closure-typed'));
    $container->scoped(Repository::class);
    $container->scoped('image');
    $container->scoped('cache.__memoized:array');

    expect(QuiescentReset::userScopedBindings($container))->toBe([
        ScopedProbe::class,
        'app.report-builder',
        ResetProbeJob::class,
    ]);
});

it('hydrates Context from the environment into ambient at a reset, never into a later attempt', function () {
    $context = (new Repository(app('events')))->add('from-env', 'yes')->dehydrate();

    putenv('__LARAVEL_CONTEXT='.json_encode($context, JSON_THROW_ON_ERROR));

    try {
        $repository = AttemptContextRepository::install(app());

        $reset = QuiescentReset::for(app());
        $reset->attemptFinished();
        $reset->beforeAttempt(1);

        // Added through a held reference, then the container resolves it again:
        // that second resolve must not wipe this attempt through a hydrate.
        $inAttempt = async(static function () use ($repository): array {
            AttemptScope::begin();
            $repository->add('tenant', 'mine');
            app(Repository::class);
            $all = $repository->all();
            AttemptScope::end();

            return $all;
        })->await();

        expect($inAttempt)->toBe(['tenant' => 'mine'])
            ->and(Context::all())->toBe(['from-env' => 'yes']);
    } finally {
        putenv('__LARAVEL_CONTEXT');
        AttemptScope::flushAmbient();
    }
});

it('survives a reset that throws and tries again at the next quiet moment', function () {
    $calls = 0;

    $reset = new QuiescentReset(app(), static function () use (&$calls): void {
        $calls++;

        throw new RuntimeException('reset failed');
    });

    AttemptContextRepository::install(app());

    $reset->attemptFinished();
    $reset->beforeAttempt(1);
    $reset->beforeAttempt(1);

    expect($calls)->toBe(2);
});
