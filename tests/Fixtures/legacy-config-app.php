<?php

declare(strict_types=1);

/*
 * A real Laravel application around a run directory, for
 * tests/Feature/Support/LegacyConfigTest.php.
 *
 * `cache` runs Laravel's own config:cache, which boots a second application from
 * bootstrap/app.php and writes bootstrap/cache/config.php. `dump` boots a fresh
 * process, on that cache when it exists, and writes what it sees to config.json.
 * A file in the run directory's config/torque.php acts as the published config.
 */

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $runDir, $action] = $argv;

file_put_contents($runDir.'/bootstrap/app.php', <<<'PHP'
    <?php

    use Illuminate\Foundation\Application;
    use Livewire\LivewireServiceProvider;
    use Webpatser\Torque\TorqueServiceProvider;

    return Application::configure(basePath: dirname(__DIR__))
        ->withProviders([LivewireServiceProvider::class, TorqueServiceProvider::class], withBootstrapProviders: false)
        ->withExceptions()
        ->create();
    PHP);

$app = require $runDir.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);

if ($action === 'cache') {
    exit($kernel->call('config:cache'));
}

$kernel->bootstrap();

file_put_contents($runDir.'/config.json', json_encode([
    'cached' => $app->configurationIsCached(),
    'torque' => $app['config']->get('torque'),
], JSON_THROW_ON_ERROR));
