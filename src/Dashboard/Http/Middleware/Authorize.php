<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every dashboard route through the `viewTorque` ability.
 *
 * Applied to the whole dashboard route group. Livewire does not run it again on
 * update requests, so the components check the gate themselves, in
 * `WithDashboardChrome`.
 */
final class Authorize
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Gate::authorize('viewTorque');

        return $next($request);
    }
}
