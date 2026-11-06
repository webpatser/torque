<?php

declare(strict_types=1);

/*
 * A real Torque master and its real workers, for tests/Integration/LoopStallWatchdogTest.php.
 *
 * The test starts this file as the master with the run directory as its argument.
 * The master execs base_path('artisan'), which in that directory is a stub that
 * sets $runDir and requires this file again, so each worker is a fresh process
 * with the same config and the same storage path as its master.
 *
 * The run directory holds config.json (merged over the package config), master.log
 * and storage/. Its name deliberately avoids "artisan torque:start" on the command
 * line, so the orphan sweeps of other tests never target this master.
 */

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\TorqueServiceProvider;
use Webpatser\Torque\Worker\WorkerProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$isWorker = isset($runDir);
$runDir ??= $argv[1];

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

$settings = json_decode((string) file_get_contents($runDir.'/config.json'), true, flags: JSON_THROW_ON_ERROR);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
    'loop_stall_probe.log' => $runDir.'/jobs.jsonl',
]);

// Lets a test give the app another cache store than the array store above.
config($settings['app_config'] ?? []);

$config = array_replace_recursive(config('torque'), require __DIR__.'/baseline-limits.php', $settings);

$app->setBasePath($runDir);
$app->useStoragePath($runDir.'/storage');

if ($isWorker) {
    (new WorkerProcess($config))->run();

    exit(0);
}

cli_set_process_title('torque:start loop-stall-fixture');

// Lets a test take Redis away from the master alone.
$config['redis']['uri'] = $config['master_redis_uri'] ?? $config['redis']['uri'];

$logFile = $runDir.'/master.log';

exit((new MasterProcess($config, static function (string $message) use ($logFile): void {
    file_put_contents($logFile, $message."\n", FILE_APPEND);
}))->start());
