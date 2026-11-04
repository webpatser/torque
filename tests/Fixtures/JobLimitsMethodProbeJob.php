<?php

declare(strict_types=1);

namespace Webpatser\Torque\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Same probe as {@see JobLimitsProbeJob}, declaring its limits through the
 * tries(), backoff() and retryUntil() methods. Always throws.
 */
class JobLimitsMethodProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  list<int>  $backoffSteps
     */
    public function __construct(
        public int $triesValue,
        public array $backoffSteps,
        public ?int $deadline = null,
    ) {}

    public function tries(): int
    {
        return $this->triesValue;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->backoffSteps;
    }

    public function retryUntil(): ?int
    {
        return $this->deadline;
    }

    public function handle(): void
    {
        file_put_contents(config('job_limits_probe.log'), json_encode([
            'type' => 'handled',
            'attempt' => $this->attempts(),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        throw new RuntimeException('probe threw');
    }
}
