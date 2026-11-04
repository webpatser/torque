<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Revolt\EventLoop;
use RuntimeException;

use function Fledge\Async\delay;
use function Fledge\Async\Redis\createRedisClient;

/**
 * Queued job for the real-worker tests in tests/Integration/JobExceptionTest.php:
 * each mode settles itself in a different way before throwing, or disturbs the
 * worker around it.
 */
final class JobExceptionProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $mode, public string $label = '') {}

    public function displayName(): string
    {
        return 'exception:'.$this->mode.$this->label;
    }

    public function handle(): void
    {
        file_put_contents(config('job_exception_probe.log'), json_encode([
            'type' => 'handled',
            'job' => $this->displayName(),
            'attempt' => $this->attempts(),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        match ($this->mode) {
            'release-then-throw' => $this->releaseThenThrowOnFirstAttempt(),
            'release-then-throw-then-throw' => $this->releaseThenThrowOnFirstAttempt() ?? throw new RuntimeException('second attempt threw'),
            'fail-then-throw' => $this->failThenThrow(),
            'delete-then-throw' => $this->deleteThenThrow(),
            'mark-failed-then-throw' => $this->markFailedThenThrow(),
            'throw-once' => $this->attempts() === 1 ? throw new RuntimeException('threw once') : null,
            'throw', 'failed-callback-throws' => throw new RuntimeException('threw'),
            'context' => $this->addContextThenThrow(),
            'linger' => delay(0.6),
            'sabotage-group' => $this->destroyConsumerGroupForAWhile(),
            default => null,
        };
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->mode === 'failed-callback-throws') {
            throw new RuntimeException('failed() callback threw');
        }
    }

    private function releaseThenThrowOnFirstAttempt(): null
    {
        if ($this->attempts() === 1) {
            $this->release();

            throw new RuntimeException('released, then threw');
        }

        return null;
    }

    private function failThenThrow(): never
    {
        $this->fail(new RuntimeException('failed itself'));

        throw new RuntimeException('failed, then threw');
    }

    private function deleteThenThrow(): never
    {
        $this->delete();

        throw new RuntimeException('deleted, then threw');
    }

    private function markFailedThenThrow(): never
    {
        $this->job->markAsFailed();

        throw new RuntimeException('marked failed, then threw');
    }

    /**
     * Stays suspended long enough for the other slot's job to add its own value.
     */
    private function addContextThenThrow(): never
    {
        Context::add('tenant', $this->label);

        delay(0.4);

        throw new RuntimeException("{$this->label} threw");
    }

    /**
     * Makes the reader's XREADGROUP fail with NOGROUP until the group is back.
     */
    private function destroyConsumerGroupForAWhile(): void
    {
        $redis = createRedisClient(config('job_exception_probe.uri'));
        $stream = config('job_exception_probe.prefix').$this->job->getQueue();
        $group = config('job_exception_probe.group');

        $redis->execute('XGROUP', 'DESTROY', $stream, $group);

        EventLoop::delay(1.2, static function () use ($redis, $stream, $group): void {
            $redis->execute('XGROUP', 'CREATE', $stream, $group, '0');
        });
    }
}
