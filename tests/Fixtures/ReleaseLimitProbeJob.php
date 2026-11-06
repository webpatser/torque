<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;

/**
 * Queued job for tests/Integration/ReleaseLimitTest.php. It sits behind one of the
 * real Laravel middleware, which releases it the way an application job is released.
 * tests/Fixtures/release-limit-worker.php sets up what that middleware reacts to:
 * an exhausted rate limit or a held lock. A throttled job always throws.
 *
 * The first middleware only observes: it logs every delivery before the next one
 * can release the job without running it.
 */
class ReleaseLimitProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public $tries;

    public $retryUntil;

    /**
     * @param  'rate_limit'|'overlap'|'throttle'  $mode
     */
    public function __construct(public string $mode, ?int $tries = null, ?int $retryUntil = null)
    {
        $this->tries = $tries;
        $this->retryUntil = $retryUntil;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $observer = new class
        {
            public function handle(object $job, callable $next): mixed
            {
                file_put_contents(config('job_limits_probe.log'), json_encode([
                    'type' => 'delivered',
                    'attempt' => $job->attempts(),
                    't' => microtime(true),
                ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

                return $next($job);
            }
        };

        return [$observer, match ($this->mode) {
            'rate_limit' => (new RateLimited('release-limit-probe'))->releaseAfter(1),
            'overlap' => (new WithoutOverlapping('probe'))->withPrefix('rl:')->shared()->releaseAfter(1),
            'throttle' => new ThrottlesExceptions(3, 60),
        }];
    }

    public function handle(): void
    {
        file_put_contents(config('job_limits_probe.log'), json_encode([
            'type' => 'handled',
            'attempt' => $this->attempts(),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        if ($this->mode === 'throttle') {
            throw new RuntimeException('probe threw');
        }
    }
}
