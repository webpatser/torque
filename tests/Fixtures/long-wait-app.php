<?php

declare(strict_types=1);

/*
 * Real processes for tests/Feature/Process/MasterProcessLongWaitTest.php.
 *
 * Started with the run directory as its argument, this file is a real master. The
 * master execs base_path('artisan'), which in that directory is a stub that sets
 * $runDir and requires this file again. There `torque:worker` is a process that only
 * sleeps, and `torque:long-wait-alert` behaves according to the queue name, so one
 * master can have a fast and a hanging alert at the same time.
 *
 * alerts.jsonl gets one line per alert child, workers.log one PID per worker.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Symfony\Component\Console\Output\StreamOutput;
use Webpatser\Torque\Events\LongWaitDetected;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\TorqueServiceProvider;

$isChild = isset($runDir);
$runDir ??= $argv[1];

$boot = static function () use ($runDir): void {
    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    $app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
    $app->register(LivewireServiceProvider::class);
    $app->register(TorqueServiceProvider::class);

    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'cache.default' => 'array',
        'logging.default' => 'null',
    ]);

    $app->setBasePath($runDir);
    $app->useStoragePath($runDir.'/storage');
};

if ($isChild && ($argv[1] ?? '') === 'torque:worker') {
    file_put_contents($runDir.'/workers.log', getmypid()."\n", FILE_APPEND);
    sleep(120);

    exit(0);
}

if ($isChild) {
    $queue = (string) $argv[2];

    file_put_contents($runDir.'/alerts.jsonl', json_encode([
        'pid' => getmypid(),
        'queue' => $queue,
        'seconds' => (int) $argv[3],
        'threshold' => (int) $argv[4],
        'limit' => (string) ($argv[5] ?? ''),
    ])."\n", FILE_APPEND);

    if (str_starts_with($queue, 'fail')) {
        exit(1);
    }

    if (str_starts_with($queue, 'hang')) {
        // Like a hung boot: none of the command's limits, only the master's kill and
        // the alarm it set before exec can end it.
        sleep(120);

        exit(0);
    }

    if (str_starts_with($queue, 'slow-listener')) {
        $boot();

        Event::listen(LongWaitDetected::class, static function () use ($runDir): void {
            touch($runDir.'/listener-started');
            sleep(120);
        });

        exit(Artisan::call('torque:long-wait-alert', [
            'queue' => $queue,
            'seconds' => (int) $argv[3],
            'threshold' => (int) $argv[4],
            '--limit' => (int) substr((string) $argv[5], strlen('--limit=')),
        ], new StreamOutput(fopen($runDir.'/child.log', 'a'))));
    }

    exit(0);
}

$boot();

$settings = json_decode((string) file_get_contents($runDir.'/config.json'), true, flags: JSON_THROW_ON_ERROR);
$logFile = $runDir.'/master.log';

cli_set_process_title('torque:start long-wait-fixture');

exit((new MasterProcess(array_replace_recursive(config('torque'), require __DIR__.'/baseline-limits.php', $settings), static function (string $message) use ($logFile): void {
    file_put_contents($logFile, $message."\n", FILE_APPEND);
}))->start());
