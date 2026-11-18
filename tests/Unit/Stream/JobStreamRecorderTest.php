<?php

declare(strict_types=1);

use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\RedisClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Stream\JobStreamRecorder;
use Webpatser\Torque\Tests\TestCase;

use function Fledge\Async\Redis\createRedisConnector;

// The dispatch test resolves the recorder from the container.
uses(TestCase::class);

/**
 * A link around the real connection that logs every command with the number of
 * replies already received when it was sent. Commands sent before any earlier
 * one answered share a round trip, so the distinct values count the round trips.
 */
final class RecorderCountingLink implements RedisLink
{
    /** @var list<array{command: string, parameters: list<string>, after: int}> */
    public array $log = [];

    private int $answered = 0;

    /**
     * Answer every script over more than one key with CROSSSLOT, as a Redis
     * Cluster does when the keys hash to different slots.
     */
    public bool $crossSlot = false;

    public function __construct(private readonly RedisLink $link) {}

    public function execute(string $command, array $parameters): RedisResponse
    {
        $parameters = array_map(strval(...), array_values($parameters));
        $this->log[] = ['command' => strtoupper($command), 'parameters' => $parameters, 'after' => $this->answered];

        if ($this->crossSlot && in_array(strtoupper($command), ['EVAL', 'EVALSHA'], true) && (int) ($parameters[1] ?? 0) > 1) {
            $this->answered++;

            return new RedisError("CROSSSLOT Keys in request don't hash to the same slot");
        }

        try {
            return $this->link->execute($command, $parameters);
        } finally {
            $this->answered++;
        }
    }

    public function reset(): void
    {
        $this->log = [];
        $this->answered = 0;
    }

    public function roundTrips(): int
    {
        return count(array_unique(array_column($this->log, 'after')));
    }
}

/**
 * A recorder whose connection runs through a counting link.
 *
 * @return array{JobStreamRecorder, RecorderCountingLink}
 */
function countingRecorder(bool $cluster, int $ttl = 300, string $prefix = 'torque-test:', array $options = [], bool $crossSlot = false): array
{
    $link = new RecorderCountingLink(new ReconnectingRedisLink(createRedisConnector('redis://127.0.0.1:6379/15')));

    $recorder = new JobStreamRecorder(...[
        'redisUri' => 'redis://127.0.0.1:6379/15',
        'prefix' => $prefix,
        'ttl' => $ttl,
        ...($cluster ? ['cluster' => true] : []),
        ...$options,
    ]);

    (new ReflectionProperty($recorder, 'redis'))->setValue($recorder, new RedisClient($link));

    // The first script call on a server may need EVAL after a NOSCRIPT: load
    // it here, so no measured call pays that extra round trip.
    (new RedisClient($link))->eval((new ReflectionClassConstant(JobStreamRecorder::class, 'LUA_RECORD'))->getValue(), [$prefix.'warm-up'], ['other', '1', '1', '1', '0', 'warm-up', '', 'type', 'warm']);
    $link->reset();
    $link->crossSlot = $crossSlot;

    return [$recorder, $link];
}

/**
 * Delete every key under a prefix.
 */
function forgetRecorderPrefix(string $prefix): void
{
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');

    foreach ($redis->execute('KEYS', $prefix.'*') as $key) {
        $redis->execute('DEL', (string) $key);
    }
}

final class RecorderRoundTripJob implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function tags(): array
    {
        return ['team:7'];
    }

    public function handle(): void {}
}

it('records each lifecycle event in one round trip and keeps the indexes', function (bool $cluster) {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-rt-'.bin2hex(random_bytes(8));

    [$recorder, $link] = countingRecorder($cluster);

    $recorder->record($uuid, 'queued', ['queue' => 'default']);
    expect($link->roundTrips())->toBe(1)
        ->and($redis->execute('ZSCORE', 'torque-test:jobs:recent', $uuid))->not->toBeNull()
        ->and($redis->execute('ZSCORE', 'torque-test:jobs:active', $uuid))->not->toBeNull();

    $link->reset();
    $recorder->record($uuid, 'started', ['worker' => 'w1']);
    expect($link->roundTrips())->toBe(1);

    $link->reset();
    $recorder->record($uuid, 'completed', ['memory_bytes' => '1'], terminal: true);
    expect($link->roundTrips())->toBe(1)
        ->and($redis->execute('ZSCORE', 'torque-test:jobs:recent', $uuid))->not->toBeNull()
        ->and($redis->execute('ZSCORE', 'torque-test:jobs:active', $uuid))->toBeNull()
        ->and((int) $redis->execute('XLEN', 'torque-test:job:'.$uuid))->toBe(3)
        ->and((int) $redis->execute('TTL', 'torque-test:job:'.$uuid))->toBeGreaterThan(0)->toBeLessThanOrEqual(300);

    $redis->execute('DEL', 'torque-test:job:'.$uuid);
    $redis->execute('ZREM', 'torque-test:jobs:recent', $uuid);
})->with(['single node' => false, 'cluster' => true]);

it('passes every key a script touches through KEYS, one slot in cluster mode', function (bool $cluster, int $keys) {
    $uuid = 'test-keys-'.bin2hex(random_bytes(8));

    [$recorder, $link] = countingRecorder($cluster);

    $recorder->record($uuid, 'started', ['worker' => 'w1']);

    $scripts = array_values(array_filter($link->log, fn (array $entry) => in_array($entry['command'], ['EVAL', 'EVALSHA'], true)));

    expect($scripts)->toHaveCount(1)
        ->and($scripts[0]['parameters'][1])->toBe((string) $keys)
        ->and(array_slice($scripts[0]['parameters'], 2, $keys))->toBe(array_slice(
            ['torque-test:job:'.$uuid, 'torque-test:jobs:recent', 'torque-test:jobs:active'],
            0,
            $keys,
        ));

    \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15')->execute('DEL', 'torque-test:job:'.$uuid);
})->with(['single node' => [false, 3], 'cluster' => [true, 1]]);

it('trims both indexes when the trim is due, in the same round trip', function (bool $cluster) {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $prefix = 'torque-test-trim-'.bin2hex(random_bytes(4)).':';
    $uuid = 'test-trim-'.bin2hex(random_bytes(8));
    $due = false;

    [$recorder, $link] = countingRecorder($cluster, ttl: 60, prefix: $prefix, options: [
        'trimDue' => function () use (&$due): bool {
            return $due;
        },
    ]);

    $old = (string) (time() - 600);
    $redis->execute('ZADD', $prefix.'jobs:recent', $old, 'stale');
    $redis->execute('ZADD', $prefix.'jobs:active', $old, 'stale');

    $recorder->record($uuid, 'progress', ['message' => 'not due']);

    expect($redis->execute('ZSCORE', $prefix.'jobs:recent', 'stale'))->not->toBeNull()
        ->and($redis->execute('ZSCORE', $prefix.'jobs:active', 'stale'))->not->toBeNull();

    $due = true;
    $link->reset();
    $recorder->record($uuid, 'progress', ['message' => 'due']);

    expect($redis->execute('ZSCORE', $prefix.'jobs:recent', 'stale'))->toBeNull()
        ->and($redis->execute('ZSCORE', $prefix.'jobs:active', 'stale'))->toBeNull()
        ->and($redis->execute('ZSCORE', $prefix.'jobs:recent', $uuid))->not->toBeNull()
        ->and($link->roundTrips())->toBe(1);

    forgetRecorderPrefix($prefix);
})->with(['single node' => false, 'cluster' => true]);

it('falls back to the cluster form when a cluster answers the three-key script with CROSSSLOT', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $prefix = 'torque-test-crossslot-'.bin2hex(random_bytes(4)).':';
    $uuid = 'test-crossslot-'.bin2hex(random_bytes(8));

    Log::spy();

    [$recorder, $link] = countingRecorder(false, prefix: $prefix, crossSlot: true);

    $scripts = fn () => array_values(array_map(
        fn (array $entry) => (int) $entry['parameters'][1],
        array_filter($link->log, fn (array $entry) => in_array($entry['command'], ['EVAL', 'EVALSHA'], true)),
    ));

    // The first event: refused once, then retried in the one-key form. Not lost.
    $recorder->record($uuid, 'queued', ['queue' => 'default']);

    expect($scripts())->toBe([3, 1])
        ->and((int) $redis->execute('XLEN', $prefix.'job:'.$uuid))->toBe(1)
        ->and($redis->execute('ZSCORE', $prefix.'jobs:recent', $uuid))->not->toBeNull()
        ->and($redis->execute('ZSCORE', $prefix.'jobs:active', $uuid))->not->toBeNull();

    // Every later event goes straight to the one-key form.
    $link->reset();
    $recorder->record($uuid, 'started', ['worker' => 'w1']);
    $recorder->record($uuid, 'completed', ['memory_bytes' => '1'], terminal: true);

    expect($scripts())->toBe([1, 1])
        ->and((int) $redis->execute('XLEN', $prefix.'job:'.$uuid))->toBe(3)
        ->and($redis->execute('ZSCORE', $prefix.'jobs:active', $uuid))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message) => str_contains($message, 'torque.redis.cluster') && str_contains($message, 'TORQUE_CLUSTER'),
    );

    forgetRecorderPrefix($prefix);
});

it('records the queued event of a dispatch in one round trip with its name and tags', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $prefix = 'torque-test-push-'.bin2hex(random_bytes(4)).':';

    [$recorder, $link] = countingRecorder(false);
    app()->instance(JobStreamRecorder::class, $recorder);

    $queue = new StreamQueue(redisUri: 'redis://127.0.0.1:6379/15', prefix: $prefix);
    $queue->setContainer(app());
    $queue->setConnectionName('torque');

    $queue->push(new RecorderRoundTripJob);

    $entry = $redis->execute('XRANGE', $prefix.'default', '-', '+')[0];
    $uuid = json_decode((string) $entry[1][1], true)['uuid'];

    $events = $redis->execute('XRANGE', 'torque-test:job:'.$uuid, '-', '+');
    $fields = [];
    for ($i = 0, $c = count($events[0][1]); $i < $c; $i += 2) {
        $fields[(string) $events[0][1][$i]] = (string) $events[0][1][$i + 1];
    }

    expect($link->roundTrips())->toBe(1)
        ->and($fields['type'])->toBe('queued')
        ->and($fields['queue'])->toBe('default')
        ->and($fields['displayName'])->toBe(RecorderRoundTripJob::class)
        ->and($fields['tags'])->toBe('["team:7"]');

    $redis->execute('DEL', $prefix.'default', 'torque-test:job:'.$uuid);
    $redis->execute('ZREM', 'torque-test:jobs:recent', $uuid);
    $redis->execute('ZREM', 'torque-test:jobs:active', $uuid);
});

it('does not record when disabled', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-disabled-'.bin2hex(random_bytes(8));
    $key = 'torque-test:job:'.$uuid;

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
        enabled: false,
    );

    $recorder->record($uuid, 'queued', ['queue' => 'default']);

    expect((int) $redis->execute('EXISTS', $key))->toBe(0);
});

it('does not record with empty uuid', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->record('', 'queued', ['queue' => 'default']);

    // No key should exist for empty uuid.
    expect((int) $redis->execute('EXISTS', 'torque-test:job:'))->toBe(0);
});

it('records a queued event to redis stream', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-'.bin2hex(random_bytes(8));
    $key = 'torque-test:job:'.$uuid;

    // Clean up before test.
    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->onQueued($uuid, 'scrpr', 'App\\Jobs\\ScrapeKvK');

    $entries = $redis->execute('XRANGE', $key, '-', '+');

    expect($entries)->toBeArray()->not->toBeEmpty();

    $fields = [];
    for ($i = 0, $c = count($entries[0][1]); $i < $c; $i += 2) {
        $fields[(string) $entries[0][1][$i]] = (string) $entries[0][1][$i + 1];
    }

    expect($fields['type'])->toBe('queued');
    expect($fields['queue'])->toBe('scrpr');
    expect($fields['displayName'])->toBe('App\\Jobs\\ScrapeKvK');
    expect($fields)->toHaveKey('timestamp');

    // Clean up.
    $redis->execute('DEL', $key);
});

it('records custom events via emitCustom', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-'.bin2hex(random_bytes(8));
    $key = 'torque-test:job:'.$uuid;

    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->emitCustom($uuid, 'Processing item 5/10', progress: 0.5, data: ['item_id' => '42']);

    $entries = $redis->execute('XRANGE', $key, '-', '+');

    expect($entries)->toHaveCount(1);

    $fields = [];
    for ($i = 0, $c = count($entries[0][1]); $i < $c; $i += 2) {
        $fields[(string) $entries[0][1][$i]] = (string) $entries[0][1][$i + 1];
    }

    expect($fields['type'])->toBe('progress');
    expect($fields['message'])->toBe('Processing item 5/10');
    expect($fields['progress'])->toBe('0.5');
    expect($fields['item_id'])->toBe('42');

    $redis->execute('DEL', $key);
});

it('sets TTL on terminal events', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-'.bin2hex(random_bytes(8));
    $key = 'torque-test:job:'.$uuid;

    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
        ttl: 60,
    );

    // Started: the long safety TTL, in case no terminal event ever comes.
    $recorder->record($uuid, 'started', ['worker' => 'test']);
    $ttl = (int) $redis->execute('TTL', $key);
    expect($ttl)->toBeGreaterThan(60)->toBeLessThanOrEqual(604800);

    // Terminal event: the short TTL.
    $recorder->record($uuid, 'completed', ['duration_ms' => '100'], terminal: true);
    $ttl = (int) $redis->execute('TTL', $key);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $redis->execute('DEL', $key);
});

it('records multiple lifecycle events in order', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-'.bin2hex(random_bytes(8));
    $key = 'torque-test:job:'.$uuid;

    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    $recorder->record($uuid, 'queued', ['queue' => 'default']);
    $recorder->record($uuid, 'started', ['worker' => 'w1']);
    $recorder->record($uuid, 'completed', ['duration_ms' => '250'], terminal: true);

    $entries = $redis->execute('XRANGE', $key, '-', '+');

    expect($entries)->toHaveCount(3);

    $types = [];
    foreach ($entries as $entry) {
        for ($i = 0, $c = count($entry[1]); $i < $c; $i += 2) {
            if ((string) $entry[1][$i] === 'type') {
                $types[] = (string) $entry[1][$i + 1];
            }
        }
    }

    expect($types)->toBe(['queued', 'started', 'completed']);

    $redis->execute('DEL', $key);
});

it('records the display name on the started event for jobs from other drivers', function () {
    $redis = \Fledge\Async\Redis\createRedisClient('redis://127.0.0.1:6379/15');
    $uuid = 'test-'.bin2hex(random_bytes(8));
    $key = 'torque-test:job:'.$uuid;

    $redis->execute('DEL', $key);

    $recorder = new JobStreamRecorder(
        redisUri: 'redis://127.0.0.1:6379/15',
        prefix: 'torque-test:',
    );

    // A job that never got a torque "queued" event, as when a classic
    // queue:work drains a legacy redis list during a migration: the started
    // event is the first (and name-carrying) event in its stream.
    $job = new class($uuid)
    {
        public function __construct(private string $uuid) {}

        public function uuid(): string
        {
            return $this->uuid;
        }

        public function getQueue(): ?string
        {
            return 'default';
        }

        public function attempts(): int
        {
            return 1;
        }

        public function resolveName(): string
        {
            return 'App\\Jobs\\LegacyDrainedJob';
        }
    };

    $recorder->onProcessing(new JobProcessing('redis', $job));

    $entries = $redis->execute('XRANGE', $key, '-', '+');

    expect($entries)->toBeArray()->not->toBeEmpty();

    $fields = [];
    for ($i = 0, $c = count($entries[0][1]); $i < $c; $i += 2) {
        $fields[(string) $entries[0][1][$i]] = (string) $entries[0][1][$i + 1];
    }

    expect($fields['type'])->toBe('started');
    expect($fields['displayName'])->toBe('App\\Jobs\\LegacyDrainedJob');

    $redis->execute('DEL', $key);
});
