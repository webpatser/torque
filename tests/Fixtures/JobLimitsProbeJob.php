<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\TimeoutExceededException;
use RuntimeException;

/**
 * Queued job for the real-worker tests in tests/Integration/JobLimitsTest.php.
 * Declares its limits as properties, the way an application job would; a null
 * leaves the limit undeclared. Fails or releases itself until attempt $until.
 */
use function Fledge\Async\delay;

class JobLimitsProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public $tries;

    public $backoff;

    public $maxExceptions;

    public $retryUntil;

    /**
     * @param  'throw'|'release'|'timeout'|'hang'  $mode  Timeout throws the exception; hang runs into a real one.
     * @param  int  $until  First attempt that completes; 0 never does.
     * @param  int|list<int>|null  $backoff
     * @param  int  $throwAt  In release mode, first attempt that throws instead; 0 never does.
     */
    public function __construct(
        public string $mode = 'throw',
        public int $until = 0,
        ?int $tries = null,
        int|array|null $backoff = null,
        ?int $maxExceptions = null,
        ?int $retryUntil = null,
        public int $throwAt = 0,
    ) {
        $this->tries = $tries;
        $this->backoff = $backoff;
        $this->maxExceptions = $maxExceptions;
        $this->retryUntil = $retryUntil;
    }

    public function handle(): void
    {
        file_put_contents(config('job_limits_probe.log'), json_encode([
            'type' => 'handled',
            'attempt' => $this->attempts(),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        if ($this->until > 0 && $this->attempts() >= $this->until) {
            return;
        }

        if ($this->mode === 'hang') {
            delay(30);
        }

        if ($this->mode === 'timeout') {
            throw TimeoutExceededException::forJob($this->job);
        }

        if ($this->mode === 'release' && ($this->throwAt === 0 || $this->attempts() < $this->throwAt)) {
            $this->release();

            return;
        }

        throw new RuntimeException('probe threw');
    }
}
