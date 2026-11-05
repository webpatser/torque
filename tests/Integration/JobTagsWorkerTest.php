<?php

declare(strict_types=1);

use Webpatser\Torque\Dashboard\Data\JobsData;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Stream\JobStream;
use Webpatser\Torque\Tests\Fixtures\TagsDto;
use Webpatser\Torque\Tests\Fixtures\TagsDtoJob;
use Webpatser\Torque\Tests\Fixtures\TagsFailingJob;
use Webpatser\Torque\Tests\Fixtures\TagsPlainJob;
use Webpatser\Torque\Tests\Fixtures\TagsSelfFailingJob;
use Webpatser\Torque\Tests\Fixtures\TagsSilencedReleasingJob;
use Webpatser\Torque\Tests\Fixtures\TagsTenantJob;
use Webpatser\Torque\Tests\Fixtures\TagsThrowingJob;

use function Webpatser\Torque\Tests\Fixtures\tag_user;

require_once dirname(__DIR__).'/Fixtures/TagFixtures.php';

/*
 * Real workers, so the listeners see the true event order and the same job
 * objects as in production. Requires a running Redis instance.
 */
beforeEach(function () {
    $token = bin2hex(random_bytes(4));
    $this->prefix = "torque-tagsw-{$token}:";
    $this->marker = sys_get_temp_dir()."/torque-tagsw-{$token}.marker";
    $this->output = sys_get_temp_dir()."/torque-tagsw-{$token}.out";

    $this->runWorker = function (object ...$jobs): void {
        $queue = new StreamQueue(redisUri: torqueRedisUri(), default: 'tagsw', retryAfter: 90, blockFor: 0, prefix: $this->prefix, consumerGroup: 'tags-test');
        $queue->setContainer(app());
        $queue->setConnectionName('torque');
        $queue->ensureConsumerGroup($queue->getStreamKey(), 'tags-test');

        foreach ($jobs as $job) {
            (void) $queue->push($job, '', 'tagsw');
        }

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/tags-worker.php', torqueRedisUri(), $this->prefix, 'tags-test', 'tagsw', (string) count($jobs)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->output, 'a'], 2 => ['file', $this->output, 'a']],
            $pipes,
        );

        $deadline = microtime(true) + 45;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $timedOut = proc_get_status($process)['running'];
        if ($timedOut) {
            proc_terminate($process, SIGKILL);
        }
        proc_close($process);

        expect($timedOut)->toBeFalse(@file_get_contents($this->output) ?: 'worker timed out');
    };

    $this->typesOf = function (string $uuid): array {
        $types = [];

        foreach (torqueRedis()->execute('XRANGE', $this->prefix.'job:'.$uuid, '-', '+') ?: [] as $entry) {
            for ($i = 0, $c = count($entry[1]); $i < $c; $i += 2) {
                if ((string) $entry[1][$i] === 'type') {
                    $types[] = (string) $entry[1][$i + 1];
                }
            }
        }

        return $types;
    };

    $this->jobs = fn (): JobsData => new JobsData(new JobStream(torqueRedisUri(), $this->prefix));
});

afterEach(function () {
    @unlink($this->marker);
    @unlink($this->output);

    $redis = torqueRedis();
    $cursor = '0';

    do {
        [$cursor, $keys] = $redis->execute('SCAN', $cursor, 'MATCH', $this->prefix.'*', 'COUNT', '100');
        $cursor = (string) $cursor;

        foreach ($keys ?? [] as $key) {
            $redis->execute('DEL', (string) $key);
        }
    } while ($cursor !== '0');
});

it('runs and completes a job whose tags() throws', function () {
    ($this->runWorker)(new TagsThrowingJob($this->marker), new TagsPlainJob($this->marker));

    expect(substr_count((string) @file_get_contents($this->marker), 'ran'))->toBe(2);

    $uuids = torqueRedis()->execute('ZRANGE', $this->prefix.'jobs:recent', '0', '-1');
    expect($uuids)->toHaveCount(2);

    foreach ($uuids as $uuid) {
        expect(($this->typesOf)((string) $uuid))->toBe(['started', 'completed']);
    }
});

it('finds a permanently failing job by tag, with status failed', function () {
    ($this->runWorker)(new TagsFailingJob);

    $found = ($this->jobs)()->list('failed', null, 'fail:me');

    expect($found)->toHaveCount(1)
        ->and($found[0]['status'])->toBe('failed')
        ->and($found[0]['tags'])->toBe(['fail:me'])
        ->and(($this->typesOf)($found[0]['id']))->toBe(['started', 'failed'])
        ->and(torqueRedis()->execute('ZCARD', $this->prefix.'jobs:active'))->toBe(0);
});

it('records a tag read from a real attribute, through to the worker', function () {
    ($this->runWorker)(new TagsTenantJob(tag_user(1, ['tenant_id' => 7]), $this->marker));

    $found = ($this->jobs)()->list('all', null, 'tenant:7');

    expect((string) @file_get_contents($this->marker))->toContain('ran')
        ->and($found)->toHaveCount(1)
        ->and($found[0]['status'])->toBe('completed');
});

it('does not fire a DTO\'s __wakeup or __destruct in the worker because of tags', function () {
    ($this->runWorker)(new TagsDtoJob(new TagsDto($this->marker)));

    $lines = file($this->marker, FILE_IGNORE_NEW_LINES);

    // One wakeup, the framework's own, and nothing destroyed before handle().
    expect(array_slice($lines, 0, (int) array_search('handle', $lines, true)))->toBe(['wakeup']);
});

it('keeps a silenced job that released itself visible while it is delayed', function () {
    ($this->runWorker)(new TagsSilencedReleasingJob);

    $found = ($this->jobs)()->list();

    expect($found)->toHaveCount(1)
        ->and(($this->typesOf)($found[0]['id']))->toBe(['started']);
});

it('does not record completed after a fail() without a throw', function () {
    ($this->runWorker)(new TagsSelfFailingJob);

    $found = ($this->jobs)()->list();

    expect($found)->toHaveCount(1)
        ->and($found[0]['status'])->toBe('failed')
        ->and(($this->typesOf)($found[0]['id']))->toBe(['started', 'failed']);
});
