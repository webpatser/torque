<?php

declare(strict_types=1);

use Webpatser\Torque\Control\PauseScopes;

/*
 * `torque:pause` against real Redis under a random prefix. Without options
 * it is the global pause as before; `--node` and `--queue` write scoped
 * pauses through PauseScopes; `--all` lifts everything on continue.
 */

beforeEach(function () {
    $this->redis = torqueRedis();
    $this->prefix = 'torque-test-pause-'.bin2hex(random_bytes(4)).':';
    $this->pausedKey = $this->prefix.'paused';
    $this->pausesKey = PauseScopes::key($this->prefix);
    $this->streams = ['default', 'emails', 'imports'];

    config()->set('torque.redis.uri', torqueRedisUri());
    config()->set('torque.redis.prefix', $this->prefix);
    config()->set('torque.streams', array_fill_keys($this->streams, []));

    $this->scopes = new PauseScopes($this->redis, $this->prefix);

    $this->breakerKey = fn (string $queue): string => $this->prefix.'cb:'.$queue.':state';

    $this->globalPaused = fn (): bool => (int) $this->redis->execute('EXISTS', $this->pausedKey) === 1;
});

afterEach(function () {
    $keys = [$this->pausedKey, $this->pausesKey];

    foreach ($this->streams as $queue) {
        foreach (['state', 'probes', 'window'] as $suffix) {
            $keys[] = $this->prefix.'cb:'.$queue.':'.$suffix;
        }
    }

    $this->redis->execute('DEL', ...$keys);
});

describe('global pause', function () {
    it('pauses by setting the Redis key', function () {
        $this->artisan('torque:pause', ['action' => 'pause'])->assertSuccessful();

        expect(($this->globalPaused)())->toBeTrue();
    });

    it('continues by removing the Redis key', function () {
        $this->redis->execute('SET', $this->pausedKey, (string) time());

        $this->artisan('torque:pause', ['action' => 'continue'])
            ->assertSuccessful()
            ->expectsOutputToContain('Torque resumed');

        expect(($this->globalPaused)())->toBeFalse();
    });

    it('toggles on when running and off when paused', function () {
        $this->artisan('torque:pause', ['action' => 'toggle'])->assertSuccessful();
        expect(($this->globalPaused)())->toBeTrue();

        $this->artisan('torque:pause', ['action' => 'toggle'])->assertSuccessful();
        expect(($this->globalPaused)())->toBeFalse();
    });

    it('rejects an invalid action', function () {
        $this->artisan('torque:pause', ['action' => 'invalid'])
            ->assertFailed()
            ->expectsOutputToContain('Invalid action');
    });

    it('reports already paused when pause is called twice', function () {
        $this->artisan('torque:pause', ['action' => 'pause']);

        $this->artisan('torque:pause', ['action' => 'pause'])
            ->assertSuccessful()
            ->expectsOutputToContain('already paused');
    });

    it('reports already running when continue is called while not paused', function () {
        $this->artisan('torque:pause', ['action' => 'continue'])
            ->assertSuccessful()
            ->expectsOutputToContain('already running');
    });

    it('closes open circuit breakers on continue', function () {
        $this->redis->execute('SET', ($this->breakerKey)('emails'), '1');

        $this->artisan('torque:pause', ['action' => 'continue'])
            ->assertSuccessful()
            ->expectsOutputToContain('Closed the circuit breaker on: emails');

        expect((int) $this->redis->execute('EXISTS', ($this->breakerKey)('emails')))->toBe(0);
    });

    it('leaves node and queue pauses alone', function () {
        $this->scopes->pauseNode('web-1');
        $this->scopes->pauseQueue('emails');

        $this->artisan('torque:pause', ['action' => 'pause'])->assertSuccessful();
        $this->artisan('torque:pause', ['action' => 'continue'])->assertSuccessful();

        $all = $this->scopes->all();

        expect(array_keys($all['nodes']))->toBe(['web-1'])
            ->and(array_keys($all['queues']))->toBe(['emails']);
    });
});

describe('node scope', function () {
    it('pauses a node without touching the global key', function () {
        $this->artisan('torque:pause', ['action' => 'pause', '--node' => ['web-1']])
            ->assertSuccessful()
            ->expectsOutputToContain('Node [web-1] paused');

        expect(array_keys($this->scopes->all()['nodes']))->toBe(['web-1'])
            ->and(($this->globalPaused)())->toBeFalse();
    });

    it('reports a node that is already paused', function () {
        $this->scopes->pauseNode('web-1');

        $this->artisan('torque:pause', ['action' => 'pause', '--node' => ['web-1']])
            ->assertSuccessful()
            ->expectsOutputToContain('Node [web-1] is already paused');
    });

    it('continues a node', function () {
        $this->scopes->pauseNode('web-1');
        $this->scopes->pauseNode('web-2');

        $this->artisan('torque:pause', ['action' => 'continue', '--node' => ['web-1']])
            ->assertSuccessful()
            ->expectsOutputToContain('Node [web-1] resumed');

        expect(array_keys($this->scopes->all()['nodes']))->toBe(['web-2']);
    });

    it('reports a node that is already running', function () {
        $this->artisan('torque:pause', ['action' => 'continue', '--node' => ['web-1']])
            ->assertSuccessful()
            ->expectsOutputToContain('Node [web-1] is already running');
    });

    it('toggles each node on its own state', function () {
        $this->scopes->pauseNode('web-1');

        $this->artisan('torque:pause', ['action' => 'toggle', '--node' => ['web-1', 'web-2']])
            ->assertSuccessful()
            ->expectsOutputToContain('Node [web-1] resumed')
            ->expectsOutputToContain('Node [web-2] paused');

        expect(array_keys($this->scopes->all()['nodes']))->toBe(['web-2']);
    });

    it('accepts comma lists and repeated options', function () {
        $this->artisan('torque:pause', ['action' => 'pause', '--node' => ['web-1,web-2', 'batch-1']])
            ->assertSuccessful();

        expect(array_keys($this->scopes->all()['nodes']))->toBe(['batch-1', 'web-1', 'web-2']);
    });

    it('does not close breakers on a node continue', function () {
        $this->redis->execute('SET', ($this->breakerKey)('emails'), '1');
        $this->scopes->pauseNode('web-1');

        $this->artisan('torque:pause', ['action' => 'continue', '--node' => ['web-1']])->assertSuccessful();

        expect((int) $this->redis->execute('EXISTS', ($this->breakerKey)('emails')))->toBe(1);
    });
});

describe('queue scope', function () {
    it('pauses a queue without touching the global key', function () {
        $this->artisan('torque:pause', ['action' => 'pause', '--queue' => ['emails']])
            ->assertSuccessful()
            ->expectsOutputToContain('Queue [emails] paused');

        expect(array_keys($this->scopes->all()['queues']))->toBe(['emails'])
            ->and(($this->globalPaused)())->toBeFalse();
    });

    it('reports a queue that is already paused', function () {
        $this->scopes->pauseQueue('emails');

        $this->artisan('torque:pause', ['action' => 'pause', '--queue' => ['emails']])
            ->assertSuccessful()
            ->expectsOutputToContain('Queue [emails] is already paused');
    });

    it('continues a queue', function () {
        $this->scopes->pauseQueue('emails');
        $this->scopes->pauseQueue('imports');

        $this->artisan('torque:pause', ['action' => 'continue', '--queue' => ['emails']])
            ->assertSuccessful()
            ->expectsOutputToContain('Queue [emails] resumed');

        expect(array_keys($this->scopes->all()['queues']))->toBe(['imports']);
    });

    it('reports a queue that is already running', function () {
        $this->artisan('torque:pause', ['action' => 'continue', '--queue' => ['emails']])
            ->assertSuccessful()
            ->expectsOutputToContain('Queue [emails] is already running');
    });

    it('toggles each queue on its own state', function () {
        $this->scopes->pauseQueue('emails');

        $this->artisan('torque:pause', ['action' => 'toggle', '--queue' => ['emails,imports']])
            ->assertSuccessful()
            ->expectsOutputToContain('Queue [emails] resumed')
            ->expectsOutputToContain('Queue [imports] paused');

        expect(array_keys($this->scopes->all()['queues']))->toBe(['imports']);
    });

    it('accepts comma lists and repeated options', function () {
        $this->artisan('torque:pause', ['action' => 'pause', '--queue' => ['emails, imports', 'default']])
            ->assertSuccessful();

        expect(array_keys($this->scopes->all()['queues']))->toBe(['default', 'emails', 'imports']);
    });

    it('handles nodes and queues in one call', function () {
        $this->artisan('torque:pause', ['action' => 'pause', '--node' => ['web-1'], '--queue' => ['emails']])
            ->assertSuccessful();

        $all = $this->scopes->all();

        expect(array_keys($all['nodes']))->toBe(['web-1'])
            ->and(array_keys($all['queues']))->toBe(['emails']);
    });
});

describe('refused commands', function () {
    it('refuses an invalid node name and writes nothing', function (array $nodes) {
        $this->artisan('torque:pause', ['action' => 'pause', '--node' => $nodes, '--queue' => ['emails']])
            ->assertFailed()
            ->expectsOutputToContain('Nothing was changed');

        expect((int) $this->redis->execute('EXISTS', $this->pausesKey))->toBe(0)
            ->and(($this->globalPaused)())->toBeFalse();
    })->with([
        'bad characters' => [['web-1', 'web 2']],
        'too long' => [[str_repeat('a', 49)]],
        'empty entry in a comma list' => [['web-1,,web-2']],
    ]);

    it('refuses an unknown queue and writes nothing', function () {
        $this->artisan('torque:pause', ['action' => 'pause', '--node' => ['web-1'], '--queue' => ['emails,nope']])
            ->assertFailed()
            ->expectsOutputToContain('Nothing was changed: unknown queue [nope]');

        expect((int) $this->redis->execute('EXISTS', $this->pausesKey))->toBe(0);
    });

    it('refuses --all together with a scope and writes nothing', function (string $action, array $scope) {
        $this->scopes->pauseNode('web-1');
        $this->redis->execute('SET', $this->pausedKey, (string) time());

        $this->artisan('torque:pause', ['action' => $action, '--all' => true, ...$scope])
            ->assertFailed()
            ->expectsOutputToContain('--all cannot be combined');

        expect(array_keys($this->scopes->all()['nodes']))->toBe(['web-1'])
            ->and(($this->globalPaused)())->toBeTrue();
    })->with([
        'continue with node' => ['continue', ['--node' => ['web-2']]],
        'continue with queue' => ['continue', ['--queue' => ['emails']]],
        'pause with node' => ['pause', ['--node' => ['web-2']]],
    ]);

    it('refuses toggle --all', function () {
        $this->artisan('torque:pause', ['action' => 'toggle', '--all' => true])
            ->assertFailed()
            ->expectsOutputToContain('not toggle');

        expect(($this->globalPaused)())->toBeFalse();
    });
});

describe('--all', function () {
    it('lifts the global, node and queue pauses and closes breakers on continue', function () {
        $this->redis->execute('SET', $this->pausedKey, (string) time());
        $this->scopes->pauseNode('web-1');
        $this->scopes->pauseQueue('emails');
        $this->redis->execute('SET', ($this->breakerKey)('imports'), '1');

        $this->artisan('torque:pause', ['action' => 'continue', '--all' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Global pause lifted')
            ->expectsOutputToContain('Node [web-1] resumed')
            ->expectsOutputToContain('Queue [emails] resumed')
            ->expectsOutputToContain('Closed the circuit breaker on: imports');

        expect(($this->globalPaused)())->toBeFalse()
            ->and((int) $this->redis->execute('EXISTS', $this->pausesKey))->toBe(0)
            ->and((int) $this->redis->execute('EXISTS', ($this->breakerKey)('imports')))->toBe(0);
    });

    it('reports when nothing was paused on continue', function () {
        $this->artisan('torque:pause', ['action' => 'continue', '--all' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Global pause was not set')
            ->expectsOutputToContain('No node or queue was paused');
    });

    it('is the global pause on pause', function () {
        $this->artisan('torque:pause', ['action' => 'pause', '--all' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Torque paused');

        expect(($this->globalPaused)())->toBeTrue()
            ->and((int) $this->redis->execute('EXISTS', $this->pausesKey))->toBe(0);
    });
});
