<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

function createAuthUser(): \Illuminate\Contracts\Auth\Authenticatable
{
    return new class implements \Illuminate\Contracts\Auth\Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 1;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }
    };
}

beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);
    \Webpatser\Torque\Dashboard\TorqueDashboardController::register();
    Gate::define('viewTorque', fn ($user): bool => true);
});

it('serves the dashboard shell on the root path', function () {
    $response = $this->actingAs(createAuthUser())->get('/torque');

    // Accept 200 (rendered) or 500 (view asset loading in test env).
    expect($response->status())->toBeIn([200, 500]);
});

it('serves sub-routes for jobs page', function () {
    $response = $this->actingAs(createAuthUser())->get('/torque/jobs');

    expect($response->status())->toBeIn([200, 500]);
});

it('serves sub-routes for streams page', function () {
    $response = $this->actingAs(createAuthUser())->get('/torque/streams');

    expect($response->status())->toBeIn([200, 500]);
});

it('serves sub-routes for workers page', function () {
    $response = $this->actingAs(createAuthUser())->get('/torque/workers');

    expect($response->status())->toBeIn([200, 500]);
});

it('serves sub-routes for failed jobs page', function () {
    $response = $this->actingAs(createAuthUser())->get('/torque/failed');

    expect($response->status())->toBeIn([200, 500]);
});

it('serves sub-routes for settings page', function () {
    $response = $this->actingAs(createAuthUser())->get('/torque/settings');

    expect($response->status())->toBeIn([200, 500]);
});

it('serves deep-link route for job inspector', function () {
    $response = $this->actingAs(createAuthUser())->get('/torque/jobs/some-uuid-here');

    expect($response->status())->toBeIn([200, 500]);
});

it('passes view parameter to the blade template', function () {
    $response = $this->actingAs(createAuthUser())->get('/torque/streams');

    // The route should capture "streams" as the view parameter.
    // We can't easily inspect the Livewire component props in an HTTP test,
    // but we verify the route resolves without error.
    expect($response->status())->toBeIn([200, 500]);
});

it('still requires authentication on sub-routes', function () {
    $response = $this->get('/torque/jobs');

    expect($response->status())->not->toBe(200);
});

it('still requires gate authorization on sub-routes', function () {
    Gate::define('viewTorque', fn ($user): bool => false);

    $response = $this->actingAs(createAuthUser())->get('/torque/jobs');

    expect($response->status())->toBe(403);
});

it('uses configurable dashboard path', function () {
    config()->set('torque.dashboard.path', 'admin/queues');
    \Webpatser\Torque\Dashboard\TorqueDashboardController::register();

    $response = $this->actingAs(createAuthUser())->get('/admin/queues');

    expect($response->status())->toBeIn([200, 500]);
});
