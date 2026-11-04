<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Context;

use function Fledge\Async\delay;

/**
 * Queued job for the attempt isolation tests: adds its tenant to Context, stays
 * in flight until the worker was signalled, and dispatches a checkpoint job from
 * interrupted(). Sends the signal itself so the timing is the same on every run.
 */
final class InterruptProbeJob implements Interruptible, ShouldQueue
{
    use Queueable;

    /**
     * Tenants that added their Context so far in this worker process.
     *
     * @var array<string, true>
     */
    private static array $started = [];

    private bool $interrupted = false;

    /**
     * @param  list<string>  $waitFor  Tenants whose jobs must be running before this job goes on.
     * @param  bool  $sendsSignal  Whether this job sends the worker its SIGTERM.
     */
    public function __construct(
        public string $tenant,
        public array $waitFor = [],
        public bool $sendsSignal = false,
    ) {}

    public function displayName(): string
    {
        return 'interrupt:'.$this->tenant;
    }

    public function handle(): void
    {
        Context::add('tenant', $this->tenant);
        self::$started[$this->tenant] = true;

        $deadline = microtime(true) + 5;

        while (array_diff_key(array_flip($this->waitFor), self::$started) !== [] && microtime(true) < $deadline) {
            delay(0.01);
        }

        if ($this->sendsSignal) {
            posix_kill((int) getmypid(), SIGTERM);
        }

        while (! $this->interrupted && microtime(true) < $deadline) {
            delay(0.01);
        }
    }

    public function interrupted(int $signal): void
    {
        file_put_contents(config('attempt_isolation.log'), json_encode([
            'type' => 'interrupted',
            'job' => $this->tenant,
            'tenant' => Context::get('tenant'),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        dispatch(new IsolationChildJob($this->tenant))->onConnection('torque')->onQueue('isolation-children');

        $this->interrupted = true;
    }
}
