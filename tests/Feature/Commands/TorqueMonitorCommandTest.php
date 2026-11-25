<?php

declare(strict_types=1);
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Webpatser\Torque\Console\TorqueMonitorCommand;
use Webpatser\Torque\Control\MasterRegistry;
use Webpatser\Torque\Control\PauseScopes;
use Webpatser\Torque\Metrics\MetricsPublisher;
use Webpatser\Torque\Support\NodeName;

/**
 * Render one monitor frame against a private prefix and return its text.
 *
 * @param  callable(string): void  $arrange  Receives the prefix before the frame renders.
 */
function monitorFrame(string $prefix, callable $arrange): string
{
    config()->set('torque.redis.prefix', $prefix);

    try {
        $arrange($prefix);

        $buffer = new BufferedOutput;
        $command = new TorqueMonitorCommand;
        $command->setLaravel(app());

        $setProperty = static function (string $name, mixed $value) use ($command): void {
            $property = new ReflectionProperty($command, $name);
            $property->setValue($command, $value);
        };

        $setProperty('input', new ArrayInput(['--refresh' => 500], $command->getDefinition()));
        $setProperty('output', new OutputStyle(new ArrayInput([]), $buffer));
        $setProperty('startedAt', time());

        $render = new ReflectionMethod($command, 'render');
        $render->invoke(
            $command,
            new MetricsPublisher((string) config('torque.redis.uri'), $prefix),
            (array) config('torque'),
            $prefix,
            (string) config('torque.redis.uri'),
        );

        return $buffer->fetch();
    } finally {
        $redis = torqueRedis();

        foreach ((array) $redis->execute('KEYS', $prefix.'*') as $key) {
            $redis->execute('DEL', (string) $key);
        }

        config()->set('torque.redis.prefix', 'torque-test:');
        NodeName::resetForTesting();
    }
}

function monitorRecord(string $node, int $pid): array
{
    return [
        'node' => $node,
        'pid' => $pid,
        'started_at' => time(),
        'version' => '2.5.0',
        'supervised' => true,
        'queues' => ['default'],
        'workers' => 1,
        'slots' => 10,
        'state' => 'running',
    ];
}

it('is registered as an artisan command', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('torque:monitor');
});

it('shows the number of distinct nodes with a live master', function () {
    $prefix = 'torque-test-monitor-'.bin2hex(random_bytes(4)).':';
    NodeName::resetForTesting();

    $frame = monitorFrame($prefix, function (string $prefix): void {
        $registry = new MasterRegistry(torqueRedis(), $prefix);
        $registry->publish('web-1-4711-a1b2c3d4', monitorRecord('web-1', 4711));
        $registry->publish('web-1-4712-a1b2c3d5', monitorRecord('web-1', 4712));
        $registry->publish('batch-1-4812-b2c3d4e5', monitorRecord('batch-1', 4812));
    });

    expect($frame)->toContain('Nodes: ')->toMatch('/Nodes: \S*2\S*\s/u');
});

it('shows whether the local node is paused', function () {
    $prefix = 'torque-test-monitor-'.bin2hex(random_bytes(4)).':';
    NodeName::resetForTesting();
    $local = NodeName::setCurrent('monitor-local');

    $paused = monitorFrame($prefix, function (string $prefix) use ($local): void {
        new PauseScopes(torqueRedis(), $prefix)->pauseNode($local);
    });

    expect($paused)->toContain("This node ({$local}) paused:")
        ->toMatch('/paused: \S*Yes/u');

    NodeName::resetForTesting();
    NodeName::setCurrent('monitor-local');

    $running = monitorFrame($prefix, function (): void {
        // Nothing paused.
    });

    expect($running)->toMatch('/paused: \S*No/u');
});

it('does not report another node pause as the local one', function () {
    $prefix = 'torque-test-monitor-'.bin2hex(random_bytes(4)).':';
    NodeName::resetForTesting();
    NodeName::setCurrent('monitor-local');

    $frame = monitorFrame($prefix, function (string $prefix): void {
        new PauseScopes(torqueRedis(), $prefix)->pauseNode('some-other-node');
    });

    expect($frame)->toMatch('/This node \(monitor-local\) paused: \S*No/u');
});

it('has the refresh option with default of 500ms', function () {
    $command = Artisan::all()['torque:monitor'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('refresh'))->toBeTrue();
    expect($definition->getOption('refresh')->getDefault())->toBe('500');
});
