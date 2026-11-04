<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Illuminate\Support\defer;

/**
 * Queued job for the real-worker defer() tests. Jobs in one worker process
 * rendezvous through named marks instead of sleeping, so an interleaving is the
 * same on every run. See tests/Integration/JobAttemptedTest.php.
 */
final class DeferProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Marks reached so far in this worker process.
     *
     * @var array<string, true>
     */
    public static array $marks = [];

    /**
     * @param  list<string>  $waitBeforeDefer  Marks to reach before calling defer().
     * @param  list<string>  $waitBeforeEnd  Marks to reach before the handler ends.
     * @param  list<string>  $callbackWaitsFor  Marks the deferred callback waits for, suspending it.
     */
    public function __construct(
        public string $name,
        public array $waitBeforeDefer = [],
        public array $waitBeforeEnd = [],
        public array $callbackWaitsFor = [],
        public bool $throws = false,
        public bool $always = false,
        public bool $fromChildFiber = false,
        public bool $hangs = false,
        public bool $exits = false,
    ) {}

    public function displayName(): string
    {
        return 'defer:'.$this->name;
    }

    public function handle(): void
    {
        $this->log('handle-start');

        self::waitFor($this->waitBeforeDefer);

        if ($this->fromChildFiber) {
            async(fn () => async(fn () => $this->register())->await())->await();
        } else {
            $this->register();
        }

        self::mark('registered:'.$this->name);

        self::waitFor($this->waitBeforeEnd);

        if ($this->hangs) {
            delay(60);
        }

        if ($this->exits) {
            exit(0);
        }

        $this->log('handle-end');

        if ($this->throws) {
            throw new RuntimeException('defer probe threw');
        }
    }

    public static function mark(string $mark): void
    {
        self::$marks[$mark] = true;
    }

    private function register(): void
    {
        $deferred = defer(function (): void {
            $this->log('deferred');
            self::mark('callback-started:'.$this->name);
            self::waitFor($this->callbackWaitsFor);
        });

        if ($this->always) {
            $deferred->always();
        }
    }

    /**
     * @param  list<string>  $marks
     */
    private static function waitFor(array $marks): void
    {
        $deadline = microtime(true) + 5;

        foreach ($marks as $mark) {
            while (! isset(self::$marks[$mark])) {
                if (microtime(true) >= $deadline) {
                    self::write(['type' => 'timeout', 'mark' => $mark]);

                    return;
                }

                delay(0.01);
            }
        }
    }

    private function log(string $type): void
    {
        self::write(['type' => $type, 'job' => $this->displayName()]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function write(array $row): void
    {
        file_put_contents(config('attempt_probe.log'), json_encode($row, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }
}
