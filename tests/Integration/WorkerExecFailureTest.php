<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A worker whose exec fails
|--------------------------------------------------------------------------
|
| The master forks and then execs the PHP binary. When that exec fails (the
| binary is gone after a PHP upgrade), the child is still a copy of the master,
| so it must end without running the master's shutdown functions and
| destructors a second time, and without the exception an application's error
| handler makes of the exec warning unwinding it through the master's code. A
| harness process plays the master: it registers both, installs a throwing error
| handler as Laravel does, spawns a worker with a PHP binary that does not
| exist, and reports how the child ended and who ran the shutdown work.
|
*/

it('ends a worker whose exec failed with SIGKILL, without the master shutdown work', function () {
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $dir = sys_get_temp_dir().'/torque-execfail-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $harness = $dir.'/harness.php';
    file_put_contents($harness, <<<'PHP'
<?php
require $argv[1];
$marks = $argv[2];

final class TorqueExecFailureProbe
{
    public function __construct(private string $marks) {}

    public function __destruct()
    {
        file_put_contents($this->marks, 'destructor '.getmypid()."\n", FILE_APPEND);
    }
}

$probe = new TorqueExecFailureProbe($marks);

// As Laravel's HandleExceptions does: every warning becomes an exception. Thrown
// out of a failed pcntl_exec(), it would unwind the child through this script.
set_error_handler(static function (int $level, string $message): never {
    throw new ErrorException($message, 0, $level);
});
register_shutdown_function(static function () use ($marks): void {
    file_put_contents($marks, 'shutdown '.getmypid()."\n", FILE_APPEND);
});

$master = (new ReflectionClass(Webpatser\Torque\Process\MasterProcess::class))->newInstanceWithoutConstructor();

Closure::bind(function (): void {
    $this->artisanPath = '/nonexistent/artisan';
    $this->phpBinary = '/nonexistent/torque-php-binary';
    $this->config = ['queues' => ['default'], 'slots_per_worker' => 1];
    $this->logger = static function (string $message): void {};
    // The constructor is skipped, so set the real fork it would have assigned.
    $this->fork = static fn (): int => pcntl_fork();
}, $master, Webpatser\Torque\Process\MasterProcess::class)();

try {
    Closure::bind(function (): void {
        $this->spawnWorker();
    }, $master, Webpatser\Torque\Process\MasterProcess::class)();
} catch (Throwable $e) {
    // Only the child could get here: the master's spawnWorker() does not exec.
    file_put_contents($marks, 'unwound '.getmypid().': '.$e->getMessage()."\n", FILE_APPEND);
    exit(0);
}

$child = array_key_first($master->workerPids);
pcntl_waitpid($child, $status);

echo json_encode([
    'master' => getmypid(),
    'child' => $child,
    'signaled' => pcntl_wifsignaled($status),
    'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null,
]), "\n";
PHP);

    $marks = $dir.'/marks.log';
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($harness).' '.escapeshellarg($autoload).' '.escapeshellarg($marks).' 2>&1');

    preg_match('/^\{.*\}$/m', $output, $json);
    $report = json_decode($json[0] ?? 'null', true);
    $ran = (string) @file_get_contents($marks);

    @unlink($marks);
    @unlink($harness);
    @rmdir($dir);

    expect($report)->toBeArray("No report from the harness. Output:\n".$output)
        ->and($report['signaled'])->toBeTrue()
        ->and($report['signal'])->toBe(SIGKILL)
        ->and($output)->toContain('[Torque] Worker exec of /nonexistent/torque-php-binary failed')
        // The shutdown function and the destructor ran once, in the master only.
        ->and($ran)->toContain("shutdown {$report['master']}\n")
        ->toContain("destructor {$report['master']}\n")
        ->not->toContain("shutdown {$report['child']}\n")
        ->not->toContain("destructor {$report['child']}\n")
        ->not->toContain('unwound');
});
