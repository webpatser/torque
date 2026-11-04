<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Illuminate\Support\defer;

/**
 * Queued job for the job timeout tests: logs its run, adds its label to Context,
 * waits on attempt 1 only, then ends the way $after asks for. The label is the
 * display name, so the worker's listeners can tell jobs apart. Batchable, as that
 * alone makes the stock Job::fail() roll back the batch connection on a timeout.
 * See tests/Integration/JobTimeoutsTest.php.
 */
final class TimeoutProbeJob implements Interruptible, ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  'none'|'throw'|'release'|'fail'|'delete'  $after  What the job does once its wait is over.
     * @param  bool  $releasesFirst  Whether the job releases itself before it waits.
     * @param  bool  $interruptHangs  Whether interrupted() itself never comes back.
     * @param  float  $interruptSeconds  How long interrupted() waits before it returns.
     * @param  'none'|'delete'  $interruptAfter  What interrupted() does last.
     * @param  bool  $writes  Whether the wait sits inside a transaction, between two writes.
     * @param  float|null  $childActsAfter  Starts an async() child that deletes and releases after this wait.
     */
    public function __construct(
        public string $label,
        public float $seconds = 0.0,
        public string $after = 'none',
        public ?int $timeout = null,
        public bool $failOnTimeout = false,
        public ?int $tries = null,
        public ?int $maxExceptions = null,
        public ?int $retryUntil = null,
        public ?int $backoff = null,
        public bool $releasesFirst = false,
        public bool $interruptHangs = false,
        public float $interruptSeconds = 0.0,
        public string $interruptAfter = 'none',
        public bool $writes = false,
        public ?float $childActsAfter = null,
    ) {}

    public function displayName(): string
    {
        return $this->label;
    }

    public function handle(): void
    {
        Context::add('label', $this->label);

        $this->log('handle', ['attempt' => $this->attempts()]);

        defer(fn () => $this->log('deferred'));
        defer(fn () => $this->log('deferred-always'), always: true);

        if ($this->releasesFirst) {
            $this->release(60);
        }

        if ($this->childActsAfter !== null) {
            (void) async(function (): void {
                delay($this->childActsAfter);

                $this->log('child-acted');
                $this->delete();
                $this->release(0);
            });
        }

        if ($this->writes) {
            DB::transaction(function (): void {
                DB::table('probe_writes')->insert(['label' => "{$this->label}-first"]);

                delay($this->seconds);

                DB::table('probe_writes')->insert(['label' => "{$this->label}-second"]);
            });

            return;
        }

        if ($this->attempts() === 1 && $this->seconds > 0) {
            delay($this->seconds);

            $this->log('resumed');
        }

        match ($this->after) {
            'throw' => throw new RuntimeException('thrown after the wait'),
            'release' => $this->release(0),
            'fail' => $this->fail(new RuntimeException('failed after the wait')),
            'delete' => $this->delete(),
            default => null,
        };
    }

    public function interrupted(int $signal): void
    {
        $this->log('interrupted', ['signal' => $signal, 'context' => Context::get('label')]);

        if ($this->interruptHangs) {
            delay(30);
        }

        if ($this->interruptSeconds > 0) {
            delay($this->interruptSeconds);

            $this->log('interrupted-returned');
        }

        if ($this->interruptAfter === 'delete') {
            $this->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $event, array $extra = []): void
    {
        file_put_contents(config('timeout_probe.log'), json_encode(['event' => $event, 'tag' => $this->label] + $extra, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }
}
