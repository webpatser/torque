<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Artisan;

it('is registered as an artisan command', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('torque:monitor');
});

it('has the refresh option with default of 500ms', function () {
    $command = Artisan::all()['torque:monitor'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('refresh'))->toBeTrue();
    expect($definition->getOption('refresh')->getDefault())->toBe('500');
});
