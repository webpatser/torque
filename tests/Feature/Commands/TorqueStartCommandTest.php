<?php

declare(strict_types=1);

it('is registered as an artisan command', function () {
    $this->artisan('list')
        ->assertSuccessful();

    $commands = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all());

    expect($commands->has('torque:start'))->toBeTrue();
});

it('rejects invalid queue names with path traversal', function () {
    $this->artisan('torque:start', ['--queues' => '../../etc'])
        ->assertFailed()
        ->expectsOutputToContain('Invalid queue name');
});

it('rejects queue names with spaces', function () {
    $this->artisan('torque:start', ['--queues' => 'valid, in valid'])
        ->assertFailed()
        ->expectsOutputToContain('Invalid queue name');
});

it('rejects queue names with special characters', function () {
    $this->artisan('torque:start', ['--queues' => 'queue;rm -rf /'])
        ->assertFailed()
        ->expectsOutputToContain('Invalid queue name');
});
