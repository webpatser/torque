<?php

declare(strict_types=1);

use Webpatser\Torque\Job\TorqueJob;

final class TorqueJobTestStub extends TorqueJob
{
    public function handle(): void {}
}

it('loads a subclass and defaults to the torque connection', function (): void {
    $job = new TorqueJobTestStub;

    expect($job->connection)->toBe('torque');
});

it('keeps the torque connection across serialization', function (): void {
    $job = unserialize(serialize(new TorqueJobTestStub));

    expect($job)->toBeInstanceOf(TorqueJobTestStub::class)
        ->and($job->connection)->toBe('torque');
});
