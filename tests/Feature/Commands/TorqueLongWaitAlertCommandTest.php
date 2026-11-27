<?php

declare(strict_types=1);

use Webpatser\Torque\Console\TorqueLongWaitAlertCommand;

/*
|--------------------------------------------------------------------------
| torque:long-wait-alert: waiting for a step's result
|--------------------------------------------------------------------------
|
| The command waits on the socket its step's fork reports over, through an
| Io\Poll context. These tests drive that wait directly on a real socket pair;
| the steps themselves are covered in MasterProcessLongWaitTest.
|
*/

/**
 * @param  resource  $socket
 */
function torque_lw_read_result($socket, float $seconds): ?string
{
    $method = new ReflectionMethod(TorqueLongWaitAlertCommand::class, 'readStepResult');

    return $method->invoke(new TorqueLongWaitAlertCommand, $socket, microtime(true) + $seconds);
}

/**
 * @return array{0: resource, 1: resource}
 */
function torque_lw_socket_pair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    expect($pair)->toBeArray();

    return $pair;
}

it('returns what the step wrote as soon as the socket closes', function () {
    [$reader, $writer] = torque_lw_socket_pair();

    fwrite($writer, 'ok');
    fclose($writer);

    $started = hrtime(true);

    expect(torque_lw_read_result($reader, 5.0))->toBe('ok')
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
});

it('returns an empty string when the step closed the socket without writing', function () {
    [$reader, $writer] = torque_lw_socket_pair();

    fclose($writer);

    expect(torque_lw_read_result($reader, 5.0))->toBe('');
});

it('returns null at the deadline when nothing was written', function () {
    [$reader, $writer] = torque_lw_socket_pair();

    $started = hrtime(true);
    $result = torque_lw_read_result($reader, 0.3);
    $elapsed = (hrtime(true) - $started) / 1e9;

    expect($result)->toBeNull()
        ->and($elapsed)->toBeGreaterThanOrEqual(0.3)
        ->and($elapsed)->toBeLessThan(1.0);

    fclose($writer);
});

it('keeps what was written when the socket stays open until the deadline', function () {
    [$reader, $writer] = torque_lw_socket_pair();

    fwrite($writer, 'ok');

    $started = hrtime(true);

    expect(torque_lw_read_result($reader, 0.3))->toBe('ok')
        ->and((hrtime(true) - $started) / 1e9)->toBeGreaterThanOrEqual(0.3);

    fclose($writer);
});

it('waits on through a signal and still reads what comes after it', function () {
    [$reader, $writer] = torque_lw_socket_pair();

    $async = pcntl_async_signals(true);
    $signalled = false;
    pcntl_signal(SIGALRM, static function () use (&$signalled): void {
        $signalled = true;
    });

    $pid = pcntl_fork();

    if ($pid === 0) {
        fclose($reader);
        usleep(1_400_000);
        fwrite($writer, 'ok');
        posix_kill((int) getmypid(), SIGKILL);
    }

    fclose($writer);

    try {
        pcntl_alarm(1);

        $started = hrtime(true);
        $result = torque_lw_read_result($reader, 4.0);
        $elapsed = (hrtime(true) - $started) / 1e9;
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_async_signals($async);
        pcntl_waitpid($pid, $status);
    }

    expect($signalled)->toBeTrue()
        ->and($result)->toBe('ok')
        ->and($elapsed)->toBeGreaterThanOrEqual(1.3)
        ->and($elapsed)->toBeLessThan(3.5);
});

it('reads a stream that cannot be polled on an interval instead', function () {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'ok');
    rewind($stream);

    $started = hrtime(true);

    expect(torque_lw_read_result($stream, 5.0))->toBe('ok')
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
});
