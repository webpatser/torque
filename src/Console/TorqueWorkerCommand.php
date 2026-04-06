<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Webpatser\Torque\Worker\WorkerProcess;

/**
 * Internal command — runs a single Torque worker process.
 *
 * Not intended for direct use. The MasterProcess spawns this via proc_open()
 * so each worker is a clean PHP process (no pcntl_fork Fiber conflicts).
 *
 * Usage:
 *   php artisan torque:worker --queues=scrpr --concurrency=5
 */
final class TorqueWorkerCommand extends Command
{
    protected $signature = 'torque:worker
        {--queues=default : Comma-separated queue names}
        {--concurrency=50 : Coroutine slots}';

    protected $description = 'Run a single Torque worker (internal — use torque:start instead)';

    protected $hidden = true;

    public function handle(): int
    {
        fwrite(STDERR, "[torque:worker] PID " . getmypid() . " starting\n");

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
                fwrite(STDERR, "[torque:worker] FATAL: [{$error['type']}] {$error['message']} in {$error['file']}:{$error['line']}\n");
            }
        });

        $config = config('torque');

        if ($this->option('queues') !== null) {
            $config['queues'] = array_map('trim', explode(',', $this->option('queues')));
        }

        if ($this->option('concurrency') !== null) {
            $config['coroutines_per_worker'] = (int) $this->option('concurrency');
        }

        $worker = new WorkerProcess($config);
        $worker->run();

        return self::SUCCESS;
    }
}
