<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Notifications\SlackNotificationRouterChannel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Webpatser\Torque\Events\LongWaitDetected;
use Webpatser\Torque\Notifications\LongWaitNotification;
use Webpatser\Torque\Process\MasterProcess;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Torque;

/*
 * `MasterProcess::handleLongWaitTick()` reads the oldest waiting job of each
 * queue that has a `waits` threshold, against real Redis, and leaves the alert to
 * a child process. The clock is passed in, so a job pushed now is "old" without
 * sleeping. The children are real processes (tests/Fixtures/long-wait-app.php),
 * whose behaviour follows the queue name: `fail*`, `hang*` and `slow-listener*`.
 * What the child sends is tested through the real command, in this process.
 */

/**
 * @return list<array{pid: int, queue: string, seconds: int, threshold: int, limit: string}>
 */
function torque_lw_alerts(string $dir): array
{
    $lines = @file($dir.'/alerts.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
}

/**
 * @return list<int>
 */
function torque_lw_workers(string $dir): array
{
    return array_map(intval(...), @file($dir.'/workers.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
}

function torque_lw_until(Closure $condition, float $seconds = 10.0): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        clearstatcache();

        if ($condition()) {
            return true;
        }

        usleep(20_000);
    }

    return false;
}

/**
 * The process state of each direct child of a PID.
 *
 * @return array<int, string> PID => `ps` state.
 */
function torque_lw_children(int $parent): array
{
    exec('ps -axo ppid=,pid=,stat=', $lines);

    $children = [];

    foreach ($lines as $line) {
        [$ppid, $pid, $stat] = preg_split('/\s+/', trim($line));

        if ((int) $ppid === $parent) {
            $children[(int) $pid] = $stat;
        }
    }

    return $children;
}

/**
 * Runs the command as if its process started this many seconds ago. Its deadline
 * counts from the start of the process, which here is the test run's.
 */
function torque_lw_alert_command(array $options = [], float $bootedFor = 0.0): int
{
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - $bootedFor;

    return Artisan::call('torque:long-wait-alert', ['queue' => 'emails', 'seconds' => 120, 'threshold' => 60] + $options);
}

/**
 * Whether a new master would get the lock, through an open of its own.
 */
function torque_lw_lock_is_free(string $path): bool
{
    $handle = fopen($path, 'c');
    $free = flock($handle, LOCK_EX | LOCK_NB);

    fclose($handle);

    return $free;
}

/**
 * Not a zombie, which a container without an init may never reap.
 */
function torque_lw_is_running(int $pid): bool
{
    $stat = trim((string) shell_exec("ps -o stat= -p {$pid}"));

    return $stat !== '' && ! str_starts_with($stat, 'Z');
}

/**
 * A notification channel doing what the closure says.
 */
function torque_lw_channel(Closure $send): object
{
    return new class($send)
    {
        public function __construct(private readonly Closure $sender) {}

        public function send(object $notifiable, object $notification): void
        {
            ($this->sender)($notifiable, $notification);
        }
    };
}

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-lw-master-'.bin2hex(random_bytes(4)).':';
    $this->logs = [];
    $this->runDir = sys_get_temp_dir().'/torque-lw-'.bin2hex(random_bytes(4));
    $this->masterProcess = null;

    mkdir($this->runDir.'/storage/framework', 0777, true);
    file_put_contents($this->runDir.'/artisan', "<?php\n\$runDir = __DIR__;\nrequire ".var_export(dirname(__DIR__, 2).'/Fixtures/long-wait-app.php', true).";\n");

    $this->master = function (array $waits, ?string $uri = null, array $settings = []): MasterProcess {
        // The master takes its children's script from base_path('artisan').
        app()->setBasePath($this->runDir);

        return new MasterProcess([
            'redis' => ['uri' => $uri ?? torqueRedisUri(), 'prefix' => $this->prefix],
            'consumer_group' => 'torque-test',
            'streams' => ['default' => [], 'emails' => []],
            'waits' => $waits,
        ] + $settings, function (string $message): void {
            $this->logs[] = $message;
        });
    };

    // The command's steps run in forks, so what they send is recorded in a file.
    $this->recordChannel = function (string $channel): void {
        $file = $this->runDir.'/sent.log';

        Notification::extend($channel, fn (): object => torque_lw_channel(static function (object $notifiable, object $notification) use ($channel, $file): void {
            file_put_contents($file, "{$channel} {$notifiable->routes[$channel]} {$notification->queue}\n", FILE_APPEND);
        }));
    };

    $this->sent = fn (): array => @file($this->runDir.'/sent.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    // A real master running tests/Fixtures/long-wait-app.php, not the one in this process.
    $this->startRealMaster = function (array $waits): int {
        file_put_contents($this->runDir.'/config.json', json_encode([
            'redis' => ['uri' => torqueRedisUri(), 'prefix' => $this->prefix, 'cluster' => false],
            'consumer_group' => 'torque-test',
            'workers' => 1,
            'waits' => $waits,
            'metrics' => ['enabled' => false],
            'dead_letter' => ['prune_interval' => 0],
            'loop_stall_kill_seconds' => 0,
            'autoscale' => ['enabled' => false],
        ], JSON_THROW_ON_ERROR));

        $this->masterProcess = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/long-wait-app.php', $this->runDir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
        );

        return proc_get_status($this->masterProcess)['pid'];
    };

    $this->push = function (string $queue): void {
        $stream = new StreamQueue(
            redisUri: torqueRedisUri(),
            default: 'default',
            prefix: $this->prefix,
            consumerGroup: 'torque-test',
        );

        $stream->pushRaw(json_encode([
            'uuid' => bin2hex(random_bytes(8)),
            'displayName' => 'Waiting',
            'job' => 'Waiting@handle',
            'data' => [],
            'attempts' => 0,
        ]), $queue);
    };

    Torque::routeMailNotificationsTo(null);
    Torque::routeSlackNotificationsTo(null);
});

afterEach(function () {
    Torque::routeMailNotificationsTo(null);
    Torque::routeSlackNotificationsTo(null);

    if (is_resource($this->masterProcess)) {
        proc_terminate($this->masterProcess, SIGKILL);
        proc_close($this->masterProcess);
    }

    $pids = [...array_column(torque_lw_alerts($this->runDir), 'pid'), ...torque_lw_workers($this->runDir)];

    foreach ($pids as $pid) {
        @posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }

    File::deleteDirectory($this->runDir);

    $cursor = '0';

    do {
        $result = $this->redis->execute('SCAN', $cursor, 'MATCH', $this->prefix.'*', 'COUNT', '100');
        $cursor = (string) $result[0];

        foreach ($result[1] ?? [] as $key) {
            $this->redis->execute('DEL', (string) $key);
        }
    } while ($cursor !== '0');
});

it('leaves the alert to one child per episode, with queue and seconds', function () {
    Event::fake([LongWaitDetected::class]);
    Notification::fake();
    Torque::routeMailNotificationsTo('ops@example.com');

    ($this->push)('emails');
    $master = ($this->master)(['emails' => 60]);
    $now = microtime(true) + 120;

    $master->handleLongWaitTick($now);
    $master->handleLongWaitTick($now + 31);

    expect(torque_lw_until(fn (): bool => torque_lw_alerts($this->runDir) !== []))->toBeTrue();

    $alert = torque_lw_alerts($this->runDir)[0];

    expect(torque_lw_alerts($this->runDir))->toHaveCount(1)
        ->and($alert['queue'])->toBe('emails')
        ->and($alert['seconds'])->toBeGreaterThanOrEqual(119)->toBeLessThanOrEqual(121)
        ->and($alert['threshold'])->toBe(60)
        ->and($alert['limit'])->toBe('--limit=60')
        ->and(implode("\n", $this->logs))->toContain('WARN Long wait on queue emails');

    // Nothing of it runs in the master itself.
    Event::assertNotDispatched(LongWaitDetected::class);
    Notification::assertNothingSent();
});

it('does not stall the tick on a child whose listener hangs', function () {
    // Would run inline in a master that still dispatched the event itself.
    Event::listen(LongWaitDetected::class, static function (): void {
        sleep(3);
    });

    ($this->push)('slow-listener');
    $master = ($this->master)(['slow-listener' => 60]);

    $started = hrtime(true);
    $master->handleLongWaitTick(microtime(true) + 120);
    $elapsed = (hrtime(true) - $started) / 1e9;

    expect($elapsed)->toBeLessThan(1.0)
        ->and(torque_lw_until(fn (): bool => file_exists($this->runDir.'/listener-started')))->toBeTrue()
        ->and($master->longWaitAlerts)->toHaveCount(1);
});

it('keeps the master working when a child fails, and retries sooner than the cooldown', function () {
    ($this->push)('fail');
    $master = ($this->master)(['fail' => 60]);
    $now = microtime(true) + 120;

    $master->handleLongWaitTick($now);

    expect(torque_lw_until(function () use ($master): bool {
        $master->superviseLongWaitAlerts();

        return $master->longWaitAlerts === [];
    }))->toBeTrue();

    $log = implode("\n", $this->logs);

    expect($log)->toContain('Long-wait alert for queue fail failed (exit code 1)')
        ->and($log)->not->toContain('Long-wait check failed')
        ->and(posix_kill(torque_lw_alerts($this->runDir)[0]['pid'], 0))->toBeFalse();

    // The check at +299 is one second early; the next check comes 30s after it.
    $master->handleLongWaitTick($now + 299);
    expect($master->longWaitAlerts)->toBe([]);

    $master->handleLongWaitTick($now + 329);
    expect($master->longWaitAlerts)->toHaveCount(1);
});

it('kills a child past the limit when the child does not end itself', function () {
    ($this->push)('hang');
    $master = ($this->master)(['hang' => 60], settings: ['long_wait_alert_seconds' => 1]);

    $master->handleLongWaitTick(microtime(true) + 120);

    expect(torque_lw_until(fn (): bool => torque_lw_alerts($this->runDir) !== []))->toBeTrue();

    $pid = torque_lw_alerts($this->runDir)[0]['pid'];

    $master->superviseLongWaitAlerts();

    expect(posix_kill($pid, 0))->toBeTrue();

    // The deadline is the limit plus slack; moved here rather than waited out.
    $alerts = $master->longWaitAlerts;
    $alerts[$pid]['killAt'] = hrtime(true);
    (new ReflectionProperty($master, 'longWaitAlerts'))->setValue($master, $alerts);

    expect(torque_lw_until(function () use ($master): bool {
        $master->superviseLongWaitAlerts();

        return $master->longWaitAlerts === [];
    }))->toBeTrue()
        ->and(posix_kill($pid, 0))->toBeFalse()
        ->and(implode("\n", $this->logs))->toContain("killing alert child PID {$pid}");
});

it('has the child end itself at its own limit', function () {
    ($this->push)('slow-listener');
    $master = ($this->master)(['slow-listener' => 60], settings: ['long_wait_alert_seconds' => 1]);

    $started = hrtime(true);
    $master->handleLongWaitTick(microtime(true) + 120);

    expect(torque_lw_until(function () use ($master): bool {
        $master->superviseLongWaitAlerts();

        return $master->longWaitAlerts === [];
    }, 5.5))->toBeTrue();

    // The master's own kill comes at limit + 5, and it logs a kill it sent.
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(5.9)
        ->and(file_exists($this->runDir.'/listener-started'))->toBeTrue()
        ->and(implode("\n", $this->logs))->toContain('failed (exit code 1)')
        ->and(implode("\n", $this->logs))->not->toContain('killing alert child')
        ->and((string) file_get_contents($this->runDir.'/child.log'))->toContain('event failed (timed out after 1s)')
        ->and(posix_kill(torque_lw_alerts($this->runDir)[0]['pid'], 0))->toBeFalse();
});

it('caps the running children and re-arms the gate of a queue it could not alert for', function () {
    $queues = ['hang-1', 'hang-2', 'hang-3', 'hang-4', 'hang-5'];

    foreach ($queues as $queue) {
        ($this->push)($queue);
    }

    $master = ($this->master)(array_fill_keys($queues, 60));
    $now = microtime(true) + 120;

    $master->handleLongWaitTick($now);

    expect($master->longWaitAlerts)->toHaveCount(4)
        ->and(torque_lw_until(fn (): bool => count(torque_lw_alerts($this->runDir)) === 4))->toBeTrue()
        ->and(implode("\n", $this->logs))->toContain('Long-wait alert for queue hang-5 not started');

    $master->handleLongWaitTick($now + 31);
    expect($master->longWaitAlerts)->toHaveCount(4);

    posix_kill(torque_lw_alerts($this->runDir)[0]['pid'], SIGTERM);

    expect(torque_lw_until(function () use ($master): bool {
        $master->superviseLongWaitAlerts();

        return count($master->longWaitAlerts) === 3;
    }))->toBeTrue();

    $master->handleLongWaitTick($now + 62);

    expect(torque_lw_until(fn (): bool => in_array('hang-5', array_column(torque_lw_alerts($this->runDir), 'queue'), true)))->toBeTrue();
});

it('leaves worker reaping alone and no zombie behind in a real master', function () {
    ($this->push)('emails');
    ($this->push)('hang');

    $masterPid = ($this->startRealMaster)(['emails' => 1, 'hang' => 1]);

    expect(torque_lw_until(fn (): bool => count(torque_lw_alerts($this->runDir)) === 2 && count(torque_lw_workers($this->runDir)) === 1))->toBeTrue();

    $pids = array_column(torque_lw_alerts($this->runDir), 'pid', 'queue');
    $worker = torque_lw_workers($this->runDir)[0];

    // The fast child is gone, not a zombie; the hanging one is still running.
    expect(torque_lw_until(fn (): bool => array_keys(torque_lw_children($masterPid)) == [min($worker, $pids['hang']), max($worker, $pids['hang'])]))->toBeTrue()
        ->and(implode('', torque_lw_children($masterPid)))->not->toContain('Z');

    $log = (string) file_get_contents($this->runDir.'/master.log');

    expect($log)->not->toContain("Worker PID {$pids['emails']}")
        ->and($log)->not->toContain('Respawning')
        ->and(torque_lw_workers($this->runDir))->toHaveCount(1);

    // With an alert child hanging, a dead worker is still noticed and replaced.
    posix_kill($worker, SIGKILL);

    expect(torque_lw_until(fn (): bool => count(torque_lw_workers($this->runDir)) === 2, 5.0))->toBeTrue()
        ->and((string) file_get_contents($this->runDir.'/master.log'))->toContain("Worker PID {$worker} killed by signal 9")
        ->and(posix_kill($pids['hang'], 0))->toBeTrue();
});

it('lets a new master take the lock while the children of the old one still run', function (int $signal, bool $workerSurvives) {
    ($this->push)('hang');

    ($this->startRealMaster)(['hang' => 1]);

    expect(torque_lw_until(fn (): bool => torque_lw_alerts($this->runDir) !== [] && torque_lw_workers($this->runDir) !== []))->toBeTrue();

    $lock = $this->runDir.'/storage/torque.lock';
    $alert = torque_lw_alerts($this->runDir)[0]['pid'];
    $worker = torque_lw_workers($this->runDir)[0];

    expect(torque_lw_lock_is_free($lock))->toBeFalse();

    proc_terminate($this->masterProcess, $signal);

    expect(torque_lw_until(fn (): bool => ! proc_get_status($this->masterProcess)['running']))->toBeTrue()
        ->and(torque_lw_lock_is_free($lock))->toBeTrue()
        ->and(torque_lw_is_running($alert))->toBeTrue()
        ->and(torque_lw_is_running($worker))->toBe($workerSurvives);
})->with([
    'stopped, with an alert running' => [SIGTERM, false],
    'killed, with an alert and a worker running' => [SIGKILL, true],
]);

it('has a child end at its limit plus the slack when nothing else ends it', function () {
    ($this->push)('hang');
    $master = ($this->master)(['hang' => 60], settings: ['long_wait_alert_seconds' => 1]);

    $started = hrtime(true);
    $master->handleLongWaitTick(microtime(true) + 120);

    expect(torque_lw_until(fn (): bool => torque_lw_alerts($this->runDir) !== []))->toBeTrue();

    $pid = torque_lw_alerts($this->runDir)[0]['pid'];
    $status = 0;

    // The master is not looking: only the child's own alarm can end this.
    expect(torque_lw_until(function () use ($pid, &$status): bool {
        return pcntl_waitpid($pid, $status, WNOHANG) === $pid;
    }, 8.0))->toBeTrue()
        ->and((hrtime(true) - $started) / 1e9)->toBeGreaterThan(5.0);

    // PHP on Apple Silicon runs its own time limit on SIGALRM, and dies with a fatal
    // error on this sleep instead.
    if (PHP_OS_FAMILY === 'Linux') {
        expect(pcntl_wifsignaled($status))->toBeTrue()
            ->and(pcntl_wtermsig($status))->toBe(SIGALRM);
    }
});

it('starts no alert while stopping or draining, and still reaps the running ones', function (string $flag) {
    ($this->push)('fail');
    $master = ($this->master)(['fail' => 60, 'emails' => 60]);
    $now = microtime(true) + 120;

    $master->handleLongWaitTick($now);

    expect($master->longWaitAlerts)->toHaveCount(1);

    (new ReflectionProperty($master, $flag))->setValue($master, true);
    ($this->push)('emails');

    expect(torque_lw_until(function () use ($master, $now): bool {
        $master->handleLongWaitTick($now + 31);

        return $master->longWaitAlerts === [];
    }))->toBeTrue()
        ->and(array_column(torque_lw_alerts($this->runDir), 'queue'))->toBe(['fail'])
        ->and(implode("\n", $this->logs))->not->toContain('Long wait on queue emails');
})->with(['shouldStop', 'draining', 'drainRequested']);

it('counts the time the process took to boot against the limit', function () {
    Event::listen(LongWaitDetected::class, static function (): void {
        sleep(30);
    });

    $started = hrtime(true);
    $code = torque_lw_alert_command(['--limit' => 5], bootedFor: 4.0);

    expect($code)->toBe(1)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(3.0)
        ->and(Artisan::output())->toContain('event failed (timed out after 1s)');
});

it('does not call a step timed out when a process it started keeps the socket open', function () {
    $pidFile = $this->runDir.'/background.pid';

    Event::listen(LongWaitDetected::class, static function () use ($pidFile): void {
        exec('sleep 8 > /dev/null 2>&1 & echo $! > '.escapeshellarg($pidFile));
    });

    $code = torque_lw_alert_command(['--limit' => 1]);
    $background = (int) file_get_contents($pidFile);

    expect($code)->toBe(0)
        ->and(Artisan::output())->not->toContain('timed out')
        ->and(torque_lw_is_running($background))->toBeTrue();

    posix_kill($background, SIGKILL);
});

it('kills what a step started when the step runs out of time', function () {
    $pidFile = $this->runDir.'/background.pid';

    Event::listen(LongWaitDetected::class, static function () use ($pidFile): void {
        exec('sleep 8 > /dev/null 2>&1 & echo $! > '.escapeshellarg($pidFile));
        sleep(30);
    });

    expect(torque_lw_alert_command(['--limit' => 1]))->toBe(1)
        ->and(Artisan::output())->toContain('event failed (timed out after 1s)');

    $background = (int) file_get_contents($pidFile);

    expect(torque_lw_until(fn (): bool => ! torque_lw_is_running($background), 3.0))->toBeTrue();
});

it('ships the limit of an alert in the config', function () {
    expect(config('torque.long_wait_alert_seconds'))->toBe(60);
});

it('sends the mail notification to the routed address', function () {
    ($this->recordChannel)('mail');
    Torque::routeMailNotificationsTo('ops@example.com');

    expect(torque_lw_alert_command())->toBe(0)
        ->and(($this->sent)())->toBe(['mail ops@example.com emails']);
});

it('sends nothing but still dispatches the event when no route is set', function () {
    ($this->recordChannel)('mail');
    ($this->recordChannel)('slack');

    Event::listen(LongWaitDetected::class, function (LongWaitDetected $e): void {
        file_put_contents($this->runDir.'/sent.log', "event {$e->queue} {$e->seconds} {$e->threshold}\n", FILE_APPEND);
    });

    expect(torque_lw_alert_command())->toBe(0)
        ->and(($this->sent)())->toBe(['event emails 120 60']);
});

it('runs the event before the notifications', function () {
    ($this->recordChannel)('mail');
    ($this->recordChannel)('slack');
    Torque::routeMailNotificationsTo('ops@example.com');
    Torque::routeSlackNotificationsTo('#ops');

    Event::listen(LongWaitDetected::class, function (): void {
        file_put_contents($this->runDir.'/sent.log', "event\n", FILE_APPEND);
    });

    expect(torque_lw_alert_command())->toBe(0)
        ->and(($this->sent)())->toBe(['event', 'mail ops@example.com emails', 'slack #ops emails']);
});

it('skips Slack with a log line when the channel package is not installed', function () {
    if (class_exists(SlackMessage::class)) {
        $this->markTestSkipped('laravel/slack-notification-channel is installed.');
    }

    Torque::routeSlackNotificationsTo('https://hooks.slack.com/services/T/B/x');

    expect(torque_lw_alert_command())->toBe(0)
        ->and(Artisan::output())->toContain('slack-notification-channel');
});

it('still sends the mail when a listener throws', function () {
    ($this->recordChannel)('mail');
    Torque::routeMailNotificationsTo('ops@example.com');

    Event::listen(LongWaitDetected::class, static function (): void {
        throw new RuntimeException('listener broke');
    });

    expect(torque_lw_alert_command())->toBe(1)
        ->and(Artisan::output())->toContain('listener broke')
        ->and(($this->sent)())->toBe(['mail ops@example.com emails']);
});

it('counts a step as failed when its exception message reads like success', function (string $message) {
    Event::listen(LongWaitDetected::class, static function () use ($message): void {
        throw new RuntimeException($message);
    });

    expect(torque_lw_alert_command())->toBe(1)
        ->and(Artisan::output())->toContain("event failed ({$message})");
})->with(['ok', 'failed:ok']);

it('still sends to Slack when the mail throws', function () {
    ($this->recordChannel)('slack');

    Notification::extend('mail', fn (): object => torque_lw_channel(static function (): void {
        throw new RuntimeException('mail broke');
    }));

    Torque::routeMailNotificationsTo('ops@example.com');
    Torque::routeSlackNotificationsTo('#ops');

    expect(torque_lw_alert_command())->toBe(1)
        ->and(Artisan::output())->toContain('mail broke')
        ->and(($this->sent)())->toBe(['slack #ops emails']);
});

it('moves on to the mail when a listener outlasts its share of the limit', function () {
    ($this->recordChannel)('mail');
    Torque::routeMailNotificationsTo('ops@example.com');

    Event::listen(LongWaitDetected::class, static function (): void {
        sleep(30);
    });

    $started = hrtime(true);
    $code = torque_lw_alert_command(['--limit' => 2]);

    expect($code)->toBe(1)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(5.0)
        ->and(Artisan::output())->toContain('event failed (timed out after 1s)')
        ->and(($this->sent)())->toBe(['mail ops@example.com emails']);
});

it('moves on when a step dies without reporting', function () {
    ($this->recordChannel)('mail');
    Torque::routeMailNotificationsTo('ops@example.com');

    Event::listen(LongWaitDetected::class, static function (): void {
        posix_kill((int) getmypid(), SIGKILL);
    });

    expect(torque_lw_alert_command())->toBe(1)
        ->and(Artisan::output())->toContain('ended without reporting')
        ->and(($this->sent)())->toBe(['mail ops@example.com emails']);
});

it('moves on to Slack when the SMTP host never answers', function () {
    // Accepts the connection through the backlog and never says anything.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    config([
        'mail.default' => 'smtp',
        'mail.from' => ['address' => 'torque@example.com', 'name' => 'Torque'],
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'timeout' => 30],
    ]);

    ($this->recordChannel)('slack');

    Torque::routeMailNotificationsTo('ops@example.com');
    Torque::routeSlackNotificationsTo('#ops');

    $started = hrtime(true);
    $code = torque_lw_alert_command(['--limit' => 3]);

    fclose($server);

    expect($code)->toBe(1)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(6.0)
        ->and(Artisan::output())->toContain('mail failed (timed out')
        ->and(($this->sent)())->toBe(['slack #ops emails']);
});

it('delivers through the real Slack package on both route types', function () {
    if (! class_exists(SlackNotificationRouterChannel::class)) {
        $this->markTestSkipped('laravel/slack-notification-channel is not installed.');
    }

    Notification::extend('slack', fn ($app): object => new SlackNotificationRouterChannel($app));

    $history = [];
    $stack = HandlerStack::create(new MockHandler([new GuzzleResponse(200)]));
    $stack->push(Middleware::history($history));
    app()->bind(SlackWebhookChannel::class, fn (): SlackWebhookChannel => new SlackWebhookChannel(new GuzzleClient(['handler' => $stack])));

    // Sent here rather than through the command: its steps run in forks, which
    // would keep what these two fakes record.
    $notification = new LongWaitNotification('emails', 120, 60, ['slack']);

    Notification::route('slack', 'https://hooks.slack.com/services/T/B/x')->notify($notification);

    expect($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toBe('https://hooks.slack.com/services/T/B/x')
        ->and(json_decode((string) $history[0]['request']->getBody(), true)['text'])->toContain('queue emails');

    config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test']);
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

    Notification::route('slack', '#ops')->notify($notification);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === '#ops'
        && str_contains((string) $request['text'], 'queue emails'));
});

it('does not fire while the oldest job is under the threshold', function () {
    ($this->push)('emails');
    $master = ($this->master)(['emails' => 60]);

    $master->handleLongWaitTick(microtime(true) + 10);

    expect($master->longWaitAlerts)->toBe([])
        ->and(implode("\n", $this->logs))->not->toContain('Long wait');
});

it('checks only queues with a threshold', function () {
    ($this->push)('default');
    ($this->push)('emails');

    $master = ($this->master)(['emails' => 60, 'default' => 0]);
    $master->handleLongWaitTick(microtime(true) + 120);

    expect(torque_lw_until(fn (): bool => torque_lw_alerts($this->runDir) !== []))->toBeTrue()
        ->and(array_column(torque_lw_alerts($this->runDir), 'queue'))->toBe(['emails']);
});

it('makes no Redis call when no queue has a threshold', function () {
    // Nothing listens here: any attempt to connect would be logged as a failure.
    $master = ($this->master)(['emails' => 0], 'redis://127.0.0.1:1');
    $master->handleLongWaitTick(microtime(true) + 120);

    $absent = new MasterProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:1', 'prefix' => $this->prefix],
    ], function (string $message): void {
        $this->logs[] = $message;
    });
    $absent->handleLongWaitTick(microtime(true) + 120);

    expect($this->logs)->toBe([])
        ->and((new ReflectionProperty($master, 'longWaitDueAt'))->getValue($master))->toBeNull()
        ->and($master->longWaitAlerts)->toBe([]);
});

it('survives a Redis failure and logs it', function () {
    $master = ($this->master)(['emails' => 60], 'redis://127.0.0.1:1');
    $master->handleLongWaitTick(microtime(true) + 120);

    expect(implode("\n", $this->logs))->toContain('Long-wait check failed')
        ->and($master->longWaitAlerts)->toBe([]);
});

it('reads Redis at a throttled interval', function () {
    $master = ($this->master)(['emails' => 60]);
    $now = microtime(true) + 120;

    // Nothing waits at the first read, so the gate holds nothing against this queue.
    $master->handleLongWaitTick($now);

    ($this->push)('emails');

    // The job is over its threshold now: only a call that skips the read misses it.
    $master->handleLongWaitTick($now + 1);

    expect(implode("\n", $this->logs))->not->toContain('Long wait');

    $master->handleLongWaitTick($now + 31);

    expect(implode("\n", $this->logs))->toContain('Long wait on queue emails');
});
