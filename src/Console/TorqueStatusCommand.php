<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Illuminate\Console\Command;
use Webpatser\Torque\Control\LeaderLease;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Job\CircuitBreaker;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Process\DrainPause;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Redis\UpgradeRunner;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeName;
use Webpatser\Torque\Support\ProcessInspector;
use Webpatser\Torque\Support\StreamQueueResolver;
use Webpatser\Torque\Support\WorkerId;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Display the current status of the Torque queue worker.
 *
 * Reads metrics from Redis hashes published by workers and the master process,
 * and displays a summary table with throughput, concurrency, and per-worker stats.
 */
final class TorqueStatusCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:status
        {--node= : Only show the master and workers of this node}';

    /** @var string */
    protected $description = 'Display the status of the Torque queue worker';

    public function handle(): int
    {
        $node = null;
        $nodeOption = $this->option('node');

        if (is_string($nodeOption) && $nodeOption !== '') {
            try {
                $node = NodeName::validate($nodeOption);
            } catch (\InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $config = Narrow::map(config('torque'));
        $redisConfig = Narrow::map($config['redis'] ?? null);

        $redisUri = Narrow::string($redisConfig['uri'] ?? null, 'redis://127.0.0.1:6379');
        $prefix = Narrow::string($redisConfig['prefix'] ?? null, 'torque:');

        // One client for everything: a short-lived CLI process must not open
        // a connection per collaborator, so queue depths and dead-letter
        // counts are read with raw commands on this client instead of
        // resolving StreamQueue/DeadLetterHandler instances.
        $redis = createRedisClient($redisUri);

        $this->renderMasterStatus();
        $this->renderMastersTable($redis, $prefix, $node);
        $this->renderOwedRespawns($redis, $prefix);
        $this->renderPauseStatus($redis, $prefix);
        $this->renderPauseScopes($redis, $prefix);
        $this->renderDataVersion($redis, $prefix);
        $this->renderOverallMetrics($redis, $prefix, $config);
        $this->renderCircuitBreakers($redis, $config);
        $this->renderWorkerTable($redis, $prefix, $node);

        return self::SUCCESS;
    }

    /**
     * List the live masters from the registry: node, PID, state, version,
     * whether a supervisor restarts it, and which one holds the leader lease.
     *
     * Nothing is printed when no master has published a record (older
     * masters, or none running), so the previous output is unchanged.
     */
    private function renderMastersTable(RedisClient $redis, string $prefix, ?string $node): void
    {
        try {
            $records = new MasterRegistry($redis, $prefix)->all();
            $leader = new LeaderLease($redis, $prefix)->leader();
        } catch (\Throwable) {
            return;
        }

        if ($records === []) {
            return;
        }

        if ($node !== null) {
            $records = array_values(array_filter(
                $records,
                static fn (array $record): bool => $record['node'] === $node,
            ));

            if ($records === []) {
                $this->components->warn("No masters registered for node {$node}.");
                $this->newLine();

                return;
            }
        }

        $rows = array_map(static fn (array $record): array => [
            $record['node'],
            (string) $record['pid'],
            $record['state'],
            $record['version'],
            $record['supervised'] ? 'yes' : 'no',
            $leader !== null && $record['master_id'] === $leader ? '*' : '',
        ], $records);

        $this->table(['Node', 'PID', 'State', 'Version', 'Supervised', 'Leader'], $rows);
        $this->newLine();

        $this->renderGroupTables($records);
    }

    /**
     * Print one sub-table per master that runs more than the implicit
     * `default` group. A master with only `default` (or no `groups` field)
     * prints nothing, so single-group output is unchanged.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function renderGroupTables(array $records): void
    {
        foreach ($records as $record) {
            $groups = $record['groups'] ?? [];

            if (! is_array($groups) || $groups === []) {
                continue;
            }

            if (count($groups) === 1 && array_key_exists('default', $groups)) {
                continue;
            }

            $rows = [];

            foreach ($groups as $name => $group) {
                if (! is_array($group)) {
                    continue;
                }

                $rows[] = [
                    (string) $name,
                    implode(', ', array_map(static fn (mixed $queue): string => Narrow::string($queue), Narrow::list($group['queues'] ?? null))),
                    Narrow::string($group['workers'] ?? null, '0'),
                    Narrow::string($group['target'] ?? null, '0'),
                    Narrow::string($group['min'] ?? null, '0'),
                    Narrow::string($group['max'] ?? null, '0'),
                    Narrow::string($group['slots'] ?? null, '0'),
                ];
            }

            if ($rows === []) {
                continue;
            }

            $this->components->twoColumnDetail(
                '<fg=cyan;options=bold>Groups</>',
                Narrow::string($record['node'] ?? null).' <fg=gray>(PID '.Narrow::string($record['pid'] ?? null).')</>',
            );
            $this->table(['Group', 'Queues', 'Workers', 'Target', 'Min', 'Max', 'Slots'], $rows);
            $this->newLine();
        }
    }

    /**
     * Show the node and queue pauses set with `torque:pause --node/--queue`,
     * next to the global pause. Nothing is printed while none are set.
     */
    private function renderPauseScopes(RedisClient $redis, string $prefix): void
    {
        try {
            $scopes = new PauseScopes($redis, $prefix)->all();
        } catch (\Throwable) {
            return;
        }

        if ($scopes['nodes'] === [] && $scopes['queues'] === []) {
            return;
        }

        if ($scopes['nodes'] !== []) {
            $this->components->twoColumnDetail(
                '<fg=yellow;options=bold>Nodes PAUSED</>',
                implode(', ', array_map('strval', array_keys($scopes['nodes']))),
            );
        }

        if ($scopes['queues'] !== []) {
            $this->components->twoColumnDetail(
                '<fg=yellow;options=bold>Queues PAUSED</>',
                implode(', ', array_map('strval', array_keys($scopes['queues']))),
            );
        }

        $this->newLine();
    }

    /**
     * Display whether the master process is running based on the PID file.
     *
     * Uses {@see MasterProcess::readPid()} so the symlink guard, the
     * recycled-PID identity check, and stale-file semantics all match the
     * rest of the toolchain; a recycled PID must not render as RUNNING.
     */
    private function renderMasterStatus(): void
    {
        $pid = MasterProcess::readPid();

        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=cyan;options=bold>Master Status</>',
            $pid !== null
                ? "<fg=green;options=bold>RUNNING</> <fg=gray>(PID {$pid})</>"
                : '<fg=red;options=bold>STOPPED</>',
        );

        $this->newLine();
    }

    /**
     * Show the workers a master owes after failed forks, per host.
     *
     * Nothing is printed while none are owed. A master whose forks keep
     * failing stays up with no workers, and without this line the only sign
     * is its log. Read from the live per-host hashes the masters publish.
     */
    private function renderOwedRespawns(RedisClient $redis, string $prefix): void
    {
        $owed = [];

        try {
            $hosts = $redis->execute('ZRANGE', $prefix.'metrics:live', '0', '-1');

            foreach (is_array($hosts) ? $hosts : [] as $host) {
                $host = Narrow::string($host);
                $count = Narrow::int($redis->execute('HGET', $prefix.'metrics:live:'.$host, MasterProcess::PENDING_RESPAWNS_FIELD));

                if ($count > 0) {
                    $owed[] = "{$count} on {$host}";
                }
            }
        } catch (\Throwable) {
            return;
        }

        if ($owed === []) {
            return;
        }

        $this->components->twoColumnDetail(
            '<fg=red;options=bold>Respawns owed</>',
            implode(', ', $owed).' <fg=gray>(worker forks failing; see the master log)</>',
        );

        $this->newLine();
    }

    /**
     * Show why the queue is paused, when it is.
     *
     * Nothing is printed on a running queue, so normal output is unchanged.
     * A pause has two very different causes and the operator response differs
     * per cause: a deliberate `torque:pause` waits for a human, while a
     * `drain:<pid>` key is a reload artefact that only outlives its master
     * when something killed that master mid-drain. Naming the owning PID (and
     * whether it still runs) is the difference between "someone paused this"
     * and "a dead reload parked the fleet".
     */
    private function renderPauseStatus(RedisClient $redis, string $prefix): void
    {
        try {
            $value = $redis->execute('GET', $prefix.'paused');
            $ttl = Narrow::int($redis->execute('TTL', $prefix.'paused'));
        } catch (\Throwable) {
            return;
        }

        if ($value === null) {
            return;
        }

        $ownerPid = DrainPause::ownerPid(Narrow::string($value));

        if ($ownerPid === null) {
            $reason = 'manual <fg=gray>(torque:pause; run `torque:pause continue` to resume)</>';
        } elseif (ProcessInspector::isTorqueMaster($ownerPid)) {
            // A live owner means a reload is under way, and then the TTL is
            // the interesting number: how long this can still last.
            $reason = "drain from master PID {$ownerPid} <fg=gray>(reload in progress)</>"
                .($ttl > 0 ? " <fg=gray>[{$ttl}s left]</>" : '');
        } else {
            // The remaining TTL is deliberately not shown: nothing will wait
            // it out, the next master start deletes the key.
            $reason = "drain from master PID {$ownerPid} <fg=gray>(gone; cleared at next start)</>";
        }

        $this->components->twoColumnDetail('<fg=yellow;options=bold>Queue PAUSED</>', $reason);

        $this->newLine();
    }

    /**
     * Display the fleet summary: master-published aggregate first (it carries
     * a real throughput), live worker-hash aggregation as fallback, and
     * always-live queue depth / dead-letter counts, mirroring the dashboard's
     * OverviewData.
     *
     * @param  array<string, mixed>  $config
     */
    private function renderOverallMetrics(RedisClient $redis, string $prefix, array $config): void
    {
        $throughput = null;
        $concurrent = null;
        $avgLatency = null;

        try {
            // Every live host's aggregate, summed, as the dashboard reads it.
            $agg = MetricsPublisher::readAggregate($redis, $prefix);

            if ($agg === []) {
                // No master-published aggregate: aggregate the live worker
                // hashes. The publisher is used purely for its aggregation
                // math; it never opens its own connection here.
                $workers = [];

                foreach ($this->scanKeys($redis, $prefix.'worker:*') as $key) {
                    $workers[substr($key, strlen($prefix.'worker:'))] = $this->getHashAll($redis, $key);
                }

                $agg = new MetricsPublisher('redis://unused')->aggregateFromWorkers($workers);
                // A one-shot snapshot cannot derive a rate; only the
                // master-published aggregate carries real throughput.
                unset($agg['throughput']);
            }

            if (Narrow::int($agg['workers'] ?? null) > 0) {
                $throughput = isset($agg['throughput']) ? Narrow::string($agg['throughput']) : null;
                $concurrent = Narrow::string($agg['concurrent'] ?? null, '0');
                $avgLatency = Narrow::string($agg['avg_latency'] ?? null, '0');
            }
        } catch (\Throwable) {
            // Placeholders below.
        }

        $waiting = $delayed = $reserved = null;
        $failed = null;

        try {
            // The dashboard's split, from StreamQueue::depths(): waiting, delayed, reserved.
            $streamQueue = StreamQueueResolver::make();
            $streams = array_keys((array) ($config['streams'] ?? []));
            $waitingCount = $delayedCount = $reservedCount = 0;

            foreach ($streams === [] ? ['default'] : $streams as $name) {
                $depths = $streamQueue->depths((string) $name);

                $waitingCount += $depths['waiting'];
                $delayedCount += $depths['delayed'];
                $reservedCount += $depths['reserved'];
            }

            $waiting = (string) $waitingCount;
            $delayed = (string) $delayedCount;
            $reserved = (string) $reservedCount;
            $failed = (string) max(0, Narrow::int($redis->execute('XLEN', $prefix.'dead-letter')));
        } catch (\Throwable) {
            // Placeholders below.
        }

        $this->components->twoColumnDetail('Throughput (jobs/sec)', $this->formatMetric($throughput, '/s'));
        $this->components->twoColumnDetail('Concurrent Jobs', $this->formatMetric($concurrent));
        $this->components->twoColumnDetail('Avg Latency', $this->formatMetric($avgLatency, ' ms'));
        $this->components->twoColumnDetail('Waiting', $this->formatMetric($waiting));
        $this->components->twoColumnDetail('Delayed', $this->formatMetric($delayed));
        $this->components->twoColumnDetail('Reserved', $this->formatMetric($reserved));
        $this->components->twoColumnDetail('Failed (dead letter)', $this->formatMetric($failed));

        $this->newLine();
    }

    /**
     * Show the recorded data version next to the installed one.
     *
     * They differ only between a deploy and the first master start after it,
     * which is exactly when knowing the upgrade sweep has not run yet is
     * useful.
     */
    private function renderDataVersion(RedisClient $redis, string $prefix): void
    {
        $installed = UpgradeRunner::installedVersion();

        try {
            $stored = $redis->execute('GET', $prefix.UpgradeRunner::VERSION_KEY_SUFFIX);
        } catch (\Throwable) {
            $stored = null;
        }

        $stored = Narrow::nullableString($stored);

        $this->components->twoColumnDetail(
            'Data version',
            $stored === null
                ? "<fg=yellow>not recorded</> <fg=gray>(upgrade runs on the next master start, installed {$installed})</>"
                : ($stored === $installed
                    ? "{$stored}"
                    : "{$stored} <fg=gray>(installed {$installed})</>"),
        );

        $this->newLine();
    }

    /**
     * List every stream whose circuit breaker is not closed.
     *
     * Nothing is printed while all breakers are closed, so the normal status
     * output is unchanged; a tripped stream is the exception worth a line.
     *
     * @param  array<string, mixed>  $config
     */
    private function renderCircuitBreakers(RedisClient $redis, array $config): void
    {
        $breaker = CircuitBreaker::fromConfig($config, client: $redis);
        $printed = false;

        foreach (array_keys((array) ($config['streams'] ?? [])) as $queue) {
            $state = $breaker->state((string) $queue);

            if ($state === null) {
                continue;
            }

            $detail = $state['state'] === 'open'
                ? '<fg=red;options=bold>OPEN</>'.($state['resumes_at'] !== null
                    ? ' <fg=gray>(probes in '.max(0, $state['resumes_at'] - time()).'s)</>'
                    : '')
                : '<fg=yellow;options=bold>HALF-OPEN</> <fg=gray>(probing)</>';

            $this->components->twoColumnDetail("Circuit breaker [{$queue}]", $detail);
            $printed = true;
        }

        if ($printed) {
            $this->newLine();
        }
    }

    /**
     * Discover and display per-worker metrics from `{prefix}worker:*` hashes.
     *
     * Expected hash fields per worker: pid, active_slots, total_slots,
     * jobs_processed, avg_latency, last_heartbeat.
     */
    private function renderWorkerTable(RedisClient $redis, string $prefix, ?string $node): void
    {
        $keyPrefix = $prefix.'worker:';
        $workerKeys = $this->scanKeys($redis, $keyPrefix.'*');

        if ($node !== null) {
            $workerKeys = array_values(array_filter(
                $workerKeys,
                static fn (string $key): bool => WorkerId::parse(
                    str_starts_with($key, $keyPrefix) ? substr($key, strlen($keyPrefix)) : $key,
                )->host === $node,
            ));
        }

        if ($workerKeys === []) {
            $this->components->warn('No worker metrics found in Redis.');

            return;
        }

        // Sort by PID for consistent output.
        sort($workerKeys);

        $rows = [];

        $workerPrefix = $prefix.'worker:';

        foreach ($workerKeys as $key) {
            $fields = $this->getHashAll($redis, $key);

            // Prefer the published pid field; fall back to parsing the
            // `{host}-{pid}-{hex}` worker id for rows written by older code.
            $workerId = str_starts_with($key, $workerPrefix) ? substr($key, strlen($workerPrefix)) : $key;
            $pid = $fields['pid'] ?? WorkerId::parse($workerId)->pid ?? '?';
            $activeSlots = $fields['active_slots'] ?? '0';
            $totalSlots = $fields['total_slots'] ?? '0';
            $jobsProcessed = $fields['jobs_processed'] ?? '0';
            $jobsFailed = $fields['jobs_failed'] ?? '0';
            $avgLatency = $fields['avg_latency_ms'] ?? '0';
            $lastHeartbeat = $fields['last_heartbeat'] ?? null;

            $rows[] = [
                (string) $pid,
                "{$activeSlots}/{$totalSlots}",
                number_format((int) $jobsProcessed),
                number_format((int) $jobsFailed),
                round((float) $avgLatency, 2).' ms',
                $this->formatHeartbeat($lastHeartbeat),
            ];
        }

        $this->table(
            ['PID', 'Slots (active/total)', 'Jobs Processed', 'Failed', 'Avg Latency', 'Last Heartbeat'],
            $rows,
        );
    }

    /**
     * Read all fields from a Redis hash as a key-value array.
     *
     * @return array<string, string>
     */
    private function getHashAll(RedisClient $redis, string $key): array
    {
        try {
            $result = $redis->execute('HGETALL', $key);
        } catch (RedisException) {
            return [];
        }

        if (! is_array($result) || $result === []) {
            return [];
        }

        // HGETALL returns a flat list: [field, value, field, value, ...]
        $result = array_values($result);
        $fields = [];
        for ($i = 0, $count = count($result); $i < $count; $i += 2) {
            $fields[Narrow::string($result[$i])] = Narrow::string($result[$i + 1] ?? null);
        }

        return $fields;
    }

    /**
     * Scan Redis for keys matching a pattern.
     *
     * Uses SCAN with COUNT to avoid blocking on large key spaces.
     *
     * @return string[]
     */
    private function scanKeys(RedisClient $redis, string $pattern): array
    {
        $keys = [];
        $cursor = '0';

        do {
            /** @var array{0: int|string, 1?: list<string>} $result */
            $result = $redis->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100');

            $cursor = (string) $result[0];
            $batch = $result[1] ?? [];

            foreach ($batch as $key) {
                $keys[] = (string) $key;
            }
        } while ($cursor !== '0');

        return $keys;
    }

    /**
     * Format a metric value for display, with an optional unit suffix.
     */
    private function formatMetric(?string $value, string $suffix = ''): string
    {
        if ($value === null || $value === '') {
            return '<fg=gray>--</>';
        }

        return number_format((float) $value, 2).$suffix;
    }

    /**
     * Format a Unix timestamp heartbeat as a human-readable "X seconds ago" string.
     */
    private function formatHeartbeat(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '' || $timestamp === '0') {
            return '<fg=gray>--</>';
        }

        $elapsed = time() - (int) $timestamp;

        if ($elapsed < 0) {
            return '<fg=gray>--</>';
        }

        if ($elapsed < 5) {
            return "<fg=green>{$elapsed}s ago</>";
        }

        if ($elapsed < 30) {
            return "<fg=yellow>{$elapsed}s ago</>";
        }

        return "<fg=red>{$elapsed}s ago</>";
    }
}
