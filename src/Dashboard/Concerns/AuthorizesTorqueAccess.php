<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Enforces the `viewTorque` gate on every Livewire lifecycle request.
 *
 * Mount guards the initial render; hydrate guards every subsequent action
 * (wire:click, wire:poll, wire:model). Without this, route middleware is
 * only checked once, leaving Livewire action endpoints open to any user
 * with the `web` session.
 */
trait AuthorizesTorqueAccess
{
    public function bootAuthorizesTorqueAccess(): void
    {
        Gate::authorize('viewTorque');
    }
}
