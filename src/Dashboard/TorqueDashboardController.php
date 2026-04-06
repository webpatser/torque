<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Registers routes and authorization for the Torque dashboard.
 *
 * The dashboard is a single-page application served by a catch-all route.
 * Auth is gated via `viewTorque` — applications should override this gate
 * in their AuthServiceProvider to restrict access.
 */
final class TorqueDashboardController
{
    /**
     * Register the dashboard routes and authorization gate.
     *
     * Called from {@see \Webpatser\Torque\TorqueServiceProvider::boot()} when
     * the dashboard is enabled in config.
     */
    public static function register(): void
    {
        static::defineGate();
        static::registerRoutes();
    }

    /**
     * Define the `viewTorque` authorization gate.
     *
     * Defaults to DENYING all access. Applications MUST override this gate
     * in their AuthServiceProvider to grant dashboard access:
     *
     *     Gate::define('viewTorque', fn (User $user) => $user->isAdmin());
     */
    private static function defineGate(): void
    {
        if (!Gate::has('viewTorque')) {
            Gate::define('viewTorque', static fn ($user): bool => false);
        }
    }

    /**
     * Register the catch-all dashboard route.
     *
     * The `{view?}` parameter with a `(.*)` constraint allows the SPA
     * to handle client-side routing while the server always returns the
     * same Blade shell.
     */
    private static function registerRoutes(): void
    {
        Route::prefix(config('torque.dashboard.path', 'torque'))
            ->middleware(config('torque.dashboard.middleware', ['web', 'auth']))
            ->group(function (): void {
                Route::get('/{view?}', static function () {
                    Gate::authorize('viewTorque');

                    return view('torque::dashboard');
                })->where('view', '(.*)')->name('torque.dashboard');
            });
    }
}
