<?php

declare(strict_types=1);

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

it('rejects path outside application directory', function () {
    $this->artisan('torque:supervisor', ['--path' => '/tmp/torque.conf'])
        ->assertFailed()
        ->expectsOutputToContain('must be within the application directory');
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
        ->toContain('command=php')
        ->toContain('torque:start')
        ->toContain('autostart=true')
        ->toContain('autorestart=true')
        ->toContain('stopwaitsecs=60')
        ->toContain('redirect_stderr=true')
        ->toContain('stopasgroup=true')
        ->toContain('killasgroup=true');
});

it('includes correct log path', function () {
    $expectedPath = storage_path('torque-supervisor.conf');

    $this->artisan('torque:supervisor')
        ->assertSuccessful();

    $content = file_get_contents($expectedPath);

    expect($content)->toContain(storage_path('logs/torque.log'));
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
