<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Fledge\Loop\UnsupportedFeatureException;
use Illuminate\Console\Command;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Support\EventLoopDriver;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Support\NodeConfig;
use Webpatser\Torque\Support\NodeName;
use Webpatser\Torque\Worker\WorkerProcess;

/**
 * Internal command: runs a single Torque worker process.
 *
 * Not intended for direct use. The MasterProcess starts this with pcntl_fork()
 * followed at once by pcntl_exec(), so each worker is a fresh PHP process that
 * inherits no event loop, fiber or connection state from the master.
 *
 * Usage:
 *   php artisan torque:worker --queues=scrpr --concurrency=5
 */
final class TorqueWorkerCommand extends Command
{
    /** Exit status for a setting the worker cannot start on (sysexits EX_CONFIG). */
    public const int EXIT_CONFIG = 78;

    /** The node block settings a worker applies itself; the master hands it the rest as options. */
    public const array NODE_WORKER_KEYS = ['max_jobs_per_worker', 'max_worker_lifetime', 'memory_limit'];

    protected $signature = 'torque:worker
        {--queues=default : Comma-separated queue names}
        {--concurrency=50 : Coroutine slots}
        {--node= : Node name of the spawning master}
        {--master-id= : Id of the spawning master}
        {--group=default : Worker group, for display}';

    protected $description = 'Run a single Torque worker (internal: use torque:start instead)';

    protected $hidden = true;

    public function handle(): int
    {
        fwrite(STDERR, '[torque:worker] PID '.getmypid()." starting\n");

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
                fwrite(STDERR, "[torque:worker] FATAL: [{$error['type']}] {$error['message']} in {$error['file']}:{$error['line']}\n");
            }
        });

        $config = Narrow::map(config('torque'));
        $environment = EventLoopDriver::environment();
        $source = EventLoopDriver::source($environment);

        // Before anything runs on the loop. This process was exec'd by the master,
        // so it inherited no loop state: install(), never afterFork(). torque:start
        // checked the same setting, but .env can change while the fleet runs: the
        // reason goes to stderr, which the master shares, and the status tells the
        // master's respawn backoff that this was a configuration refusal.
        try {
            $driver = EventLoopDriver::install($config, static function (string $warning): void {
                fwrite(STDERR, "[torque:worker] WARN {$warning}\n");
            }, $environment);
        } catch (\InvalidArgumentException|UnsupportedFeatureException $e) {
            fwrite(STDERR, "[torque:worker] FATAL Unusable event loop driver from {$source}: {$e->getMessage()}\n");

            return self::EXIT_CONFIG;
        }

        fwrite(STDERR, "[torque:worker] Event loop: {$driver} (from {$source})\n");

        // The master passes its node; a master from before --node did not, and
        // then the worker resolves the name the same way that master did.
        try {
            $node = NodeName::setCurrent(NodeName::resolve(Narrow::nullableString($this->option('node')), $config));
            $config = self::applyNodeSettings($config, $node);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, "[torque:worker] FATAL Unusable node configuration: {$e->getMessage()}\n");

            return self::EXIT_CONFIG;
        }

        $masterId = Narrow::nullableString($this->option('master-id'));

        if ($masterId !== null && $masterId !== '' && ! MasterRegistry::isValidMasterId($masterId)) {
            fwrite(STDERR, "[torque:worker] WARN Ignoring invalid --master-id [{$masterId}]: only the legacy drain applies\n");
            $masterId = null;
        }

        $config['master_id'] = $masterId !== '' ? $masterId : null;
        $config['group'] = self::groupName(Narrow::nullableString($this->option('group')));

        if ($this->option('queues') !== null) {
            $config['queues'] = array_map('trim', explode(',', $this->option('queues')));
        }

        if ($this->option('concurrency') !== null) {
            $config['slots_per_worker'] = (int) $this->option('concurrency');
        }

        $worker = new WorkerProcess($config);
        $worker->run();

        return self::SUCCESS;
    }

    /**
     * The flat config with the worker-level limits of the node's block applied
     * and `node` set. A fresh worker process reads the flat `torque` config,
     * so without this a node's limits would never reach it. Queues, workers,
     * slots and autoscale are left alone: the master passes the worker's
     * queues and slots as options.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException For an invalid `nodes` config.
     */
    public static function applyNodeSettings(array $config, string $node): array
    {
        $resolved = NodeConfig::resolve($config, $node);

        foreach (self::NODE_WORKER_KEYS as $key) {
            if (array_key_exists($key, $resolved)) {
                $config[$key] = $resolved[$key];
            }
        }

        $config['node'] = $node;

        return $config;
    }

    /**
     * The group name from `--group`, `default` when blank or not a valid name.
     */
    public static function groupName(?string $option): string
    {
        $group = trim($option ?? '');

        if ($group === '') {
            return NodeConfig::DEFAULT_GROUP;
        }

        if (preg_match(NodeConfig::GROUP_NAME_PATTERN, $group) !== 1) {
            fwrite(STDERR, "[torque:worker] WARN Ignoring invalid --group [{$group}], using ".NodeConfig::DEFAULT_GROUP."\n");

            return NodeConfig::DEFAULT_GROUP;
        }

        return $group;
    }
}
