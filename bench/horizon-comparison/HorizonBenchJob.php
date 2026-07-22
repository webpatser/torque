<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Webpatser\Torque\Console\Bench\BenchJob;

/**
 * Mirror of {@see BenchJob} for plain Laravel /
 * Horizon, used to produce apples-to-apples throughput comparisons.
 *
 * Drop this file in your Laravel app's `app/Jobs/` and dispatch via
 * {@see run-horizon-bench.php} with QUEUE_CONNECTION=redis. See the README in
 * this directory for the full reproduction recipe.
 *
 * Workloads kept identical to BenchJob, except `async-io` and `fanout` collapse
 * to blocking sleeps because Horizon workers have no event loop. That collapse
 * is the point of the comparison.
 */
final class HorizonBenchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $workload,
        public float $enqueuedMicrotime,
        public string $runId,
        public ?string $blob = null,
    ) {}

    public function handle(): void
    {
        $startNs = hrtime(true);

        switch ($this->workload) {
            case 'cpu':
                for ($i = 0; $i < 5000; $i++) {
                    hash('xxh3', random_bytes(64));
                }
                break;
            case 'io':
            case 'async-io':
                // Horizon has no event loop, so any "async" wait collapses to
                // a blocking sleep. This is exactly what a real Laravel job
                // pays for an HTTP/DB call without an async wrapper.
                usleep(2000);
                break;
            case 'fanout':
                usleep(100_000);
                break;
            case 'payload-small':
            case 'payload-large':
                if ($this->blob !== null) {
                    strlen($this->blob);
                }
                break;
        }

        $handleNs = hrtime(true) - $startNs;
        $totalMs = (microtime(true) - $this->enqueuedMicrotime) * 1000.0;

        try {
            Redis::connection()->command('XADD', [
                "torque-bench:{$this->runId}:results",
                'MAXLEN', '~', '200000',
                '*',
                'total_ms', (string) $totalMs,
                'handle_ns', (string) $handleNs,
                'workload', $this->workload,
            ]);
        } catch (\Throwable) {
            // Swallow.
        }
    }
}
