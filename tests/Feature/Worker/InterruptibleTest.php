<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Jobs\Job as IlluminateJob;
use Illuminate\Support\Facades\Event;
use Webpatser\Torque\Queue\StreamJob;
use Webpatser\Torque\Queue\StreamQueue;
use Webpatser\Torque\Worker\WorkerProcess;

final class TorqueInterruptibleSpyJob implements Interruptible
{
    public ?int $receivedSignal = null;

    public function interrupted(int $signal): void
    {
        $this->receivedSignal = $signal;
    }
}

final class TorquePlainSpyJob
{
    public bool $wasInterrupted = false;
}

function torque_make_stream_job_with_command(?object $command): StreamJob
{
    $streamQueue = new StreamQueue(
        redisUri: 'redis://127.0.0.1:6379',
        default: 'default',
        retryAfter: 90,
        blockFor: 0,
        prefix: 'torque-test:',
        consumerGroup: 'torque-test',
    );

    $payload = json_encode([
        'displayName' => 'Test',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'Test', 'command' => 'serialized'],
    ], JSON_THROW_ON_ERROR);

    $job = new StreamJob(
        container: app(),
        streamQueue: $streamQueue,
        rawBody: $payload,
        messageId: '1-0',
        connectionName: 'torque',
        queue: 'default',
    );

    if ($command !== null) {
        $handler = new CallQueuedHandler(app(BusDispatcher::class), app());
        (new ReflectionProperty(CallQueuedHandler::class, 'runningCommand'))
            ->setValue($handler, $command);
        (new ReflectionProperty(IlluminateJob::class, 'instance'))
            ->setValue($job, $handler);
    }

    return $job;
}

it('dispatches WorkerInterrupted exactly once with the right signal, connection and queue', function () {
    Event::fake([WorkerInterrupted::class]);

    $worker = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);

    $worker->notifyInterrupted(SIGTERM, [], app(EventDispatcher::class), 'torque', 'default');

    Event::assertDispatchedTimes(WorkerInterrupted::class, 1);
    Event::assertDispatched(WorkerInterrupted::class, fn (WorkerInterrupted $event) =>
        $event->signal === SIGTERM
        && $event->connectionName === 'torque'
        && $event->queue === 'default');
});

it('forwards the signal to running Interruptible commands', function () {
    $worker = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);

    $command = new TorqueInterruptibleSpyJob();
    $job = torque_make_stream_job_with_command($command);

    $worker->notifyInterrupted(SIGINT, [0 => $job], app(EventDispatcher::class), 'torque', 'default');

    expect($command->receivedSignal)->toBe(SIGINT);
});

it('leaves non-Interruptible commands untouched', function () {
    $worker = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);

    $command = new TorquePlainSpyJob();
    $job = torque_make_stream_job_with_command($command);

    $worker->notifyInterrupted(SIGTERM, [0 => $job], app(EventDispatcher::class), 'torque', 'default');

    expect($command->wasInterrupted)->toBeFalse();
});

it('skips slots whose StreamJob has not yet resolved a handler', function () {
    $worker = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);

    $job = torque_make_stream_job_with_command(null);

    expect(fn () => $worker->notifyInterrupted(
        SIGTERM,
        [0 => $job],
        app(EventDispatcher::class),
        'torque',
        'default',
    ))->not->toThrow(Throwable::class);
});

it('forwards to every in-flight Interruptible across slots without stopping on a thrower', function () {
    $worker = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);

    $first = new TorqueInterruptibleSpyJob();
    $second = new class implements Interruptible {
        public function interrupted(int $signal): void
        {
            throw new RuntimeException('boom');
        }
    };
    $third = new TorqueInterruptibleSpyJob();

    $jobs = [
        0 => torque_make_stream_job_with_command($first),
        1 => torque_make_stream_job_with_command($second),
        2 => torque_make_stream_job_with_command($third),
    ];

    $worker->notifyInterrupted(SIGTERM, $jobs, app(EventDispatcher::class), 'torque', 'default');

    expect($first->receivedSignal)->toBe(SIGTERM)
        ->and($third->receivedSignal)->toBe(SIGTERM);
});
