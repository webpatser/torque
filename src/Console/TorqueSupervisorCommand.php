<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;

/**
 * Generate a Supervisor configuration file for running Torque in production.
 *
 * Usage:
 *   php artisan torque:supervisor
 *   php artisan torque:supervisor --workers=8 --user=forge
 *   php artisan torque:supervisor --path=/etc/supervisor/conf.d/torque.conf
 */
final class TorqueSupervisorCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:supervisor
        {--path= : Output path for the config file}
        {--user= : Process user}
        {--workers= : Number of worker processes}';

    /** @var string */
    protected $description = 'Generate a Supervisor config file for Torque';

    public function handle(): int
    {
        $path = $this->option('path') ?? storage_path('torque-supervisor.conf');

        // Prevent path traversal: output must be within storage or base path.
        $realParent = realpath(dirname($path));
        if ($realParent === false
            || (!str_starts_with($realParent, storage_path()) && !str_starts_with($realParent, base_path()))) {
            $this->components->error('Output path must be within the application directory.');
            return self::FAILURE;
        }

        $user = preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->option('user') ?? 'www-data');
        $workers = (int) ($this->option('workers') ?? config('torque.workers', 4));

        $artisanPath = base_path('artisan');
        $logPath = storage_path('logs/torque.log');

        $config = <<<INI
        [program:torque]
        process_name=%(program_name)s
        command=php {$artisanPath} torque:start --workers={$workers}
        autostart=true
        autorestart=true
        stopwaitsecs=60
        user={$user}
        redirect_stderr=true
        stdout_logfile={$logPath}
        stopasgroup=true
        killasgroup=true
        INI;

        file_put_contents($path, $config);

        $this->components->info("Supervisor config written to {$path}");

        return self::SUCCESS;
    }
}
