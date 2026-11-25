<?php

declare(strict_types=1);

namespace Webpatser\Torque\Process;

use Closure;
use Webpatser\Torque\Manager\AutoScaler;
use Webpatser\Torque\Support\Narrow;

/**
 * One worker group of a master: its queues, its target size, its slots, its
 * own autoscaler, and the respawn, backoff and fast-death state of its workers.
 *
 * The rules are the ones the master applied fleet-wide before groups existed,
 * kept per group so a crash loop in one group never delays another. Pure state:
 * no Redis, no forking, no signals. The master forks, logs and reaps; this
 * class decides when an owed respawn is due and how long a backoff lasts.
 */
final class WorkerGroup
{
    /** Longest wait between two respawn retries, in seconds. */
    public const float RESPAWN_BACKOFF_MAX = 30.0;

    /**
     * A worker that ends within this many seconds of its spawn, other than by an
     * orderly stop or a watchdog kill, died young.
     */
    public const int FAST_DEATH_SECONDS = 10;

    /** Consecutive fast deaths in this group after which the master logs an error. */
    public const int FAST_DEATHS_BEFORE_ERROR = 3;

    /** Respawns owed after a failed fork or a fast death, retried on the backoff. */
    public private(set) int $pendingRespawns = 0;

    /** Clock time of the next respawn retry. */
    public private(set) float $respawnRetryAt = 0.0;

    /** Seconds to wait before the next retry, doubled per failed fork. */
    public private(set) float $respawnBackoff = 0.0;

    /** Workers in a row that died young, reset once a worker spawned since then runs {@see FAST_DEATH_SECONDS}. */
    public private(set) int $fastDeaths = 0;

    /** Unix time of the first fast death of the current run of them. */
    public private(set) int $fastDeathsSince = 0;

    /** Seconds the next respawn after a fast death waits, doubled per fast death. */
    public private(set) float $fastDeathBackoff = 0.0;

    /** Fewest workers the group runs: the autoscale floor, else the target. */
    public int $minWorkers {
        get => $this->autoScaler->minWorkers ?? $this->target;
    }

    /** Most workers the group runs: the autoscale ceiling, else the target. */
    public int $maxWorkers {
        get => $this->autoScaler->maxWorkers ?? $this->target;
    }

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  list<string>  $queues
     * @param  (Closure(): float)|null  $clock  Returns the current time in seconds, microtime(true) by default.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $queues,
        public readonly int $target,
        public readonly int $slots,
        public readonly ?AutoScaler $autoScaler = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Build a group from one `NodeConfig::groups()` entry, with an autoscaler
     * set up the way the master sets up its own when `autoscale.enabled` is on.
     *
     * @param  array{name: string, queues: list<string>, workers: int, slots_per_worker: int, autoscale: array<string, mixed>}  $group
     * @param  (Closure(): float)|null  $clock
     */
    public static function fromConfig(array $group, string $redisUri, ?Closure $clock = null): self
    {
        $autoscale = $group['autoscale'];
        $autoScaler = null;

        if ($autoscale['enabled'] ?? false) {
            $autoScaler = new AutoScaler(
                redisUri: $redisUri,
                minWorkers: Narrow::int($autoscale['min_workers'] ?? null, 2),
                maxWorkers: Narrow::int($autoscale['max_workers'] ?? null, 8),
                scaleUpThreshold: Narrow::float($autoscale['scale_up_threshold'] ?? null, 0.85),
                scaleDownThreshold: Narrow::float($autoscale['scale_down_threshold'] ?? null, 0.20),
                cooldownSeconds: Narrow::int($autoscale['cooldown'] ?? null, 30),
            );
        }

        return new self(
            name: $group['name'],
            queues: $group['queues'],
            target: $group['workers'],
            slots: $group['slots_per_worker'],
            autoScaler: $autoScaler,
            clock: $clock,
        );
    }

    /**
     * The group's size as the autoscaler sees it: its live children not being
     * scaled down, plus the respawns it owes.
     *
     * @param  array<int, mixed>  $pids  The group's live PIDs, keyed by PID.
     * @param  array<int, mixed>  $scalingDown  PIDs being scaled down, keyed by PID.
     */
    public function fleetSize(array $pids, array $scalingDown): int
    {
        return count(array_diff_key($pids, $scalingDown)) + $this->pendingRespawns;
    }

    /** Whether respawns are owed, so a new one joins the queue and keeps the running backoff. */
    public function hasPendingRespawns(): bool
    {
        return $this->pendingRespawns > 0;
    }

    /** Owe one more respawn without touching the backoff (it joins the queue). */
    public function oweRespawn(): void
    {
        $this->pendingRespawns++;
    }

    /**
     * Push the next retry out after a failed fork: the wait starts at 1 second
     * and doubles per failure, up to {@see RESPAWN_BACKOFF_MAX}.
     *
     * @return float The new backoff in seconds, for the log line.
     */
    public function deferRespawn(?float $now = null): float
    {
        $now ??= ($this->clock)();

        $this->respawnBackoff = clamp($this->respawnBackoff * 2, 1.0, self::RESPAWN_BACKOFF_MAX);
        $this->respawnRetryAt = $now + $this->respawnBackoff;

        return $this->respawnBackoff;
    }

    /** Whether an owed respawn may be forked now: one is owed and its backoff has passed. */
    public function respawnDue(?float $now = null): bool
    {
        return $this->pendingRespawns > 0 && ($now ?? ($this->clock)()) >= $this->respawnRetryAt;
    }

    /** An owed respawn was forked: one fewer owed, and the fork backoff starts over. */
    public function recordRespawn(): void
    {
        if ($this->pendingRespawns > 0) {
            $this->pendingRespawns--;
        }

        $this->respawnBackoff = 0.0;
    }

    /**
     * Cancel one owed respawn, as a scale-down does before it terminates a live
     * worker. The fork backoff starts over once nothing is owed.
     *
     * @return bool Whether a respawn was owed and cancelled.
     */
    public function cancelPendingRespawn(): bool
    {
        if ($this->pendingRespawns === 0) {
            return false;
        }

        $this->pendingRespawns--;

        if ($this->pendingRespawns === 0) {
            $this->respawnBackoff = 0.0;
        }

        return true;
    }

    /** Drop every owed respawn, as on a stop. The fast-death run is kept. */
    public function dropPendingRespawns(): void
    {
        $this->pendingRespawns = 0;
        $this->respawnBackoff = 0.0;
    }

    /**
     * Record a reaped worker of this group that the master would replace (not
     * scaled down, no stop requested).
     *
     * A worker that lived {@see FAST_DEATH_SECONDS} or longer, an unknown spawn
     * time, a watchdog kill or an orderly stop (`$exempt`) means an immediate
     * respawn, which the master forks itself (`fastDeath` false). A worker that
     * lived long enough and was spawned during a run of fast deaths ends that run
     * (`resetRun` true). Anything else is a fast death: the respawn is owed, on
     * a wait that starts at 1 second and doubles per fast death up to
     * {@see RESPAWN_BACKOFF_MAX}.
     *
     * @param  int|null  $spawnedAt  Unix time the worker was spawned, null when unknown.
     * @param  bool  $exempt  An orderly stop (status 0 or 12) or a kill of the master's watchdog.
     * @return array{fastDeath: bool, resetRun: bool, age: float|null, backoff: float, backoffChanged: bool, reachedErrorThreshold: bool}
     */
    public function recordExit(?int $spawnedAt, bool $exempt, ?float $now = null): array
    {
        $now ??= ($this->clock)();
        $age = $spawnedAt === null ? null : $now - $spawnedAt;

        if ($age === null || $age >= self::FAST_DEATH_SECONDS || $exempt) {
            $resetRun = $age !== null && $age >= self::FAST_DEATH_SECONDS && $spawnedAt >= $this->fastDeathsSince
                && $this->resetFastDeaths();

            return [
                'fastDeath' => false,
                'resetRun' => $resetRun,
                'age' => $age,
                'backoff' => $this->fastDeathBackoff,
                'backoffChanged' => false,
                'reachedErrorThreshold' => false,
            ];
        }

        if ($this->fastDeaths++ === 0) {
            $this->fastDeathsSince = (int) $now;
        }

        $previous = $this->fastDeathBackoff;
        $this->fastDeathBackoff = clamp($this->fastDeathBackoff * 2, 1.0, self::RESPAWN_BACKOFF_MAX);
        $this->pendingRespawns++;
        $this->respawnRetryAt = max($this->respawnRetryAt, $now + $this->fastDeathBackoff);

        return [
            'fastDeath' => true,
            'resetRun' => false,
            'age' => $age,
            'backoff' => $this->fastDeathBackoff,
            'backoffChanged' => $this->fastDeathBackoff !== $previous,
            'reachedErrorThreshold' => $this->fastDeaths === self::FAST_DEATHS_BEFORE_ERROR,
        ];
    }

    /**
     * End a run of fast deaths once a worker spawned during it has run
     * {@see FAST_DEATH_SECONDS}. A worker spawned before the run started proves
     * nothing about the ones that keep dying.
     *
     * @param  array<int, int|null>  $spawnedAt  The group's live PIDs => unix time each was spawned.
     * @return int|null The surviving PID that ended the run, null when the run goes on or none was running.
     */
    public function resetFastDeathsOnSurvivor(array $spawnedAt, ?float $now = null): ?int
    {
        if ($this->fastDeaths === 0) {
            return null;
        }

        $now ??= ($this->clock)();

        foreach ($spawnedAt as $pid => $at) {
            if ($at !== null && $at >= $this->fastDeathsSince && $now - $at >= self::FAST_DEATH_SECONDS) {
                $this->resetFastDeaths();

                return $pid;
            }
        }

        return null;
    }

    /**
     * End the current run of fast deaths: respawns are immediate again.
     *
     * @return bool Whether a run was going on, so the master logs the recovery once.
     */
    public function resetFastDeaths(): bool
    {
        if ($this->fastDeaths === 0) {
            return false;
        }

        $this->fastDeaths = 0;
        $this->fastDeathsSince = 0;
        $this->fastDeathBackoff = 0.0;

        return true;
    }
}
