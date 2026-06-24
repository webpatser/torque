<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Webpatser\Torque\Dashboard\TorqueDashboardController;

beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);

    // The service provider boots (and registers routes) before the test body
    // can tweak config, so re-register to pick up the enabled flag / path.
    TorqueDashboardController::register();
});

it('defines the viewTorque gate that defaults to deny outside local', function () {
    expect(Gate::has('viewTorque'))->toBeTrue();

    // The default gate only allows the local environment; the test env denies.
    expect(Gate::forUser(torqueTestUser())->allows('viewTorque'))->toBeFalse();
});

it('serves the Livewire overview on the dashboard root', function () {
    Gate::define('viewTorque', fn ($user): bool => true);

    $response = $this->actingAs(torqueTestUser())->get('/torque');

    $response->assertOk();

    // The Overview full-page component rendered inside the Torque layout.
    expect($response->getContent())
        ->toContain('keeps spinning')      // brand tag from the shell
        ->toContain('Cluster throughput')  // overview-specific copy
        ->toContain('wire:'); // Livewire directives present
});

it('serves the inspector for a job deep link', function () {
    Gate::define('viewTorque', fn ($user): bool => true);

    $response = $this->actingAs(torqueTestUser())->get('/torque/jobs/abc');

    $response->assertOk();

    // The Inspector mounts with the uuid and shows the per-job detail chrome.
    expect($response->getContent())->toContain('Event stream');
});

it('serves the workers screen', function () {
    Gate::define('viewTorque', fn ($user): bool => true);

    $this->actingAs(torqueTestUser())
        ->get('/torque/workers')
        ->assertOk()
        ->assertSee('Active workers');
});

it('denies the dashboard when the gate denies', function () {
    Gate::define('viewTorque', fn ($user): bool => false);

    $this->actingAs(torqueTestUser())
        ->get('/torque')
        ->assertForbidden();
});

it('denies a sub-screen when the gate denies', function () {
    Gate::define('viewTorque', fn ($user): bool => false);

    $this->actingAs(torqueTestUser())
        ->get('/torque/dead')
        ->assertForbidden();
});

it('requires authentication on the dashboard', function () {
    Gate::define('viewTorque', fn ($user): bool => true);

    // Unauthenticated: the auth middleware blocks before the gate runs.
    expect($this->get('/torque')->status())->not->toBe(200);
});

it('honors a configurable dashboard path', function () {
    config()->set('torque.dashboard.path', 'admin/queues');
    TorqueDashboardController::register();

    Gate::define('viewTorque', fn ($user): bool => true);

    $this->actingAs(torqueTestUser())
        ->get('/admin/queues')
        ->assertOk();
});
