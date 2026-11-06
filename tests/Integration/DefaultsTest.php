<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The 2.0 package defaults, on a worker that merges no baseline
|--------------------------------------------------------------------------
|
| tests/Fixtures/defaults-worker.php boots a real WorkerProcess on the merged
| package config alone, and the boot WARN for a PHP memory_limit below the
| worker's is read from its stderr. The ini value is set on the PHP command line.
|
| Requires a running Redis instance.
|
*/

/**
 * @return array{status: array<string, mixed>, output: string}
 */
function torque_run_defaults_worker(string $iniMemoryLimit): array
{
    $token = bin2hex(random_bytes(4));
    $directory = sys_get_temp_dir();
    $statusFile = "{$directory}/torque-defaults-{$token}.json";
    $outputFile = "{$directory}/torque-defaults-{$token}.out";
    $prefix = "torque-defaults-{$token}:";

    try {
        $process = proc_open(
            [PHP_BINARY, '-d', "memory_limit={$iniMemoryLimit}", dirname(__DIR__).'/Fixtures/defaults-worker.php', torqueRedisUri(), $prefix, 'defaults-test', 'defaults', $statusFile],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the worker.');
        }

        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline && (proc_get_status($process)['running'] ?? false)) {
            usleep(100_000);
        }

        if (proc_get_status($process)['running'] ?? false) {
            proc_terminate($process, SIGKILL);
        }

        proc_close($process);

        return [
            'status' => is_file($statusFile) ? json_decode((string) file_get_contents($statusFile), true, flags: JSON_THROW_ON_ERROR) : [],
            'output' => is_file($outputFile) ? (string) file_get_contents($outputFile) : '',
        ];
    } finally {
        @unlink($statusFile);
        @unlink($outputFile);
    }
}

it('ships the 2.0 defaults in the package config', function () {
    expect(config('torque.strict_attempts'))->toBeTrue()
        ->and(config('torque.timeouts.enabled'))->toBeTrue()
        ->and(config('torque.memory_limit'))->toBe(512);
});

it('runs a worker on the new defaults when no baseline is merged', function () {
    $run = torque_run_defaults_worker('1G');

    expect($run['status'])->toBe(['strict_attempts' => true, 'timeouts' => true, 'memory_limit' => 512]);
});

it('warns at boot when the ini memory_limit is below the setting', function (string $ini) {
    $run = torque_run_defaults_worker($ini);

    expect($run['status'])->not->toBe([])
        ->and($run['output'])->toContain('WARN PHP memory_limit')
        ->toContain('torque memory_limit of 512 MB');
})->with(['256M' => '256M', '128M' => '128M']);

it('does not warn when the ini memory_limit is unlimited or high enough', function (string $ini) {
    $run = torque_run_defaults_worker($ini);

    expect($run['status'])->not->toBe([])
        ->and($run['output'])->not->toContain('WARN PHP memory_limit');
})->with(['unlimited' => '-1', 'equal' => '512M', 'gigabyte' => '1G']);
