<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every dashboard request (shell + API) through the `viewTorque` ability.
 *
 * Applied to the whole dashboard route group so the JSON endpoints are not
 * reachable by users who would fail the gate on the shell route.
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
