<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Worker Signal Mask Integration Test
|--------------------------------------------------------------------------
|
| The master blocks SIGTERM/SIGINT/SIGUSR2/SIGCHLD so it can use
| pcntl_sigtimedwait. A mask survives fork and exec, so a worker forked from
| the monitor loop must clear it or it never sees a stop signal.
|
| Linux only: the check reads SigBlk from /proc/self/status.
|
*/

it('forks workers with an empty signal mask', function () {
    if (PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Needs /proc/self/status (Linux only).');
    }

    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $dir = sys_get_temp_dir().'/torque-sigmask-'.bin2hex(random_bytes(4));
    mkdir($dir);

    // Stands in for artisan: reports the blocked-signal mask it started with.
    $fakeArtisan = $dir.'/artisan';
    file_put_contents($fakeArtisan, '<?php preg_match("/^SigBlk:\s*(\w+)/m", (string) file_get_contents("/proc/self/status"), $m); echo "SIGBLK=".($m[1] ?? "unknown")."\n";');

    $harness = $dir.'/harness.php';
    file_put_contents($harness, <<<'PHP'
<?php
require $argv[1];

$master = (new ReflectionClass(Webpatser\Torque\Process\MasterProcess::class))->newInstanceWithoutConstructor();

$init = function (string $artisan): void {
    $this->artisanPath = $artisan;
    $this->config = ['queues' => ['default'], 'slots_per_worker' => 1];
    $this->logger = static function (string $message): void {};
};
Closure::bind($init, $master, Webpatser\Torque\Process\MasterProcess::class)($argv[2]);

// Mimic the monitor loop: block the signals, then fork a worker.
pcntl_sigprocmask(SIG_BLOCK, [SIGTERM, SIGINT, SIGCHLD, SIGUSR2]);

$spawn = function (): void {
    $this->spawnWorker();
};
Closure::bind($spawn, $master, Webpatser\Torque\Process\MasterProcess::class)();

pcntl_wait($status);
PHP);

    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($harness).' '.escapeshellarg($autoload).' '.escapeshellarg($fakeArtisan).' 2>&1');

    @unlink($fakeArtisan);
    @unlink($harness);
    @rmdir($dir);

    expect($output)->toMatch('/SIGBLK=0+\b/', "Worker inherited a blocked signal mask. Output:\n".$output);
})->skip(fn () => PHP_OS_FAMILY !== 'Linux', 'Linux only');
