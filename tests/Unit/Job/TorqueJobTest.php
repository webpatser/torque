<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Attributes\Connection;
use Webpatser\Torque\Job\TorqueJob;

final class TorqueJobTestStub extends TorqueJob
{
    public function handle(): void {}
}

/*
 * A subclass with its own constructor and no parent::__construct() call, the
 * way a plain Laravel job is written. The connection must still be torque.
 */
final class TorqueJobOwnConstructorStub extends TorqueJob
{
    public function __construct(public readonly int $documentId) {}

    public function handle(): void {}
}

/*
 * A subclass that names its own connection through the attribute.
 */
#[Connection('x')]
final class TorqueJobConnectionAttributeStub extends TorqueJob
{
    public function handle(): void {}
}

/*
 * A subclass that names its own connection through the property.
 */
final class TorqueJobConnectionPropertyStub extends TorqueJob
{
    public $connection = 'x';

    public function handle(): void {}
}

/**
 * Dispatch through a real bus dispatcher and return the connection name its
 * queue resolver was asked for. The queue mock is an external contract.
 */
function torqueJobDispatchedConnection(object $job): ?string
{
    $resolved = [];

    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('push')->once()->andReturn('job-id');

    $dispatcher = new Dispatcher(new Container, function (?string $connection) use (&$resolved, $queue): Queue {
        $resolved[] = $connection;

        return $queue;
    });

    $dispatcher->dispatchToQueue($job);

    expect($resolved)->toHaveCount(1);

    return $resolved[0];
}

it('loads a subclass', function (): void {
    expect(new TorqueJobTestStub)->toBeInstanceOf(TorqueJob::class);
});

it('dispatches a subclass with its own constructor to the torque connection', function (): void {
    expect(torqueJobDispatchedConnection(new TorqueJobOwnConstructorStub(42)))->toBe('torque');
});

it('lets an explicit onConnection() win over the torque default', function (): void {
    $job = (new TorqueJobOwnConstructorStub(42))->onConnection('redis');

    expect(torqueJobDispatchedConnection($job))->toBe('redis');
});

it('lets a subclass #[Connection] attribute win over the torque default', function (): void {
    expect(torqueJobDispatchedConnection(new TorqueJobConnectionAttributeStub))->toBe('x');
});

it('lets a subclass $connection property win over the torque default', function (): void {
    expect(torqueJobDispatchedConnection(new TorqueJobConnectionPropertyStub))->toBe('x');
});

it('keeps dispatching to the torque connection after serialization', function (): void {
    $job = unserialize(serialize(new TorqueJobOwnConstructorStub(42)));

    expect($job)->toBeInstanceOf(TorqueJobOwnConstructorStub::class)
        ->and($job->documentId)->toBe(42)
        ->and(torqueJobDispatchedConnection($job))->toBe('torque');
});
