<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Stream\JobStreamRecorder;
use Webpatser\Torque\Tests\Fixtures\FakeQueueJob;
use Webpatser\Torque\Tests\Fixtures\TagPost;
use Webpatser\Torque\Tests\Fixtures\TagsEncryptedJob;
use Webpatser\Torque\Tests\Fixtures\TagsExplicitJob;
use Webpatser\Torque\Tests\Fixtures\TagsInvalidUtf8Job;
use Webpatser\Torque\Tests\Fixtures\TagsLogOnceJob;
use Webpatser\Torque\Tests\Fixtures\TagsMixedPropertiesJob;
use Webpatser\Torque\Tests\Fixtures\TagsPlainJob;
use Webpatser\Torque\Tests\Fixtures\TagsPublicModelJob;
use Webpatser\Torque\Tests\Fixtures\TagsSerializesJob;
use Webpatser\Torque\Tests\Fixtures\TagsSilencedJob;
use Webpatser\Torque\Tests\Fixtures\TagsTenantJob;
use Webpatser\Torque\Tests\Fixtures\TagsThrowingJob;
use Webpatser\Torque\Tests\Fixtures\TagUser;

use function Webpatser\Torque\Tests\Fixtures\tag_posts;
use function Webpatser\Torque\Tests\Fixtures\tag_user;

require_once dirname(__DIR__, 2).'/Fixtures/TagFixtures.php';

const TAG_TEST_PREFIX = 'torque-tags-test:';

const TAG_SAFETY_TTL = 604800;

final class TagsQueryFailingJob implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function tags(): array
    {
        DB::select('select * from torque_no_such_table');

        return ['never'];
    }

    public function handle(): void {}
}

function tagStreamTtl(string $uuid): int
{
    return (int) torqueRedis()->execute('TTL', TAG_TEST_PREFIX.'job:'.$uuid);
}

function tagExpectSafetyTtl(string $uuid): void
{
    expect(tagStreamTtl($uuid))->toBeGreaterThan(TAG_SAFETY_TTL - 60)->toBeLessThanOrEqual(TAG_SAFETY_TTL);
}

function tagExpectShortTtl(string $uuid): void
{
    expect(tagStreamTtl($uuid))->toBeGreaterThan(0)->toBeLessThanOrEqual(300);
}

function tagQueue(): StreamQueue
{
    $queue = new StreamQueue(redisUri: torqueRedisUri(), default: 'tags', retryAfter: 90, blockFor: 100, prefix: TAG_TEST_PREFIX, consumerGroup: 'tags-test');
    $queue->setContainer(app());
    $queue->setConnectionName('torque');

    return $queue;
}

/** @return array<string, mixed> The payload of the newest entry on the test queue. */
function tagPushedPayload(): array
{
    $entries = torqueRedis()->execute('XREVRANGE', TAG_TEST_PREFIX.'tags', '+', '-', 'COUNT', '1');

    return json_decode((string) $entries[0][1][1], true);
}

function tagRecorder(bool $enabled = true): JobStreamRecorder
{
    return new JobStreamRecorder(redisUri: torqueRedisUri(), prefix: TAG_TEST_PREFIX, enabled: $enabled);
}

/** @return list<array<string, string>> */
function tagStreamEvents(string $uuid): array
{
    $entries = torqueRedis()->execute('XRANGE', TAG_TEST_PREFIX.'job:'.$uuid, '-', '+');
    $events = [];

    foreach ($entries ?: [] as $entry) {
        $fields = [];
        for ($i = 0, $c = count($entry[1]); $i < $c; $i += 2) {
            $fields[(string) $entry[1][$i]] = (string) $entry[1][$i + 1];
        }
        $events[] = $fields;
    }

    return $events;
}

function tagUuid(): string
{
    return 'tags-'.bin2hex(random_bytes(6));
}

afterEach(function () {
    $redis = torqueRedis();
    $cursor = '0';

    do {
        [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', TAG_TEST_PREFIX.'*', 'COUNT', '200');
        $cursor = (string) $cursor;

        foreach ($keys ?? [] as $key) {
            $redis->execute('DEL', (string) $key);
        }
    } while ($cursor !== '0');
});

describe('at push', function () {
    it('stores explicit tags() in the payload, over auto-tags, dropping oversized ones', function () {
        (void) tagQueue()->push(new TagsExplicitJob(tag_user(7)));

        expect(tagPushedPayload()['torqueTags'])->toBe(['user:7', 'explicit']);
    });

    it('runs tags() on the real object, so a real attribute is there', function () {
        (void) tagQueue()->push(new TagsTenantJob(tag_user(1, ['tenant_id' => 7])));

        expect(tagPushedPayload()['torqueTags'])->toBe(['tenant:7']);
    });

    it('auto-tags public models and collections of models with no query and no relation load', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $user = tag_user(7);

        (void) tagQueue()->push(new TagsPublicModelJob($user, tag_posts(2)));

        expect(tagPushedPayload()['torqueTags'])->toBe([TagUser::class.':7', TagPost::class.':1', TagPost::class.':2'])
            ->and($queries)->toBe([])
            ->and($user->getRelations())->toBe([]);
    });

    it('tags untyped public models, skipping keyless models, protected models and other values', function () {
        (void) tagQueue()->push(new TagsMixedPropertiesJob(tag_user(5), new TagUser, tag_user(6)));

        expect(tagPushedPayload()['torqueTags'])->toBe([TagUser::class.':5']);
    });

    it('caps auto-tags at 50', function () {
        (void) tagQueue()->push(new TagsSerializesJob(tag_user(1), tag_posts(80)));

        expect(tagPushedPayload()['torqueTags'])->toHaveCount(50);
    });

    it('does not break a dispatch when tags() throws, and adds no field', function () {
        $id = tagQueue()->push(new TagsThrowingJob);

        expect($id)->toBeString()->and(tagPushedPayload())->not->toHaveKey('torqueTags');
    });

    it('rethrows a failing tags() query inside a transaction, which it may have aborted', function () {
        expect(fn () => DB::transaction(fn () => tagQueue()->push(new TagsQueryFailingJob)))
            ->toThrow(QueryException::class);

        expect(DB::transactionLevel())->toBe(0)
            ->and((int) torqueRedis()->execute('XLEN', TAG_TEST_PREFIX.'tags'))->toBe(0);
    });

    it('warns and dispatches when a tags() query fails outside a transaction', function () {
        Log::spy();

        $id = tagQueue()->push(new TagsQueryFailingJob);

        expect($id)->toBeString()->and(tagPushedPayload())->not->toHaveKey('torqueTags');
        Log::shouldHaveReceived('warning')->once();
    });

    it('still dispatches inside a transaction when tags() throws something other than a query error', function () {
        $id = DB::transaction(fn () => tagQueue()->push(new TagsThrowingJob));

        expect($id)->toBeString()->and(tagPushedPayload())->not->toHaveKey('torqueTags');
    });

    it('logs a throwing tags() once, not on every dispatch', function () {
        Log::spy();

        (void) tagQueue()->push(new TagsLogOnceJob);
        (void) tagQueue()->push(new TagsLogOnceJob);

        Log::shouldHaveReceived('warning')->once();
    });

    it('drops a tag JSON cannot carry instead of failing the dispatch', function () {
        (void) tagQueue()->push(new TagsInvalidUtf8Job);

        expect(tagPushedPayload()['torqueTags'])->toBe(['valid']);
    });

    it('adds nothing to the payload of a job without tags', function () {
        (void) tagQueue()->push(new TagsPlainJob);

        expect(tagPushedPayload())->not->toHaveKey('torqueTags');
    });

    it('gives a closure job and a raw push no tags', function () {
        (void) tagQueue()->push(function (): void {});
        expect(tagPushedPayload())->not->toHaveKey('torqueTags');

        tagQueue()->pushRaw(json_encode(['uuid' => tagUuid(), 'job' => 'x', 'data' => []]));
        expect(tagPushedPayload())->not->toHaveKey('torqueTags');
    });

    it('never calls tags() on an encrypted job, and leaves no plaintext ids in its payload', function () {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);

        (void) tagQueue()->push(new TagsEncryptedJob(tag_user(7)));

        $payload = tagPushedPayload();

        expect($payload)->not->toHaveKey('torqueTags')
            ->and(json_encode($payload))->not->toContain('TagUser');
    });

    it('stores tags on a delayed push', function () {
        (void) tagQueue()->later(60, new TagsExplicitJob(tag_user(7)));

        $delayed = torqueRedis()->execute('ZRANGE', TAG_TEST_PREFIX.'tags:delayed', '0', '-1');

        expect(json_decode((string) $delayed[0], true)['torqueTags'])->toBe(['user:7', 'explicit']);
    });

    it('keeps the field through a release', function () {
        $queue = tagQueue();
        (void) $queue->push(new TagsExplicitJob(tag_user(7)));

        $queue->pop()->release();

        $payload = tagPushedPayload();

        expect($payload['torqueTags'])->toBe(['user:7', 'explicit'])
            ->and($payload['attempts'])->toBe(1);
    });
});

describe('recording', function () {
    it('writes the payload tags on every event of an attempt', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob, ['a', 'b']);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->emitCustom($uuid, 'half', 0.5, [], $job);
        $recorder->onExceptionOccurred(new JobExceptionOccurred('torque', $job, new RuntimeException('retry')));
        $recorder->onProcessed(new JobProcessed('torque', $job));

        $events = tagStreamEvents($uuid);

        expect(array_column($events, 'type'))->toBe(['started', 'progress', 'exception', 'completed'])
            ->and(array_unique(array_column($events, 'tags')))->toBe(['["a","b"]']);
    });

    it('never unserializes the command', function () {
        $uuid = tagUuid();
        $job = new class($uuid)
        {
            public function __construct(private string $uuid) {}

            public function uuid(): string
            {
                return $this->uuid;
            }

            /** @return array<string, mixed> */
            public function payload(): array
            {
                return ['displayName' => 'x', 'data' => ['commandName' => 'x', 'command' => 'O:8:"stdClass":1:{s:1:"a";O:3:"Nope":0:{}']];
            }

            public function getQueue(): string
            {
                return 'default';
            }

            public function attempts(): int
            {
                return 1;
            }

            public function resolveName(): string
            {
                return 'x';
            }
        };

        Log::spy();

        tagRecorder()->onProcessing(new JobProcessing('torque', $job));

        // Unserializing this truncated command raises, which the recorder logs.
        Log::shouldNotHaveReceived('warning');
        expect(tagStreamEvents($uuid)[0])->not->toHaveKey('tags');
    });

    it('treats an older queued job without the field as having no tags', function () {
        $uuid = tagUuid();

        tagRecorder()->onProcessing(new JobProcessing('torque', new FakeQueueJob($uuid, new TagsExplicitJob(tag_user(7)))));

        expect(tagStreamEvents($uuid)[0])->not->toHaveKey('tags');
    });

    it('does no inspection at all when job streams are disabled', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob, ['a']);
        $recorder = tagRecorder(enabled: false);

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->onExceptionOccurred(new JobExceptionOccurred('torque', $job, new RuntimeException('x')));
        $recorder->onFailed(new JobFailed('torque', $job, new RuntimeException('x')));
        $recorder->onProcessed(new JobProcessed('torque', $job));

        expect($job->payloadReads)->toBe(0)->and(tagStreamEvents($uuid))->toBe([]);
    });

    it('reads a job once per attempt and keeps nothing once the job object is gone', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob, ['a']);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->onProcessed(new JobProcessed('torque', $job));
        expect($job->payloadReads)->toBe(1);

        $property = new ReflectionProperty(JobStreamRecorder::class, 'attempts');
        expect($property->getValue($recorder))->toBeInstanceOf(WeakMap::class)->toHaveCount(1);

        unset($job);
        gc_collect_cycles();

        expect($property->getValue($recorder))->toHaveCount(0);
    });

    it('leaves a failed job failed when JobExceptionOccurred follows JobFailed', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob, ['a']);
        $recorder = tagRecorder();
        $exception = new RuntimeException('nope');

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->onFailed(new JobFailed('torque', $job, $exception));
        $recorder->onExceptionOccurred(new JobExceptionOccurred('torque', $job, $exception));

        expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['started', 'failed'])
            ->and(torqueRedis()->execute('ZSCORE', TAG_TEST_PREFIX.'jobs:active', $uuid))->toBeNull();
    });

    it('records nothing as completed after a fail() without a throw', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $job->failed = true;
        $recorder->onFailed(new JobFailed('torque', $job, new RuntimeException('nope')));
        $recorder->onProcessed(new JobProcessed('torque', $job));

        expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['started', 'failed']);
    });

    it('records nothing as completed after a release', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $job->released = true;
        $recorder->onProcessed(new JobProcessed('torque', $job));

        expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['started']);
    });

    it('keeps slot B of a 0-delay release intact when slot A finishes after B started', function () {
        $uuid = tagUuid();
        $a = new FakeQueueJob($uuid, new TagsSilencedJob, ['a']);
        $b = new FakeQueueJob($uuid, new TagsSilencedJob, ['a']);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $a));
        $a->released = true;
        $recorder->onProcessing(new JobProcessing('torque', $b));
        $recorder->onProcessed(new JobProcessed('torque', $a));

        expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['started', 'started']);

        // B is still known as silenced: its own success cleans up.
        $recorder->onProcessed(new JobProcessed('torque', $b));

        expect(tagStreamEvents($uuid))->toBe([]);
    });

    it('sets the TTL on queued, and swaps it for the safety TTL once a worker starts the job', function () {
        $uuid = tagUuid();
        $recorder = tagRecorder();

        $recorder->onQueued($uuid, 'default', 'App\\Jobs\\Fast');
        tagExpectShortTtl($uuid);

        $recorder->onProcessing(new JobProcessing('torque', new FakeQueueJob($uuid, new TagsPlainJob)));
        tagExpectSafetyTtl($uuid);
    });

    it('keeps the safety TTL when queued lands after started, as a fast worker makes it', function () {
        $uuid = tagUuid();
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', new FakeQueueJob($uuid, new TagsPlainJob)));
        $recorder->onQueued($uuid, 'default', 'App\\Jobs\\Fast');

        tagExpectSafetyTtl($uuid);
    });

    it('expires a queued event that lands after a silenced job was forgotten', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsSilencedJob);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->onProcessed(new JobProcessed('torque', $job));
        $recorder->onQueued($uuid, 'default', 'App\\Jobs\\Fast');

        expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['queued']);
        tagExpectShortTtl($uuid);
    });

    it('expires a stream a late emit recreates after a silenced job was forgotten', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsSilencedJob);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->onProcessed(new JobProcessed('torque', $job));
        $recorder->emitCustom($uuid, 'from an async child');

        expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['progress']);
        tagExpectSafetyTtl($uuid);
    });

    it('expires a stream a late emit recreates after its terminal TTL ran out', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->onProcessed(new JobProcessed('torque', $job));
        torqueRedis()->execute('DEL', TAG_TEST_PREFIX.'job:'.$uuid);
        $recorder->emitCustom($uuid, 'from an async child');

        tagExpectSafetyTtl($uuid);
    });

    it('leaves a job whose last event is an exception with the safety TTL', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->onExceptionOccurred(new JobExceptionOccurred('torque', $job, new RuntimeException('after delete')));

        tagExpectSafetyTtl($uuid);
    });

    it('gives a stream written before this version a TTL on its next event', function () {
        $uuid = tagUuid();
        torqueRedis()->execute('XADD', TAG_TEST_PREFIX.'job:'.$uuid, '*', 'type', 'started');

        tagRecorder()->emitCustom($uuid, 'half', 0.5);

        tagExpectSafetyTtl($uuid);
    });

    it('does not extend the short TTL of a finished job on a late emit', function () {
        $uuid = tagUuid();
        $job = new FakeQueueJob($uuid, new TagsPlainJob);
        $recorder = tagRecorder();

        $recorder->onProcessing(new JobProcessing('torque', $job));
        $recorder->onProcessed(new JobProcessed('torque', $job));
        $recorder->emitCustom($uuid, 'from an async child');

        expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['started', 'completed', 'progress']);
        tagExpectShortTtl($uuid);
    });

    it('carries the tags on the queued event of a push', function () {
        $recorded = app(JobStreamRecorder::class);
        (void) tagQueue()->push(new TagsExplicitJob(tag_user(7)));
        $uuid = tagPushedPayload()['uuid'];

        // The queue records through the app's recorder, under its own prefix.
        $entries = torqueRedis()->execute('XRANGE', 'torque-test:job:'.$uuid, '-', '+');
        $fields = [];
        for ($i = 0, $c = count($entries[0][1]); $i < $c; $i += 2) {
            $fields[(string) $entries[0][1][$i]] = (string) $entries[0][1][$i + 1];
        }

        expect($recorded)->toBeInstanceOf(JobStreamRecorder::class)
            ->and(json_decode($fields['tags'], true))->toBe(['user:7', 'explicit']);

        torqueRedis()->execute('DEL', 'torque-test:job:'.$uuid);
        torqueRedis()->execute('ZREM', 'torque-test:jobs:recent', $uuid);
        torqueRedis()->execute('ZREM', 'torque-test:jobs:active', $uuid);
    });
});

dataset('silenced jobs', [
    'interface' => [fn () => [new TagsSilencedJob, []]],
    'config class' => [function () {
        config(['torque.silenced' => [TagsPlainJob::class]]);

        return [new TagsPlainJob, []];
    }],
    'config tag' => [function () {
        config(['torque.silenced_tags' => ['explicit']]);

        return [new TagsPlainJob, ['explicit']];
    }],
]);

it('omits a silenced job from completed records', function (Closure $make) {
    $uuid = tagUuid();
    [$command, $tags] = $make();
    $job = new FakeQueueJob($uuid, $command, $tags);
    $recorder = tagRecorder();

    $recorder->onProcessing(new JobProcessing('torque', $job));
    $recorder->onProcessed(new JobProcessed('torque', $job));

    expect(tagStreamEvents($uuid))->toBe([])
        ->and(torqueRedis()->execute('ZSCORE', TAG_TEST_PREFIX.'jobs:recent', $uuid))->toBeNull()
        ->and(torqueRedis()->execute('ZSCORE', TAG_TEST_PREFIX.'jobs:active', $uuid))->toBeNull();
})->with('silenced jobs');

it('always records a silenced job that fails', function (Closure $make) {
    $uuid = tagUuid();
    [$command, $tags] = $make();
    $job = new FakeQueueJob($uuid, $command, $tags);
    $recorder = tagRecorder();

    $recorder->onProcessing(new JobProcessing('torque', $job));
    $job->failed = true;
    $recorder->onFailed(new JobFailed('torque', $job, new RuntimeException('nope')));
    $recorder->onProcessed(new JobProcessed('torque', $job));

    expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['started', 'failed']);
})->with('silenced jobs');

it('keeps a silenced job that released itself visible', function (Closure $make) {
    $uuid = tagUuid();
    [$command, $tags] = $make();
    $job = new FakeQueueJob($uuid, $command, $tags);
    $recorder = tagRecorder();

    $recorder->onProcessing(new JobProcessing('torque', $job));
    $job->released = true;
    $recorder->onProcessed(new JobProcessed('torque', $job));

    expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['started']);
})->with('silenced jobs');

it('still records a normal job as completed', function () {
    $uuid = tagUuid();
    $job = new FakeQueueJob($uuid, new TagsPlainJob);
    $recorder = tagRecorder();

    $recorder->onProcessing(new JobProcessing('torque', $job));
    $recorder->onProcessed(new JobProcessed('torque', $job));

    expect(array_column(tagStreamEvents($uuid), 'type'))->toBe(['started', 'completed']);
});
