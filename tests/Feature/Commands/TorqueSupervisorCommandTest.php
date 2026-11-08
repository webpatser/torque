<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

afterEach(function () {
    // Clean up any generated config files.
    $default = storage_path('torque-supervisor.conf');

    if (file_exists($default)) {
        unlink($default);
    }
});

it('generates config file at default path', function () {
    $expectedPath = storage_path('torque-supervisor.conf');

    $this->artisan('torque:supervisor')
        ->assertSuccessful()
        ->expectsOutputToContain('torque-supervisor.conf');

    expect(file_exists($expectedPath))->toBeTrue();

    $content = file_get_contents($expectedPath);
    expect($content)->toContain('[program:torque]');
});

it('rejects path outside storage directory', function () {
    $this->artisan('torque:supervisor', ['--path' => '/tmp/torque.conf'])
        ->assertFailed()
        ->expectsOutputToContain('must be within storage_path()');
});

it('rejects path under base_path but outside storage_path', function () {
    $this->artisan('torque:supervisor', ['--path' => base_path('torque.conf')])
        ->assertFailed()
        ->expectsOutputToContain('must be within storage_path()');
});

it('quotes artisan path in generated supervisor config', function () {
    $expectedPath = storage_path('torque-supervisor.conf');

    $this->artisan('torque:supervisor')->assertSuccessful();

    $content = file_get_contents($expectedPath);
    $artisan = base_path('artisan');

    expect($content)->toContain("\"{$artisan}\"");
});

it('sanitizes user option by stripping special characters', function () {
    $expectedPath = storage_path('torque-supervisor.conf');

    $this->artisan('torque:supervisor', ['--user' => 'forge$;rm -rf'])
        ->assertSuccessful();

    $content = file_get_contents($expectedPath);

    // The preg_replace strips everything except [a-zA-Z0-9_\-].
    // 'forge$;rm -rf' becomes 'forgerm-rf'.
    expect($content)->toContain('user=forgerm-rf');

    // Verify the dangerous characters are not in the user= line.
    $lines = explode("\n", $content);
    $userLine = '';

    foreach ($lines as $line) {
        if (str_contains(trim($line), 'user=')) {
            $userLine = trim($line);

            break;
        }
    }

    expect($userLine)->toBe('user=forgerm-rf')
        ->not->toContain('$')
        ->not->toContain(';');
});

it('generates config with correct worker count from option', function () {
    $expectedPath = storage_path('torque-supervisor.conf');

    $this->artisan('torque:supervisor', ['--workers' => '12'])
        ->assertSuccessful();

    $content = file_get_contents($expectedPath);
    expect($content)->toContain('--workers=12');
});

it('generates config with correct worker count from config default', function () {
    $expectedPath = storage_path('torque-supervisor.conf');

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    $content = file_get_contents($expectedPath);

    // Default from config is 4.
    $workers = config('torque.workers', 4);
    expect($content)->toContain("--workers={$workers}");
});

it('generates valid supervisor config with required directives', function () {
    $expectedPath = storage_path('torque-supervisor.conf');

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    $content = file_get_contents($expectedPath);

    expect($content)
        ->toContain('[program:torque]')
        ->toContain('process_name=')
        ->toContain('command="'.PHP_BINARY.'"')
        ->toContain('torque:start')
        ->toContain('autostart=true')
        ->toContain('autorestart=true')
        ->toContain('stopwaitsecs=85')
        ->toContain('redirect_stderr=true')
        ->toContain('stopasgroup=true')
        ->toContain('killasgroup=true');
});

it('scales stopwaitsecs with drain_grace_seconds', function () {
    // killasgroup means supervisord SIGKILLs the whole fleet the moment
    // stopwaitsecs expires, so a fixed 60 cut through the drain on any
    // installation that sized the grace for long jobs.
    config()->set('torque.drain_grace_seconds', 300);

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    // pause 10 + grace 300 + slack 15
    expect(file_get_contents(storage_path('torque-supervisor.conf')))
        ->toContain('stopwaitsecs=325');
});

it('scales stopwaitsecs with reload_pause_seconds', function () {
    config()->set('torque.drain_grace_seconds', 300);
    config()->set('torque.reload_pause_seconds', 100);

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    expect(file_get_contents(storage_path('torque-supervisor.conf')))
        ->toContain('stopwaitsecs=415');
});

it('covers a grace derived from the streams in stopwaitsecs', function () {
    config()->set('torque.drain_grace_seconds', null);
    config()->set('torque.streams', ['long' => ['timeout' => 200, 'retry_after' => 240]]);

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    expect(file_get_contents(storage_path('torque-supervisor.conf')))
        ->toContain('stopwaitsecs=225');
});

it('follows a raised drain_slack_seconds in stopwaitsecs', function () {
    config()->set('torque.drain_grace_seconds', 300);
    config()->set('torque.intervals.drain_slack_seconds', 100);

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    expect(file_get_contents(storage_path('torque-supervisor.conf')))
        ->toContain('stopwaitsecs=410');
});

it('keeps 60 as the stopwaitsecs floor', function () {
    config()->set('torque.drain_grace_seconds', 10);

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    expect(file_get_contents(storage_path('torque-supervisor.conf')))
        ->toContain('stopwaitsecs=60');
});

it('includes correct log path', function () {
    $expectedPath = storage_path('torque-supervisor.conf');

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    $content = file_get_contents($expectedPath);

    expect($content)->toContain(storage_path('logs/torque.log'));
});

/**
 * Build a Deployer-like layout: <root>/releases/1, <root>/releases/2 and a
 * current symlink pointing at the given release.
 */
function makeDeployerLayout(string $currentRelease = '1'): string
{
    $root = sys_get_temp_dir().'/torque-deployer-'.bin2hex(random_bytes(6));

    mkdir($root.'/releases/1', 0755, true);
    mkdir($root.'/releases/2', 0755, true);
    symlink($root.'/releases/'.$currentRelease, $root.'/current');

    return $root;
}

function removeDeployerLayout(string $root): void
{
    @unlink($root.'/current');
    @rmdir($root.'/releases/1');
    @rmdir($root.'/releases/2');
    @rmdir($root.'/releases');
    @rmdir($root);
}

it('uses the Deployer current symlink instead of the release path', function () {
    $root = makeDeployerLayout('1');
    $originalBase = base_path();

    try {
        app()->setBasePath($root.'/releases/1');

        $this->artisan('torque:supervisor', ['--stdout' => true])
            ->assertSuccessful()
            ->expectsOutputToContain("\"{$root}/current/artisan\" torque:start")
            ->expectsOutputToContain("stdout_logfile=\"{$root}/current/storage/logs/torque.log\"")
            ->doesntExpectOutputToContain('releases/1');
    } finally {
        app()->setBasePath($originalBase);
        removeDeployerLayout($root);
    }
});

it('keeps base_path when current points at another release', function () {
    $root = makeDeployerLayout('2');
    $originalBase = base_path();

    try {
        app()->setBasePath($root.'/releases/1');

        $this->artisan('torque:supervisor', ['--stdout' => true])
            ->assertSuccessful()
            ->expectsOutputToContain("\"{$root}/releases/1/artisan\" torque:start");
    } finally {
        app()->setBasePath($originalBase);
        removeDeployerLayout($root);
    }
});

it('lets --app-path override the detected root', function () {
    $this->artisan('torque:supervisor', ['--app-path' => '/srv/app/current/'])
        ->assertSuccessful();

    $content = file_get_contents(storage_path('torque-supervisor.conf'));

    expect($content)
        ->toContain('"/srv/app/current/artisan" torque:start')
        ->toContain('stdout_logfile="/srv/app/current/storage/logs/torque.log"')
        ->not->toContain(base_path('artisan'));
});

it('writes the --php binary quoted into the command line', function () {
    $this->artisan('torque:supervisor', ['--php' => '/usr/bin/php8.5'])
        ->assertSuccessful();

    expect(file_get_contents(storage_path('torque-supervisor.conf')))
        ->toContain('command="/usr/bin/php8.5" "'.base_path('artisan').'" torque:start');
});

it('defaults the php binary to the running PHP_BINARY', function () {
    $this->artisan('torque:supervisor')->assertSuccessful();

    expect(file_get_contents(storage_path('torque-supervisor.conf')))
        ->toContain('command="'.PHP_BINARY.'" ')
        ->not->toContain('command=php ');
});

it('prints the config with --stdout and writes no file', function () {
    $this->artisan('torque:supervisor', ['--stdout' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('[program:torque]')
        ->expectsOutputToContain('killasgroup=true')
        ->doesntExpectOutputToContain('Supervisor config written');

    expect(file_exists(storage_path('torque-supervisor.conf')))->toBeFalse();
});

it('skips the storage_path check with --stdout', function () {
    $this->artisan('torque:supervisor', ['--stdout' => true, '--path' => '/etc/supervisor/conf.d/torque.conf'])
        ->assertSuccessful()
        ->expectsOutputToContain('[program:torque]');

    expect(file_exists('/etc/supervisor/conf.d/torque.conf'))->toBeFalse();
});

it('points at --stdout in the storage_path error', function () {
    $this->artisan('torque:supervisor', ['--path' => '/tmp/torque.conf'])
        ->assertFailed()
        ->expectsOutputToContain('--stdout');
});

it('rejects quotes, percent signs and control characters in --php and --app-path', function (string $option, string $value) {
    $this->artisan('torque:supervisor', ['--stdout' => true, $option => $value])
        ->assertFailed()
        ->expectsOutputToContain('may not contain quotes, percent signs or control characters');
})->with([
    'php quote' => ['--php', '/usr/bin/php" evil'],
    'php newline' => ['--php', "/usr/bin/php\nuser=root"],
    'php percent' => ['--php', '/usr/bin/php%(ENV_HOME)s'],
    'app-path quote' => ['--app-path', '/srv/app"'],
    'app-path newline' => ['--app-path', "/srv/app\nuser=root"],
    'app-path percent' => ['--app-path', '/srv/%(program_name)s'],
]);

it('rejects a relative --app-path', function () {
    $this->artisan('torque:supervisor', ['--stdout' => true, '--app-path' => 'srv/app'])
        ->assertFailed()
        ->expectsOutputToContain('must be an absolute path');
});

it('does not double the slash for --app-path=/', function () {
    $this->artisan('torque:supervisor', ['--stdout' => true, '--app-path' => '/'])
        ->assertSuccessful()
        ->expectsOutputToContain('"/artisan" torque:start')
        ->expectsOutputToContain('stdout_logfile="/storage/logs/torque.log"')
        ->doesntExpectOutputToContain('//');
});

it('keeps a storage path outside base_path for the log', function () {
    $originalStorage = storage_path();

    try {
        app()->useStoragePath('/var/shared/torque-storage');

        $this->artisan('torque:supervisor', ['--stdout' => true, '--app-path' => '/srv/app/current'])
            ->assertSuccessful()
            ->expectsOutputToContain('stdout_logfile="/var/shared/torque-storage/logs/torque.log"')
            ->doesntExpectOutputToContain('/srv/app/current/storage');
    } finally {
        app()->useStoragePath($originalStorage);
    }
});

it('warns on stderr that --path is ignored with --stdout and keeps stdout clean', function () {
    $output = new class(fopen('php://memory', 'w+')) extends StreamOutput implements ConsoleOutputInterface
    {
        private ?OutputInterface $stderr = null;

        public function getErrorOutput(): OutputInterface
        {
            return $this->stderr ??= new StreamOutput(fopen('php://memory', 'w+'));
        }

        public function setErrorOutput(OutputInterface $error): void
        {
            $this->stderr = $error;
        }

        public function section(): ConsoleSectionOutput
        {
            throw new LogicException('Sections are not supported.');
        }
    };

    $exitCode = Artisan::call('torque:supervisor', [
        '--stdout' => true,
        '--path' => '/etc/supervisor/conf.d/torque.conf',
    ], $output);

    $read = function (StreamOutput $stream): string {
        rewind($stream->getStream());

        return (string) stream_get_contents($stream->getStream());
    };

    $stdout = trim($read($output));
    $stderr = $read($output->getErrorOutput());

    expect($exitCode)->toBe(0)
        ->and($stdout)->toStartWith('[program:torque]')
        ->and($stdout)->toEndWith('killasgroup=true')
        ->and($stdout)->not->toContain('--path is ignored')
        ->and($stderr)->toContain('--path is ignored with --stdout');
});

it('allows path within storage directory', function () {
    $customPath = storage_path('custom/torque.conf');

    // Create the directory so realpath works.
    if (! is_dir(dirname($customPath))) {
        mkdir(dirname($customPath), 0755, true);
    }

    $this->artisan('torque:supervisor', ['--path' => $customPath])
        ->assertSuccessful();

    expect(file_exists($customPath))->toBeTrue();

    // Clean up.
    unlink($customPath);
    rmdir(dirname($customPath));
});
