<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Webpatser\Torque\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Integration');

/**
 * A minimal authenticatable user for dashboard route tests.
 *
 * The dashboard middleware defaults to `['web', 'auth']`; authenticating with
 * this stub lets the auth middleware pass so the `viewTorque` gate is what
 * decides 200 vs 403 (rather than an auth redirect).
 */
function torqueTestUser(): Authenticatable
{
    return new class implements Authenticatable
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
