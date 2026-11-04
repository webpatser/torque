<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Closure;
use Fiber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

use function Fledge\Async\delay;

/**
 * Queued job for the quiescent reset tests: logs what a reset would have changed
 * (a scoped binding, Cache::memo, Context outside any attempt). Jobs in one worker
 * process rendezvous through named marks. See
 * tests/Integration/AttemptIsolationTest.php.
 */
final class ResetProbeJob implements ShouldQueue
{
    use Queueable;

    /**
     * Jobs started so far in this worker process.
     *
     * @var array<string, true>
     */
    public static array $marks = [];

    /**
     * @param  list<string>  $waitFor  Jobs that must have started before this one goes on.
     * @param  list<self>  $dispatchesDelayed  Jobs arriving once this worker has gone idle.
     */
    public function __construct(
        public string $name,
        public array $waitFor = [],
        public array $dispatchesDelayed = [],
        public bool $dispatchesChild = false,
    ) {}

    public function displayName(): string
    {
        return 'reset-probe:'.$this->name;
    }

    public function handle(): void
    {
        $scoped = app(ScopedProbe::class)->number;
        $memo = Cache::memo()->get('reset-probe');
        Cache::put('reset-probe', $this->name);

        // A raw Fiber has no attempt scope, so this is the ambient scope.
        $ambient = self::inRawFiber(static fn (): array => Context::all());
        self::inRawFiber(fn () => Context::add('ambient:'.$this->name, true));

        Context::add('tenant', $this->name);
        self::$marks[$this->name] = true;

        $this->log('start', ['scoped' => $scoped, 'memo' => $memo, 'ambient' => $ambient]);

        $this->waitForOthers();

        $this->log('end', [
            'scoped' => app(ScopedProbe::class)->number,
            'tenant' => Context::get('tenant'),
            'repository' => get_class(Context::getFacadeRoot()),
        ]);

        if ($this->dispatchesChild) {
            dispatch(new IsolationChildJob($this->name))->onConnection('torque')->onQueue('isolation-children');
        }

        // Two seconds, since a delayed job's score has whole seconds: this
        // job's own acknowledgement must be long done when these arrive.
        foreach ($this->dispatchesDelayed as $job) {
            dispatch($job)->onConnection('torque')->onQueue('isolation')->delay(2);
        }
    }

    private static function inRawFiber(Closure $callback): mixed
    {
        $fiber = new Fiber($callback);
        $fiber->start();

        return $fiber->getReturn();
    }

    private function waitForOthers(): void
    {
        $deadline = microtime(true) + 10;

        foreach ($this->waitFor as $name) {
            while (! isset(self::$marks[$name])) {
                if (microtime(true) >= $deadline) {
                    $this->log('rendezvous-timeout', ['waiting_for' => $name]);

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
            json_encode(['type' => $type, 'job' => $this->name, ...$extra], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND,
        );
    }
}
