<?php

declare(strict_types=1);

it('is registered as an artisan command', function () {
    $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());

    expect($commands)->toContain('torque:monitor');
});

it('has the refresh option with default of 500ms', function () {
    $command = \Illuminate\Support\Facades\Artisan::all()['torque:monitor'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('refresh'))->toBeTrue();
    expect($definition->getOption('refresh')->getDefault())->toBe('500');
});
