<?php

declare(strict_types=1);

use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\RedisClient;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Webpatser\Torque\Console\TorqueFlushCommand;

use function Fledge\Async\Redis\createRedisConnector;

/*
 * torque:flush against a live Redis, under a prefix of its own so parallel
 * workers using `torque-test:` never see these keys.
 */

const FLUSH_PREFIX = 'torque-flush-test:';
const FLUSH_OTHER_PREFIX = 'torque-flush-other:';

function flushPayload(string $uuid): string
{
    return json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\Example', 'job' => 'x', 'data' => []], JSON_THROW_ON_ERROR);
}

function flushJobKey(string $uuid, string $prefix = FLUSH_PREFIX): string
{
    $key = $prefix.'job:'.$uuid;
    test()->redis->execute('XADD', $key, '*', 'type', 'queued');
    test()->redis->execute('ZADD', $prefix.'jobs:recent', (string) time(), $uuid);
    test()->redis->execute('ZADD', $prefix.'jobs:active', (string) time(), $uuid);

    return $key;
}

/**
 * A link that counts the commands it sends and can run a callback once,
 * right after the first command matching $after, before the next one goes out.
 */
function flushLink(?Closure $between = null, ?Closure $after = null): RedisLink
{
    return new class(new ReconnectingRedisLink(createRedisConnector(torqueRedisUri())), $between, $after) implements RedisLink
    {
        /** @var array<string, int> */
        public array $counts = [];

        public int $total = 0;

        private bool $armed = false;

        public function __construct(private readonly RedisLink $link, private ?Closure $between, private readonly ?Closure $after) {}

        public function execute(string $command, array $parameters): RedisResponse
        {
            if ($this->armed && $this->between !== null) {
                $between = $this->between;
                $this->between = null;
                $between();
            }

            $command = strtoupper($command);
            $this->counts[$command] = ($this->counts[$command] ?? 0) + 1;
            $this->total++;

            $response = $this->link->execute($command, $parameters);

            if ($this->after !== null && ($this->after)($command, $parameters)) {
                $this->armed = true;
            }

            return $response;
        }
    };
}

/**
 * Run torque:flush on the given link, returning [exit code, output].
 *
 * @return array{int, string}
 */
function flushWith(RedisLink $link, array $input): array
{
    $command = app(TorqueFlushCommand::class)->usingRedis(new RedisClient($link));
    $command->setLaravel(app());
    $output = new BufferedOutput;

    return [$command->run(new ArrayInput($input), $output), $output->fetch()];
}

function flushCleanup(): void
{
    foreach ([FLUSH_PREFIX, FLUSH_OTHER_PREFIX] as $prefix) {
        foreach (test()->redis->execute('KEYS', $prefix.'*') as $key) {
            test()->redis->execute('DEL', (string) $key);
        }
    }
}

beforeEach(function () {
    config([
        'torque.redis.prefix' => FLUSH_PREFIX,
        'torque.streams' => ['default' => [], 'emails' => []],
    ]);
    $this->redis = torqueRedis();
    flushCleanup();
});

afterEach(function () {
    flushCleanup();
});

function flushKeyExists(string $key): bool
{
    return (int) test()->redis->execute('EXISTS', $key) === 1;
}

// T3-4: queue name parsing.

it('flushes only the named queue when the list has a trailing comma', function () {
    $this->redis->execute('XADD', FLUSH_PREFIX.'default', '*', 'payload', flushPayload('d-1'));
    $this->redis->execute('XADD', FLUSH_PREFIX.'emails', '*', 'payload', flushPayload('e-1'));

    $code = Artisan::call('torque:flush', ['--queues' => 'emails,']);

    expect($code)->toBe(0)
        ->and((int) $this->redis->execute('XLEN', FLUSH_PREFIX.'default'))->toBe(1)
        ->and((int) $this->redis->execute('XLEN', FLUSH_PREFIX.'emails'))->toBe(0);
});

it('refuses an empty queue list and leaves the default queue alone', function (string $value) {
    $this->redis->execute('XADD', FLUSH_PREFIX.'default', '*', 'payload', flushPayload('d-1'));
    $this->redis->execute('ZADD', FLUSH_PREFIX.'default:delayed', (string) (time() + 60), flushPayload('d-2'));

    $code = Artisan::call('torque:flush', ['--queues' => $value]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('No queue names')
        ->and((int) $this->redis->execute('XLEN', FLUSH_PREFIX.'default'))->toBe(1)
        ->and((int) $this->redis->execute('ZCARD', FLUSH_PREFIX.'default:delayed'))->toBe(1);
})->with(['empty' => '', 'comma' => ',', 'blank' => ' , ']);

it('refuses an invalid queue name', function () {
    $this->redis->execute('XADD', FLUSH_PREFIX.'emails', '*', 'payload', flushPayload('e-1'));

    $code = Artisan::call('torque:flush', ['--queues' => 'emails,bad name']);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('Invalid queue name: bad name')
        ->and((int) $this->redis->execute('XLEN', FLUSH_PREFIX.'emails'))->toBe(1);
});

// T3-3: only the job keys of the flushed entries go.

it('deletes only the job keys of the flushed queue entries', function () {
    $group = config('torque.consumer_group');

    // emails: one claimed (in-flight) entry, two waiting, one delayed, one also dead-lettered.
    $this->redis->execute('XGROUP', 'CREATE', FLUSH_PREFIX.'emails', $group, '$', 'MKSTREAM');
    $this->redis->execute('XADD', FLUSH_PREFIX.'emails', '*', 'payload', flushPayload('e-inflight'));
    $this->redis->execute('XREADGROUP', 'GROUP', $group, 'w1', 'COUNT', '1', 'STREAMS', FLUSH_PREFIX.'emails', '>');
    $this->redis->execute('XADD', FLUSH_PREFIX.'emails', '*', 'payload', flushPayload('e-1'));
    $this->redis->execute('XADD', FLUSH_PREFIX.'emails', '*', 'payload', flushPayload('e-2'));
    $this->redis->execute('XADD', FLUSH_PREFIX.'emails', '*', 'payload', flushPayload('e-dl'));
    $this->redis->execute('ZADD', FLUSH_PREFIX.'emails:delayed', (string) (time() + 60), flushPayload('e-delayed'));
    $this->redis->execute('XADD', FLUSH_PREFIX.'dead-letter', '*', 'payload', flushPayload('e-dl'), 'original_queue', 'emails');

    // default: untouched queue.
    $this->redis->execute('XADD', FLUSH_PREFIX.'default', '*', 'payload', flushPayload('d-1'));
    $this->redis->execute('ZADD', FLUSH_PREFIX.'default:delayed', (string) (time() + 60), flushPayload('d-delayed'));

    foreach (['e-inflight', 'e-1', 'e-2', 'e-dl', 'e-delayed', 'd-1', 'd-delayed', 'dl-only', 'finished'] as $uuid) {
        flushJobKey($uuid);
    }
    flushJobKey('e-1', FLUSH_OTHER_PREFIX);

    $code = Artisan::call('torque:flush', ['--queues' => 'emails']);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('3 job keys');

    // Flushed: the waiting and delayed entries of `emails`, keys and index members.
    foreach (['e-1', 'e-2', 'e-delayed'] as $uuid) {
        expect(flushKeyExists(FLUSH_PREFIX.'job:'.$uuid))->toBeFalse("job:{$uuid} should be gone")
            ->and($this->redis->execute('ZSCORE', FLUSH_PREFIX.'jobs:recent', $uuid))->toBeNull()
            ->and($this->redis->execute('ZSCORE', FLUSH_PREFIX.'jobs:active', $uuid))->toBeNull();
    }

    // Kept: in-flight, dead-lettered, other queue, unrelated jobs, other prefix.
    foreach (['e-inflight', 'e-dl', 'd-1', 'd-delayed', 'dl-only', 'finished'] as $uuid) {
        expect(flushKeyExists(FLUSH_PREFIX.'job:'.$uuid))->toBeTrue("job:{$uuid} should survive")
            ->and($this->redis->execute('ZSCORE', FLUSH_PREFIX.'jobs:recent', $uuid))->not->toBeNull();
    }

    expect(flushKeyExists(FLUSH_OTHER_PREFIX.'job:e-1'))->toBeTrue()
        ->and($this->redis->execute('ZSCORE', FLUSH_OTHER_PREFIX.'jobs:recent', 'e-1'))->not->toBeNull()
        ->and((int) $this->redis->execute('XLEN', FLUSH_PREFIX.'default'))->toBe(1)
        ->and((int) $this->redis->execute('ZCARD', FLUSH_PREFIX.'default:delayed'))->toBe(1)
        ->and((int) $this->redis->execute('XLEN', FLUSH_PREFIX.'dead-letter'))->toBe(1)
        ->and(flushKeyExists(FLUSH_PREFIX.'emails:delayed'))->toBeFalse();
});

/*
 * A worker claims an entry after the command read it and before the command
 * removed it. That job is running now, so its event stream and its
 * jobs:active membership must stay.
 */
it('keeps the job key of an entry claimed while the flush reads the stream', function () {
    $group = config('torque.consumer_group');
    $stream = FLUSH_PREFIX.'emails';
    $redis = $this->redis;

    $redis->execute('XGROUP', 'CREATE', $stream, $group, '$', 'MKSTREAM');
    $redis->execute('XADD', $stream, '*', 'payload', flushPayload('e-inflight'));
    $redis->execute('XREADGROUP', 'GROUP', $group, 'w1', 'COUNT', '1', 'STREAMS', $stream, '>');
    $redis->execute('XADD', $stream, '*', 'payload', flushPayload('e-1'));
    $redis->execute('XADD', $stream, '*', 'payload', flushPayload('e-2'));

    foreach (['e-inflight', 'e-1', 'e-2'] as $uuid) {
        flushJobKey($uuid);
    }

    $link = flushLink(
        between: function () use ($redis, $group, $stream): void {
            // Delivers e-1, the oldest entry not yet delivered to the group.
            $redis->execute('XREADGROUP', 'GROUP', $group, 'w2', 'COUNT', '1', 'STREAMS', $stream, '>');
        },
        after: fn (string $command, array $parameters): bool => $command === 'XRANGE' && ($parameters[0] ?? null) === $stream,
    );

    [$code, $output] = flushWith($link, ['--queues' => 'emails']);

    expect($code)->toBe(0)
        ->and($output)->toContain('1 job keys')
        ->and(flushKeyExists(FLUSH_PREFIX.'job:e-1'))->toBeTrue()
        ->and($redis->execute('ZSCORE', FLUSH_PREFIX.'jobs:active', 'e-1'))->not->toBeNull()
        ->and(flushKeyExists(FLUSH_PREFIX.'job:e-inflight'))->toBeTrue()
        ->and(flushKeyExists(FLUSH_PREFIX.'job:e-2'))->toBeFalse()
        ->and($redis->execute('ZSCORE', FLUSH_PREFIX.'jobs:active', 'e-2'))->toBeNull();
});

// B1: claimed entries are in flight; the flush says how many it removed.

it('warns with the count of claimed entries it removed', function () {
    $group = config('torque.consumer_group');

    foreach (['emails', 'default'] as $queue) {
        $stream = FLUSH_PREFIX.$queue;
        $this->redis->execute('XGROUP', 'CREATE', $stream, $group, '$', 'MKSTREAM');
        $this->redis->execute('XADD', $stream, '*', 'payload', flushPayload("{$queue}-inflight"));
        $this->redis->execute('XREADGROUP', 'GROUP', $group, 'w1', 'COUNT', '1', 'STREAMS', $stream, '>');
        $this->redis->execute('XADD', $stream, '*', 'payload', flushPayload("{$queue}-waiting"));
    }

    // w2 claims emails-waiting and emails-inflight-2: three claimed on emails,
    // one on default, one entry still waiting on default.
    $this->redis->execute('XADD', FLUSH_PREFIX.'emails', '*', 'payload', flushPayload('emails-inflight-2'));
    $this->redis->execute('XREADGROUP', 'GROUP', $group, 'w2', 'COUNT', '2', 'STREAMS', FLUSH_PREFIX.'emails', '>');

    [$code, $output] = flushWith(flushLink(), []);

    expect($code)->toBe(0)
        ->and($output)->toContain('4 claimed jobs were in flight')
        ->and($output)->toContain('Flushed 5 queued');
});

it('does not warn about claimed entries when none are claimed', function () {
    $group = config('torque.consumer_group');
    $this->redis->execute('XGROUP', 'CREATE', FLUSH_PREFIX.'emails', $group, '$', 'MKSTREAM');
    $this->redis->execute('XADD', FLUSH_PREFIX.'emails', '*', 'payload', flushPayload('e-1'));

    [$code, $output] = flushWith(flushLink(), ['--queues' => 'emails']);

    expect($code)->toBe(0)
        ->and($output)->not->toContain('claimed');
});

/*
 * Round trips grow with the number of pages, not the number of jobs: 1100
 * waiting and 600 delayed jobs are three stream pages and two delayed pages.
 */
it('flushes in pages with a bounded number of round trips', function () {
    $stream = FLUSH_PREFIX.'emails';

    for ($i = 0; $i < 1100; $i++) {
        $this->redis->execute('XADD', $stream, '*', 'payload', flushPayload("w-{$i}"));
        $this->redis->execute('XADD', FLUSH_PREFIX."job:w-{$i}", '*', 'type', 'queued');
    }

    for ($i = 0; $i < 600; $i++) {
        $this->redis->execute('ZADD', $stream.':delayed', (string) (time() + 60 + $i), flushPayload("l-{$i}"));
        $this->redis->execute('XADD', FLUSH_PREFIX."job:l-{$i}", '*', 'type', 'queued');
    }

    $link = flushLink();

    [$code, $output] = flushWith($link, ['--queues' => 'emails']);

    expect($code)->toBe(0)
        ->and($output)->toContain('1100 queued, 600 delayed, 1700 job keys')
        ->and($this->redis->execute('KEYS', FLUSH_PREFIX.'job:*'))->toBe([])
        ->and((int) $this->redis->execute('XLEN', $stream))->toBe(0)
        ->and(flushKeyExists($stream.':delayed'))->toBeFalse()
        ->and($link->total)->toBeLessThan(60)
        // Job keys: one UNLINK per page. The single DEL is the redeliveries hash.
        ->and($link->counts['UNLINK'] ?? 0)->toBeLessThanOrEqual(5)
        ->and($link->counts['DEL'] ?? 0)->toBeLessThanOrEqual(1);
});
