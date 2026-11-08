<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Webpatser\Torque\Dashboard\Http\Middleware\Authorize;
use Webpatser\Torque\Dashboard\Http\Middleware\DetectCspMismatch;
use Webpatser\Torque\Dashboard\Livewire\Batches;
use Webpatser\Torque\Dashboard\Livewire\Dead;
use Webpatser\Torque\Dashboard\Livewire\Feed;
use Webpatser\Torque\Dashboard\Livewire\Inspector;
use Webpatser\Torque\Dashboard\Livewire\Jobs;
use Webpatser\Torque\Dashboard\Livewire\Overview;
use Webpatser\Torque\Dashboard\Livewire\Queues;
use Webpatser\Torque\Dashboard\Livewire\Workers;
use Webpatser\Torque\Support\Narrow;
use Webpatser\Torque\TorqueServiceProvider;

/**
 * Registers routes and authorization for the Torque dashboard.
 *
 * The dashboard is a set of full-page Livewire 4 components sharing a single
 * Blade layout. Access is gated via `viewTorque`; applications should override
 * this gate in their AuthServiceProvider to restrict access.
 */
final class TorqueDashboardController
{
    /**
     * Register the dashboard routes and authorization gate.
     *
     * Called from {@see TorqueServiceProvider::boot()} when
     * the dashboard is enabled in config.
     */
    public static function register(): void
    {
        self::defineGate();
        self::registerRoutes();
    }

    /**
     * Define the `viewTorque` authorization gate, if nothing has yet.
     *
     * {@see TorqueServiceProvider::registerDefaultGate()} runs first and allows
     * the `local` environment only, so this deny-all is the fallback for a call
     * without it. Applications override the gate in their AuthServiceProvider
     * to grant dashboard access elsewhere:
     *
     *     Gate::define('viewTorque', fn (User $user) => $user->isAdmin());
     */
    private static function defineGate(): void
    {
        if (! Gate::has('viewTorque')) {
            Gate::define('viewTorque', static fn ($user = null): bool => false);
        }
    }

    /**
     * Register the full-page Livewire routes, all gated by `viewTorque` via the
     * {@see Authorize} middleware.
     */
    private static function registerRoutes(): void
    {
        $middleware = config('torque.dashboard.middleware', ['web', 'auth']);

        Route::prefix(Narrow::string(config('torque.dashboard.path', 'torque'), 'torque'))
            ->middleware(is_string($middleware) ? $middleware : array_values(array_filter(Narrow::list($middleware), is_string(...))))
            ->group(static function (): void {
                Route::middleware([Authorize::class, DetectCspMismatch::class])->group(static function (): void {
                    Route::get('/', Overview::class)->name('torque.overview');
                    Route::get('workers', Workers::class)->name('torque.workers');
                    Route::get('queues', Queues::class)->name('torque.queues');
                    Route::get('feed', Feed::class)->name('torque.feed');
                    Route::get('inspector', Inspector::class)->name('torque.inspector');
                    Route::get('jobs', Jobs::class)->name('torque.jobs');
                    Route::get('jobs/{uuid}', Inspector::class)->name('torque.inspector.job');
                    Route::get('dead', Dead::class)->name('torque.dead');
                    Route::get('batches', Batches::class)->name('torque.batches');
                });
            });
    }
}
