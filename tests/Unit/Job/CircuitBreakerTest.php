<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Webpatser\Torque\Events\QueueCircuitClosed;
use Webpatser\Torque\Events\QueueCircuitOpened;
use Webpatser\Torque\Job\CircuitBreaker;

/*
 * The breaker is Redis-backed on purpose: workers are separate processes, so
 * the sliding window and the open/half-open state have to be shared. These
 * tests drive the real state machine against the test Redis.
 *
 * Half-open is "state key expired, probes key still there". Deleting the state
 * key is exactly what its TTL does, so most transitions are driven that way;
 * one test waits out a real one-second cooldown to prove the TTL itself
 * produces the half-open state.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-cb-'.bin2hex(random_bytes(4)).':';
    $this->events = new Dispatcher;
    $this->dispatched = [];

    $this->events->listen(QueueCircuitOpened::class, function (QueueCircuitOpened $event) {
        $this->dispatched[] = $event;
    });
    $this->events->listen(QueueCircuitClosed::class, function (QueueCircuitClosed $event) {
        $this->dispatched[] = $event;
    });

    $this->breaker = function (array $config = [], array $streams = []): CircuitBreaker {
        return new CircuitBreaker(
            redisUri: torqueRedisUri(),
            prefix: $this->prefix,
            config: array_merge([
                'enabled' => true,
                'window' => 100,
                'min_samples' => 20,
                'threshold' => 0.9,
                'cooldown' => 300,
                'half_open_max' => 5,
                'retention' => 3600,
            ], $config),
            streams: $streams,
            events: $this->events,
            logger: fn () => null,
        );
    };

    $this->key = fn (string $suffix): string => $this->prefix.'cb:default:'.$suffix;
});

afterEach(function () {
    foreach ($this->redis->execute('KEYS', $this->prefix.'*') as $key) {
        $this->redis->execute('DEL', (string) $key);
    }
});

it('does not trip below min_samples', function () {
    $breaker = ($this->breaker)();

    for ($i = 0; $i < 19; $i++) {
        $breaker->recordFailure('default');
    }

    expect($breaker->state('default'))->toBeNull()
        ->and($this->dispatched)->toBe([])
        ->and((int) $this->redis->execute('LLEN', ($this->key)('window')))->toBe(19);
});

it('opens once the failure ratio crosses the threshold', function () {
    $breaker = ($this->breaker)();

    for ($i = 0; $i < 20; $i++) {
        $breaker->recordFailure('default');
    }

    $state = $breaker->state('default');

    expect($state['state'])->toBe('open')
        ->and($state['resumes_at'])->toBeGreaterThan(time())
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->dispatched[0])->toBeInstanceOf(QueueCircuitOpened::class)
        ->and($this->dispatched[0]->queue)->toBe('default')
        ->and($this->dispatched[0]->failures)->toBe(20)
        ->and($this->dispatched[0]->ratio)->toBe(1.0);
});

it('stays closed while successes keep the ratio under the threshold', function () {
    $breaker = ($this->breaker)(['min_samples' => 10, 'threshold' => 0.9]);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordSuccess('default');
    }

    for ($i = 0; $i < 15; $i++) {
        $breaker->recordFailure('default');
    }

    expect($breaker->state('default'))->toBeNull();
});

it('gives the state key the cooldown as its ttl and resets the window', function () {
    $breaker = ($this->breaker)(['cooldown' => 120]);

    for ($i = 0; $i < 20; $i++) {
        $breaker->recordFailure('default');
    }

    expect((int) $this->redis->execute('TTL', ($this->key)('state')))->toBeGreaterThan(110)
        ->and((int) $this->redis->execute('TTL', ($this->key)('state')))->toBeLessThanOrEqual(120)
        ->and((int) $this->redis->execute('EXISTS', ($this->key)('window')))->toBe(0)
        ->and((int) $this->redis->execute('TTL', ($this->key)('probes')))->toBeGreaterThan(120);
});

it('goes half-open when the cooldown lapses', function () {
    $breaker = ($this->breaker)(['cooldown' => 1]);

    for ($i = 0; $i < 20; $i++) {
        $breaker->recordFailure('default');
    }

    expect($breaker->state('default')['state'])->toBe('open');

    usleep(1_200_000);

    expect($breaker->state('default'))->toBe(['state' => 'half-open', 'resumes_at' => null]);
});

it('re-opens when every half-open probe fails', function () {
    $breaker = ($this->breaker)(['half_open_max' => 3]);

    for ($i = 0; $i < 20; $i++) {
        $breaker->recordFailure('default');
    }

    // The cooldown lapsing is what makes it half-open.
    $this->redis->execute('DEL', ($this->key)('state'));
    $this->dispatched = [];

    $breaker->recordFailure('default');
    $breaker->recordFailure('default');

    expect($breaker->state('default')['state'])->toBe('half-open')
        ->and($this->dispatched)->toBe([]);

    $breaker->recordFailure('default');

    expect($breaker->state('default')['state'])->toBe('open')
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->dispatched[0])->toBeInstanceOf(QueueCircuitOpened::class);
});

it('closes on the first successful half-open probe and resets everything', function () {
    $breaker = ($this->breaker)();

    for ($i = 0; $i < 20; $i++) {
        $breaker->recordFailure('default');
    }

    $this->redis->execute('DEL', ($this->key)('state'));
    $this->dispatched = [];

    $breaker->recordFailure('default');
    $breaker->recordSuccess('default');

    expect($breaker->state('default'))->toBeNull()
        ->and($this->redis->execute('KEYS', $this->prefix.'cb:default:*'))->toBe([])
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->dispatched[0])->toBeInstanceOf(QueueCircuitClosed::class)
        ->and($this->dispatched[0]->reason)->toBe('probe');
});

it('honours a per-stream override', function () {
    $breaker = ($this->breaker)([], ['default' => ['circuit_breaker' => ['min_samples' => 5, 'threshold' => 0.5]]]);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure('default');
    }

    expect($breaker->state('default')['state'])->toBe('open')
        ->and($breaker->settingsFor('default'))
        ->toMatchArray(['min_samples' => 5, 'threshold' => 0.5, 'cooldown' => 300]);
});

it('records nothing for a stream that opted out', function () {
    $breaker = ($this->breaker)([], ['default' => ['circuit_breaker' => false]]);

    for ($i = 0; $i < 50; $i++) {
        $breaker->recordFailure('default');
    }

    expect($breaker->settingsFor('default'))->toBeNull()
        ->and($breaker->state('default'))->toBeNull()
        ->and($breaker->openQueues(['default']))->toBe([])
        ->and($this->redis->execute('KEYS', $this->prefix.'cb:*'))->toBe([]);
});

it('records nothing at all when disabled globally', function () {
    $breaker = ($this->breaker)(['enabled' => false]);

    for ($i = 0; $i < 50; $i++) {
        $breaker->recordFailure('default');
    }

    expect($breaker->state('default'))->toBeNull()
        ->and($this->redis->execute('KEYS', $this->prefix.'cb:*'))->toBe([]);
});

it('force-closes an open breaker and reports which streams had one', function () {
    $breaker = ($this->breaker)();

    for ($i = 0; $i < 20; $i++) {
        $breaker->recordFailure('default');
    }

    $this->dispatched = [];

    expect($breaker->forceCloseAll(['default', 'other']))->toBe(['default'])
        ->and($breaker->state('default'))->toBeNull()
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->dispatched[0]->reason)->toBe('manual');
});

it('resolves per-stream config precedence without touching redis', function () {
    $global = ['enabled' => true, 'threshold' => 0.9, 'cooldown' => 300];

    expect(CircuitBreaker::resolveConfig($global, null))
        ->toMatchArray(['threshold' => 0.9, 'cooldown' => 300, 'window' => 100])
        ->and(CircuitBreaker::resolveConfig($global, ['threshold' => 0.5]))
        ->toMatchArray(['threshold' => 0.5, 'cooldown' => 300])
        ->and(CircuitBreaker::resolveConfig($global, false))->toBeNull()
        ->and(CircuitBreaker::resolveConfig(['enabled' => false], ['threshold' => 0.5]))->toBeNull()
        ->and(CircuitBreaker::resolveConfig(['enabled' => false], ['enabled' => true]))
        ->toMatchArray(['enabled' => true]);
});
