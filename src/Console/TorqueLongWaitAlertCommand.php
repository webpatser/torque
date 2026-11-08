<?php

declare(strict_types=1);

namespace Webpatser\Torque\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;
use Webpatser\Torque\Events\LongWaitDetected;
use Webpatser\Torque\Notifications\LongWaitNotification;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\Torque;

/**
 * Runs one long-wait alert, in a process of its own.
 *
 * Not intended for direct use. The master starts this and only reads its exit
 * status, so a listener or a mail server that hangs costs the master nothing.
 *
 * The event and each notification are separate steps, each in a fork of this process
 * that is killed at its share of the limit, what earlier steps left unused included.
 * A fork because nothing softer ends a hang: a signal handler only runs once PHP
 * regains control, and neither a read on a black-holed SMTP socket nor a call inside
 * libcurl gives it back before its own timeout. It also means a step that throws,
 * hangs or dies on a fatal error costs the next step nothing.
 *
 * Exit status 1 means at least one step failed, which makes the master retry sooner.
 */
final class TorqueLongWaitAlertCommand extends Command
{
    /** What a step's fork reports when it finished without an exception. */
    private const string STEP_OK = 'ok';

    /** Goes before the exception message, so no message can read as {@see STEP_OK}. */
    private const string STEP_FAILED = 'failed:';

    /** @var string */
    protected $signature = 'torque:long-wait-alert
        {queue : The queue over its threshold}
        {seconds : Seconds the oldest job has waited}
        {threshold : The queue\'s threshold in seconds}
        {--limit=60 : Seconds this alert may take}';

    /** @var string */
    protected $description = 'Dispatch and send one long-wait alert (started by the Torque master)';

    /** @var bool */
    protected $hidden = true;

    public function handle(): int
    {
        $queue = (string) $this->argument('queue');
        $seconds = (int) $this->argument('seconds');
        $threshold = (int) $this->argument('threshold');

        $steps = ['event' => static function () use ($queue, $seconds, $threshold): void {
            event(new LongWaitDetected($queue, $seconds, $threshold));
        }];

        foreach ($this->routes() as $channel => $route) {
            $steps[$channel] = static function () use ($channel, $route, $queue, $seconds, $threshold): void {
                Notification::route($channel, $route)
                    ->notify(new LongWaitNotification($queue, $seconds, $threshold, [$channel]));
            };
        }

        // From the start of this process, as booting the app counts against the limit
        // too: the master's kill and the alarm it set before exec do not wait for it.
        $deadline = Narrow::float($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) + max(1, (int) $this->option('limit'));
        $stepsLeft = count($steps);
        $succeeded = true;

        foreach ($steps as $name => $step) {
            $budget = max(1, (int) ceil(($deadline - microtime(true)) / $stepsLeft));
            $stepsLeft--;

            $failure = $this->runStep($step, $budget);

            if ($failure !== null) {
                $succeeded = false;
                $this->note("Long-wait alert for queue {$queue}: {$name} failed ({$failure}).");
            }
        }

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string> Channel => route.
     */
    private function routes(): array
    {
        $routes = [];

        if (($mail = Torque::mailNotificationRoute()) !== null) {
            $routes['mail'] = $mail;
        }

        if (($slack = Torque::slackNotificationRoute()) !== null) {
            if ($this->slackChannelExists()) {
                $routes['slack'] = $slack;
            } else {
                $this->note('Long-wait Slack alert skipped: install laravel/slack-notification-channel to use Torque::routeSlackNotificationsTo().');
            }
        }

        return $routes;
    }

    private function slackChannelExists(): bool
    {
        $manager = Notification::getFacadeRoot();

        if (! $manager instanceof ChannelManager) {
            return true;
        }

        try {
            $manager->driver('slack');

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Run a step in a fork, killing it after this many seconds.
     *
     * The fork reports over a socket pair and ends with SIGKILL rather than exit():
     * it is a copy of a booted app, and its shutdown work would run a second time,
     * on connections it shares with this process.
     *
     * The fork leads a process group, so a step that runs out of time takes along
     * what it started: a hung sendmail or curl would otherwise outlive this command,
     * one more with every alert. What a finished step left running is left alone.
     *
     * @param  Closure(): void  $step
     * @return string|null Why the step failed, null when it did not.
     */
    private function runStep(Closure $step, int $seconds): ?string
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            return 'could not open a socket pair';
        }

        [$reader, $writer] = $sockets;

        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($reader);
            fclose($writer);

            return 'could not fork';
        }

        if ($pid === 0) {
            posix_setpgid(0, 0);
            fclose($reader);

            try {
                $step();
                $result = self::STEP_OK;
            } catch (Throwable $e) {
                $result = self::STEP_FAILED.($e->getMessage() ?: $e::class);
            }

            fwrite($writer, $result);
            posix_kill((int) getmypid(), SIGKILL);
        }

        // On both sides of the fork, so the group exists whichever runs first.
        posix_setpgid($pid, $pid);
        fclose($writer);

        $result = $this->readStepResult($reader, microtime(true) + $seconds);

        fclose($reader);

        if ($result === null) {
            posix_kill(-$pid, SIGKILL);
        }

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        return match ($result) {
            self::STEP_OK => null,
            null => "timed out after {$seconds}s",
            '' => 'ended without reporting a result',
            default => str_starts_with($result, self::STEP_FAILED) ? substr($result, strlen(self::STEP_FAILED)) : 'reported an unreadable result',
        };
    }

    /**
     * Read until the fork is gone, or give up at the deadline.
     *
     * Anything read means the step finished, as the fork writes only as its last act.
     * No end of file follows that if a process it started inherited the socket.
     *
     * @param  resource  $socket
     * @return string|null What the fork wrote, null when the deadline passed with nothing.
     */
    private function readStepResult($socket, float $deadline): ?string
    {
        stream_set_blocking($socket, false);

        $buffer = '';

        while (($left = $deadline - microtime(true)) > 0) {
            $read = [$socket];
            $write = $except = null;

            // False means a signal interrupted the wait: just go around again.
            if (! @stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1.0) * 1_000_000))) {
                continue;
            }

            $chunk = fread($socket, 8192);

            if ($chunk === false || ($chunk === '' && feof($socket))) {
                return $buffer;
            }

            $buffer .= $chunk;
        }

        return $buffer === '' ? null : $buffer;
    }

    /**
     * To the master's output, which this process shares, and to the app's log.
     */
    private function note(string $message): void
    {
        $this->line('[Torque] '.$message);

        try {
            Log::warning($message);
        } catch (Throwable) {
            // An unwritable log must not cost the next step.
        }
    }
}
