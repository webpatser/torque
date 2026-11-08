<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Webpatser\Torque\Support\DrainSettings;

/**
 * Generate a Supervisor configuration file for running Torque in production.
 *
 * Usage:
 *   php artisan torque:supervisor
 *   php artisan torque:supervisor --workers=8 --user=forge
 *   php artisan torque:supervisor --path=storage/torque.conf
 *   php artisan torque:supervisor --stdout | sudo tee /etc/supervisor/conf.d/torque.conf
 */
final class TorqueSupervisorCommand extends Command
{
    /** @var string */
    protected $signature = 'torque:supervisor
        {--path= : Output path, must be inside storage_path(); use --stdout to pipe elsewhere}
        {--stdout : Print the config to stdout instead of writing a file}
        {--user= : Process user}
        {--workers= : Number of worker processes}
        {--php= : PHP binary for the command= line (defaults to the running PHP binary)}
        {--app-path= : Application root for artisan and logs (defaults to the Deployer current symlink or base_path())}';

    /** @var string */
    protected $description = 'Generate a Supervisor config file for Torque';

    public function handle(): int
    {
        $toStdout = (bool) $this->option('stdout');
        $path = $this->option('path') ?? storage_path('torque-supervisor.conf');

        // Output must resolve to an existing directory under storage_path().
        // storage_path() is trusted; arbitrary dirs aren't, and realpath() on
        // a non-existent parent returns false (so a prepared symlink can't
        // slip past a !== false check). --stdout writes nothing, so the
        // operator decides where the config goes (sudo tee).
        if (! $toStdout && ! $this->isWithinStorage($path)) {
            $this->components->error('Output path must be within storage_path(). Use --stdout to pipe the config elsewhere.');

            return self::FAILURE;
        }

        $appPath = $this->option('app-path');

        if (is_string($appPath) && $appPath !== '' && ! str_starts_with($appPath, '/')) {
            $this->components->error('The --app-path value must be an absolute path (starting with /).');

            return self::FAILURE;
        }

        $root = $this->resolveAppRoot();
        $php = $this->resolvePhpBinary();

        // Both end up inside a double-quoted value; a quote or a control
        // character would break out of it and inject supervisor directives,
        // and supervisord expands %(...)s in values.
        foreach (['--app-path' => $root, '--php' => $php] as $option => $value) {
            if (preg_match('/["%\x00-\x1F\x7F]/', $value) === 1) {
                $this->components->error("The {$option} value may not contain quotes, percent signs or control characters.");

                return self::FAILURE;
            }
        }

        // Keep stdout clean for piping into sudo tee.
        if ($toStdout && $this->option('path') !== null) {
            $this->output->getErrorStyle()->writeln('<comment>WARN</comment> --path is ignored with --stdout.');
        }

        $user = preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->option('user') ?? 'www-data');
        $workers = (int) ($this->option('workers') ?? config('torque.workers', 4));

        // Deployer shares storage/ across releases, so the log path under the
        // stable root stays valid after the next deploy. A storage path that
        // lives outside base_path() is kept as is.
        $artisanPath = $this->joinPath($root, 'artisan');
        $storage = storage_path();
        $base = base_path();
        $logPath = str_starts_with($storage, $base.'/')
            ? $this->joinPath($root, substr($storage, strlen($base) + 1).'/logs/torque.log')
            : storage_path('logs/torque.log');

        // stopwaitsecs has to clear a full drain, because killasgroup means
        // supervisord SIGKILLs the whole fleet the moment it expires. A fixed
        // 60 was shorter than the drain on any installation that raised
        // drain_grace_seconds, so it scales with the worst case and keeps 60
        // as the floor.
        $stopWaitSecs = max(60, DrainSettings::drainWorstCaseSeconds((array) config('torque')));

        // --replace makes the supervised start converge: a live master that
        // is not supervised (stray takeover master, manual start) is absorbed
        // via the takeover handshake instead of the start failing until the
        // program goes FATAL. startretries covers the window where an
        // absorbed master is still draining.
        $config = <<<INI
        [program:torque]
        process_name=%(program_name)s
        command="{$php}" "{$artisanPath}" torque:start --replace --workers={$workers}
        autostart=true
        autorestart=true
        startretries=10
        stopwaitsecs={$stopWaitSecs}
        user={$user}
        redirect_stderr=true
        stdout_logfile="{$logPath}"
        stopasgroup=true
        killasgroup=true
        INI;

        if ($toStdout) {
            foreach (explode("\n", $config) as $line) {
                $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
            }

            return self::SUCCESS;
        }

        file_put_contents($path, $config);

        $this->components->info("Supervisor config written to {$path}");

        return self::SUCCESS;
    }

    /**
     * The application root written into the config.
     *
     * Under Deployer base_path() is releases/<n>, which disappears a few
     * deploys later. When the current symlink points at this very release,
     * the stable current path is used instead. --app-path always wins.
     */
    protected function resolveAppRoot(): string
    {
        $override = $this->option('app-path');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/') ?: '/';
        }

        $base = base_path();

        if (basename(dirname($base)) === 'releases') {
            $current = dirname($base, 2).'/current';
            $realBase = realpath($base);

            if (is_link($current) && $realBase !== false && realpath($current) === $realBase) {
                return $current;
            }
        }

        return $base;
    }

    private function resolvePhpBinary(): string
    {
        $php = $this->option('php');

        if (is_string($php) && $php !== '') {
            return $php;
        }

        $default = $this->defaultPhpBinary();

        return $default !== '' ? $default : 'php';
    }

    /**
     * The running PHP binary; PHP_BINARY can be empty under some SAPIs.
     */
    protected function defaultPhpBinary(): string
    {
        return PHP_BINARY;
    }

    private function joinPath(string $root, string $relative): string
    {
        return rtrim($root, '/').'/'.ltrim($relative, '/');
    }

    private function isWithinStorage(string $path): bool
    {
        $parent = dirname($path);
        $realParent = is_dir($parent) ? realpath($parent) : false;
        $realStorage = realpath(storage_path());

        return $realParent !== false && $realStorage !== false
            && str_starts_with($realParent.DIRECTORY_SEPARATOR, $realStorage.DIRECTORY_SEPARATOR);
    }
}
