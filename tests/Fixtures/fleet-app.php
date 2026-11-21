<?php

declare(strict_types=1);

/*
 * A real fleet through the real commands, for tests/Integration/FleetLifecycleTest.php.
 *
 * Started with the run directory as its argument, this file runs `torque:start`
 * in a process group of its own, so a test can always take the whole fleet down
 * with one kill. The master execs base_path('artisan'), a stub in the run
 * directory that sets $runDir and requires this file again, which then runs
 * `torque:worker` with the options the master passed. Both commands install the
 * event loop driver from `torque.event_loop` and say which one on their output.
 *
 * The run directory holds config.json (merged over the package config) and
 * storage/. The process title carries "torque:start" so the orphan sweeps of
 * torque:start know the workers have a live master, but never "artisan
 * torque:start", so the sweeps of other tests never target it.
 */

use Illuminate\Support\Facades\Artisan;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Symfony\Component\Console\Output\StreamOutput;
use Webpatser\Torque\TorqueServiceProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$isWorker = isset($runDir);
$runDir ??= $argv[1];

if (! $isWorker) {
    posix_setpgid(0, 0);
    cli_set_process_title('torque:start fleet-fixture');
}

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(LivewireServiceProvider::class);
$app->register(TorqueServiceProvider::class);

$settings = json_decode((string) file_get_contents($runDir.'/config.json'), true, flags: JSON_THROW_ON_ERROR);

config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'cache.default' => 'array',
    'queue.failed.driver' => 'null',
    'loop_stall_probe.log' => $runDir.'/jobs.jsonl',
    'torque' => array_replace_recursive(config('torque'), require __DIR__.'/baseline-limits.php', $settings),
]);

$app->setBasePath($runDir);
$app->useStoragePath($runDir.'/storage');

$output = new StreamOutput(STDOUT);

if ($isWorker) {
    $options = [];

    foreach (array_slice($argv, 2) as $argument) {
        [$name, $value] = explode('=', $argument, 2) + [1 => ''];
        $options[$name] = $value;
    }

    exit(Artisan::call('torque:worker', $options, $output));
}

exit(Artisan::call('torque:start', [
    '--workers' => (string) config('torque.workers'),
    '--concurrency' => (string) config('torque.slots_per_worker'),
    '--queues' => implode(',', config('torque.queues')),
], $output));
