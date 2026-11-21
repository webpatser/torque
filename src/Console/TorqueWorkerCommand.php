<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Fledge\Loop\UnsupportedFeatureException;
use Illuminate\Console\Command;
use Webpatser\Torque\Support\EventLoopDriver;
use Webpatser\Torque\Support\Narrow;
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

    protected $signature = 'torque:worker
        {--queues=default : Comma-separated queue names}
        {--concurrency=50 : Coroutine slots}';

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
}
