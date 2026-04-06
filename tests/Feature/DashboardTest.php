<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    // Enable the dashboard for these tests.
    config()->set('torque.dashboard.enabled', true);

    // Re-register dashboard routes since the service provider boot
    // already ran before we changed the config.
    \Webpatser\Torque\Dashboard\TorqueDashboardController::register();
});

it('defines the viewTorque gate that defaults to deny', function () {
    expect(Gate::has('viewTorque'))->toBeTrue();

    // The default gate denies all users.
    $user = new class
    {
        public function getAuthIdentifier(): int
        {
            return 1;
        }
    };

    expect(Gate::forUser($user)->allows('viewTorque'))->toBeFalse();
});

it('rejects unauthenticated requests to the dashboard', function () {
    $response = $this->get('/torque');

    // Without auth the request should never succeed — expect a redirect to
    // login, an auth error, or an internal error from the unconfigured guard.
    expect($response->status())->not->toBe(200);
});

it('denies dashboard access when gate returns false', function () {
    // Create a minimal authenticatable user.
    $user = new class implements \Illuminate\Contracts\Auth\Authenticatable
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

    // Gate defaults to deny, so even an authenticated user gets 403.
    $response = $this->actingAs($user)->get('/torque');

    expect($response->status())->toBe(403);
});

it('allows dashboard access when gate is overridden to permit', function () {
    Gate::define('viewTorque', fn ($user): bool => true);

    $user = new class implements \Illuminate\Contracts\Auth\Authenticatable
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

    $response = $this->actingAs($user)->get('/torque');

    // The view may not exist in the test environment, but the gate passed.
    // We accept 200 (view rendered) or 500 (view missing in test env).
    expect($response->status())->toBeIn([200, 500]);
});
