<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Context;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * Queued job for the attempt isolation tests. Jobs in one worker process
 * rendezvous through named marks instead of sleeping, so the interleaving is the
 * same on every run. See tests/Integration/AttemptIsolationTest.php.
 */
final class IsolationProbeJob implements ShouldQueue
{
    use Queueable;

    /**
     * Marks reached so far in this worker process.
     *
     * @var array<string, true>
     */
    public static array $marks = [];

    /**
     * @param  string  $tenant  This job's own tenant, added to Context.
     * @param  list<string>  $waitFor  Tenants whose jobs must have added theirs before this job goes on.
     * @param  bool  $probesAsyncChild  Read and write Context from an async() child after the rendezvous.
     * @param  string  $contextKey  The Context key this job's tenant goes under.
     */
    public function __construct(
        public string $tenant,
        public array $waitFor = [],
        public bool $dispatchesChild = false,
        public bool $probesAsyncChild = false,
        public string $contextKey = 'tenant',
    ) {}

    public function displayName(): string
    {
        return 'probe:'.$this->tenant;
    }

    public function handle(): void
    {
        $this->log('handle-start', ['data' => Context::all(), 'hidden' => Context::allHidden()]);

        Context::add($this->contextKey, $this->tenant);
        self::$marks['added:'.$this->tenant] = true;
        $this->log('context-added');

        $this->waitForOthers();

        $this->log('resumed', ['tenant' => Context::get('tenant'), 'data' => Context::all()]);

        if ($this->probesAsyncChild) {
            $seen = async(function (): mixed {
                delay(0.01);
                $seen = Context::get('tenant');
                Context::add('added-by-child', $this->tenant);

                return $seen;
            })->await();

            $this->log('async-child', ['seen' => $seen, 'parent_sees' => Context::get('added-by-child')]);
        }

        if ($this->dispatchesChild) {
            dispatch(new IsolationChildJob($this->tenant))->onConnection('torque')->onQueue('isolation-children');
        }

        $this->log('handle-end');
    }

    private function waitForOthers(): void
    {
        $deadline = microtime(true) + 5;

        foreach ($this->waitFor as $tenant) {
            while (! isset(self::$marks['added:'.$tenant])) {
                if (microtime(true) >= $deadline) {
                    $this->log('rendezvous-timeout', ['waiting_for' => $tenant]);

                    return;
                }

                delay(0.01);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $type, array $extra = []): void
    {
        file_put_contents(
            config('attempt_isolation.log'),
            json_encode(['type' => $type, 'job' => $this->tenant, ...$extra], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND,
        );
    }
}
