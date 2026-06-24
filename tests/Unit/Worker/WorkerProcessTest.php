<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\WorkerPausing;
use Illuminate\Queue\Events\WorkerResuming;
use Webpatser\Torque\Worker\WorkerProcess;

it('constructor sets config without errors', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
        'queues' => ['default'],
        'coroutines_per_worker' => 10,
    ]);

    expect($worker)->toBeInstanceOf(WorkerProcess::class);
});

it('consumerId contains hostname and PID', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
    ]);

    // consumerId is a private(set) property, but we can use reflection to verify.
    $reflection = new ReflectionProperty(WorkerProcess::class, 'consumerId');
    $consumerId = $reflection->getValue($worker);

    expect($consumerId)->toContain(gethostname())
        ->and($consumerId)->toContain((string) getmypid());
});

it('shouldStop is false initially', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
    ]);

    expect($worker->shouldStop)->toBeFalse();
});

it('isRunning is false initially', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
    ]);

    expect($worker->isRunning)->toBeFalse();
});

it('jobsProcessed starts at zero', function () {
    $worker = new WorkerProcess([
        'redis' => ['uri' => 'redis://127.0.0.1:6379'],
    ]);

    expect($worker->jobsProcessed)->toBe(0);
});

it('consumerId is unique across instances', function () {
    $workerA = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);
    $workerB = new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);

    $reflection = new ReflectionProperty(WorkerProcess::class, 'consumerId');

    expect($reflection->getValue($workerA))
        ->not->toBe($reflection->getValue($workerB));
});

// -------------------------------------------------------------------------
//  Pause / Resume event dispatch
// -------------------------------------------------------------------------

/**
 * Build a minimal Dispatcher fake that records every dispatched event.
 *
 * @return array{0: Dispatcher, 1: \ArrayObject<int, object>}
 */
function pauseEventRecorder(): array
{
    $log = new \ArrayObject;

    $dispatcher = new class ($log) implements Dispatcher {
        public function __construct(private \ArrayObject $log) {}

        public function dispatch($event, $payload = [], $halt = false)
        {
            $this->log->append($event);

            return null;
        }

        public function listen($events, $listener = null) {}
        public function hasListeners($eventName): bool { return false; }
        public function subscribe($subscriber) {}
        public function until($event, $payload = []) { return null; }
        public function push($event, $payload = []) {}
        public function flush($event) {}
        public function forget($event) {}
        public function forgetPushed() {}
    };

    return [$dispatcher, $log];
}

it('dispatches WorkerPausing on a false to true transition', function () {
    [$events, $log] = pauseEventRecorder();
    $pauseState = new \stdClass;
    $pauseState->paused = false;

    WorkerProcess::applyPauseTransition(true, $pauseState, $events, 'torque', 'default');

    expect($pauseState->paused)->toBeTrue();
    expect($log)->toHaveCount(1);
    expect($log[0])->toBeInstanceOf(WorkerPausing::class);
    expect($log[0]->connectionName)->toBe('torque');
    expect($log[0]->queue)->toBe('default');
});

it('dispatches WorkerResuming on a true to false transition', function () {
    [$events, $log] = pauseEventRecorder();
    $pauseState = new \stdClass;
    $pauseState->paused = true;

    WorkerProcess::applyPauseTransition(false, $pauseState, $events, 'torque', 'default');

    expect($pauseState->paused)->toBeFalse();
    expect($log)->toHaveCount(1);
    expect($log[0])->toBeInstanceOf(WorkerResuming::class);
    expect($log[0]->connectionName)->toBe('torque');
    expect($log[0]->queue)->toBe('default');
});

it('does not dispatch when the pause state is unchanged', function () {
    [$events, $log] = pauseEventRecorder();
    $pauseState = new \stdClass;
    $pauseState->paused = false;

    WorkerProcess::applyPauseTransition(false, $pauseState, $events, 'torque', 'default');
    WorkerProcess::applyPauseTransition(false, $pauseState, $events, 'torque', 'default');

    expect($log)->toHaveCount(0);
});

it('only dispatches once per flip while the worker stays paused', function () {
    [$events, $log] = pauseEventRecorder();
    $pauseState = new \stdClass;
    $pauseState->paused = false;

    WorkerProcess::applyPauseTransition(true, $pauseState, $events, 'torque', 'default');
    WorkerProcess::applyPauseTransition(true, $pauseState, $events, 'torque', 'default');
    WorkerProcess::applyPauseTransition(true, $pauseState, $events, 'torque', 'default');

    expect($log)->toHaveCount(1);
    expect($log[0])->toBeInstanceOf(WorkerPausing::class);
});

it('dispatches both events when the worker pauses then resumes', function () {
    [$events, $log] = pauseEventRecorder();
    $pauseState = new \stdClass;
    $pauseState->paused = false;

    WorkerProcess::applyPauseTransition(true, $pauseState, $events, 'torque', 'default');
    WorkerProcess::applyPauseTransition(false, $pauseState, $events, 'torque', 'default');

    expect($log)->toHaveCount(2);
    expect($log[0])->toBeInstanceOf(WorkerPausing::class);
    expect($log[1])->toBeInstanceOf(WorkerResuming::class);
});

it('forwards the configured connection name and primary queue', function () {
    [$events, $log] = pauseEventRecorder();
    $pauseState = new \stdClass;
    $pauseState->paused = false;

    WorkerProcess::applyPauseTransition(true, $pauseState, $events, 'reverb', 'high');

    expect($log[0])->toBeInstanceOf(WorkerPausing::class);
    expect($log[0]->connectionName)->toBe('reverb');
    expect($log[0]->queue)->toBe('high');
});

it('swallows dispatcher exceptions so the poller keeps running', function () {
    $pauseState = new \stdClass;
    $pauseState->paused = false;

    $events = new class implements Dispatcher {
        public function dispatch($event, $payload = [], $halt = false)
        {
            throw new RuntimeException('listener exploded');
        }

        public function listen($events, $listener = null) {}
        public function hasListeners($eventName): bool { return false; }
        public function subscribe($subscriber) {}
        public function until($event, $payload = []) { return null; }
        public function push($event, $payload = []) {}
        public function flush($event) {}
        public function forget($event) {}
        public function forgetPushed() {}
    };

    ob_start();
    WorkerProcess::applyPauseTransition(true, $pauseState, $events, 'torque', 'default');
    ob_end_clean();

    expect($pauseState->paused)->toBeTrue();
});

describe('shouldStopRetries', function () {
    // Unit tests do not boot Testbench, so stand up a bare container for the
    // app(ExceptionHandler::class) resolution and tear it down afterwards.
    $bindHandler = function (object $handler): WorkerProcess {
        $container = new Container;
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        return new WorkerProcess(['redis' => ['uri' => 'redis://127.0.0.1:6379']]);
    };

    $invoke = function (WorkerProcess $worker, Throwable $e): bool {
        $method = new ReflectionMethod(WorkerProcess::class, 'shouldStopRetries');

        return $method->invoke($worker, $e);
    };

    afterEach(fn () => Container::setInstance(null));

    it('stops retries when the handler returns true', function () use ($bindHandler, $invoke) {
        $handler = new class {
            public function shouldStopRetries(Throwable $e): bool { return true; }
        };

        expect($invoke($bindHandler($handler), new RuntimeException('nope')))->toBeTrue();
    });

    it('keeps retrying when the handler returns false', function () use ($bindHandler, $invoke) {
        $handler = new class {
            public function shouldStopRetries(Throwable $e): bool { return false; }
        };

        expect($invoke($bindHandler($handler), new RuntimeException('retry me')))->toBeFalse();
    });

    it('keeps retrying when the handler predates shouldStopRetries', function () use ($bindHandler, $invoke) {
        // A legacy handler (pre-Laravel v13.17.0) has no shouldStopRetries();
        // the method_exists guard must keep attempt-count behaviour.
        $handler = new class {};

        expect($invoke($bindHandler($handler), new RuntimeException('legacy')))->toBeFalse();
    });
});
