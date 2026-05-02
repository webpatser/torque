<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Webpatser\Torque\Console\Bench\BenchRunner;

/**
 * Drive a closed-loop benchmark against a running Torque master.
 *
 * Usage:
 *   php artisan torque:start --workers=4 --concurrency=50   # in another shell
 *   php artisan torque:bench --jobs=10000 --use-running-master
 *   php artisan torque:bench --workload=payload-large --serializer=igbinary --use-running-master
 *
 * v1 limitation: requires a separately-started master (--use-running-master).
 * The current {@see \Webpatser\Torque\Process\MasterProcess} spawns workers via
 * `pcntl_exec` of `torque:worker`, which re-reads `config('torque')` from disk
 * and therefore cannot pick up an in-memory config override (different stream
 * prefix or consumer group). Self-spawning with config isolation is tracked as
 * v1.1 follow-up.
 *
 * TODO(bench): per-stage breakdown via BenchProbe (see plan section A5).
 */
final class TorqueBenchCommand extends Command
{
    private const VALID_WORKLOADS = ['cpu', 'io', 'async-io', 'fanout', 'mixed', 'payload-small', 'payload-large'];

    private const VALID_SERIALIZERS = ['json', 'igbinary'];

    /** @var string */
    protected $signature = 'torque:bench
        {--jobs=10000 : Total jobs to enqueue (includes warmup)}
        {--workers=4 : Worker count to record in run metadata}
        {--coroutines=50 : Coroutines-per-worker recorded in run metadata}
        {--workload=mixed : cpu|io|async-io|fanout|mixed|payload-small|payload-large}
        {--serializer=json : json|igbinary}
        {--warmup=500 : Number of leading samples to discard from aggregation}
        {--use-running-master : Required in v1; assumes torque:start is already running}
        {--json= : Write JSON results to a path, or `-` for stdout}
        {--force : Allow running in production}';

    /** @var string */
    protected $description = 'Benchmark Torque end-to-end throughput and latency';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force to bench in production.');

            return self::FAILURE;
        }

        $workload = (string) $this->option('workload');
        if (! in_array($workload, self::VALID_WORKLOADS, true)) {
            $this->components->error(
                "Invalid --workload={$workload}. Allowed: " . implode(', ', self::VALID_WORKLOADS),
            );

            return self::FAILURE;
        }

        $serializer = (string) $this->option('serializer');
        if (! in_array($serializer, self::VALID_SERIALIZERS, true)) {
            $this->components->error(
                "Invalid --serializer={$serializer}. Allowed: " . implode(', ', self::VALID_SERIALIZERS),
            );

            return self::FAILURE;
        }

        if ($serializer === 'igbinary' && ! extension_loaded('igbinary')) {
            $this->components->error('ext-igbinary is not loaded. Run: pecl install igbinary');

            return self::FAILURE;
        }

        if (! $this->option('use-running-master')) {
            $this->components->error(
                'v1 of torque:bench requires --use-running-master. Start `php artisan torque:start` '
                . 'in another terminal before running the bench. Self-spawning workers with config '
                . 'isolation is a v1.1 follow-up.',
            );

            return self::FAILURE;
        }

        $jobs = (int) $this->option('jobs');
        $workers = (int) $this->option('workers');
        $coroutines = (int) $this->option('coroutines');
        $warmup = (int) $this->option('warmup');

        if ($jobs <= 0) {
            $this->components->error('--jobs must be a positive integer.');

            return self::FAILURE;
        }

        if ($warmup >= $jobs) {
            $this->components->error('--warmup must be less than --jobs.');

            return self::FAILURE;
        }

        $runId = substr(Str::uuid()->toString(), 0, 8);
        $jsonOption = $this->option('json');
        $jsonOnStdout = $jsonOption === '-';

        if (! $jsonOnStdout) {
            $this->components->info(
                "Torque Bench  run={$runId}  jobs={$jobs}  workers={$workers}  "
                . "coroutines={$coroutines}  workload={$workload}  serializer={$serializer}",
            );
            $this->newLine();
        }

        $runner = new BenchRunner(
            jobs: $jobs,
            workers: $workers,
            coroutines: $coroutines,
            workload: $workload,
            serializer: $serializer,
            warmup: $warmup,
            useRunningMaster: true,
            runId: $runId,
        );

        try {
            $report = $runner->run();
        } catch (\Throwable $e) {
            $this->components->error('Bench failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $envelope = $this->buildEnvelope($report, $runId);

        if ($jsonOption !== null) {
            $this->writeJson($envelope, (string) $jsonOption);
        }

        if (! $jsonOnStdout) {
            $this->renderHumanReport($envelope);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function buildEnvelope(array $report, string $runId): array
    {
        return [
            'torque_version' => \Composer\InstalledVersions::getPrettyVersion('webpatser/torque') ?? 'dev',
            'php_version' => PHP_VERSION,
            'extensions' => [
                'igbinary' => extension_loaded('igbinary'),
            ],
            'date' => gmdate('Y-m-d\TH:i:s\Z'),
            'run_id' => $runId,
            'config' => $report['config'] ?? [],
            'results' => $report['results'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function writeJson(array $envelope, string $target): void
    {
        $json = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($target === '-') {
            $this->line($json);

            return;
        }

        file_put_contents($target, $json . "\n");
        $this->components->info("Wrote JSON report to {$target}");
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function renderHumanReport(array $envelope): void
    {
        /** @var array<string, mixed> $results */
        $results = $envelope['results'] ?? [];

        $count = (int) ($results['count'] ?? 0);
        $wall = $results['wall_seconds'] ?? null;
        $throughput = (float) ($results['throughput_per_sec'] ?? 0.0);

        /** @var array<string, mixed> $latency */
        $latency = $results['latency_ms'] ?? [];

        $this->components->twoColumnDetail('Samples (post-warmup)', number_format($count));
        $this->components->twoColumnDetail(
            'Throughput',
            number_format($throughput, 1) . ' jobs/sec',
        );
        $this->components->twoColumnDetail(
            'Wall time',
            $wall !== null ? number_format((float) $wall, 3) . ' s' : '<fg=gray>--</>',
        );
        $this->components->twoColumnDetail(
            'Memory peak (bench process)',
            $this->humanBytes(memory_get_peak_usage(true)),
        );

        $this->newLine();
        $this->components->info('Latency (end-to-end)');
        $this->components->twoColumnDetail('p50', $this->ms($latency['p50'] ?? null));
        $this->components->twoColumnDetail('p95', $this->ms($latency['p95'] ?? null));
        $this->components->twoColumnDetail('p99', $this->ms($latency['p99'] ?? null));
        $this->components->twoColumnDetail('mean', $this->ms($latency['mean'] ?? null));
        $this->components->twoColumnDetail('stddev', $this->ms($latency['stddev'] ?? null));

        $this->newLine();
        $this->components->info('Stage breakdown (median ns)');
        $this->components->twoColumnDetail('serialize', '<fg=gray>v1.1 follow-up</>');
        $this->components->twoColumnDetail('xadd', '<fg=gray>v1.1 follow-up</>');
        $this->components->twoColumnDetail('xreadgroup', '<fg=gray>v1.1 follow-up</>');
        $this->components->twoColumnDetail('unserialize', '<fg=gray>v1.1 follow-up</>');
        $this->components->twoColumnDetail(
            'handle (median)',
            isset($results['handle_ns_median']) && $results['handle_ns_median'] !== null
                ? number_format((int) $results['handle_ns_median']) . ' ns'
                : '<fg=gray>--</>',
        );
        $this->components->twoColumnDetail('xack', '<fg=gray>v1.1 follow-up</>');
        $this->newLine();
    }

    private function ms(mixed $value): string
    {
        if ($value === null) {
            return '<fg=gray>--</>';
        }

        return number_format((float) $value, 2) . ' ms';
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
