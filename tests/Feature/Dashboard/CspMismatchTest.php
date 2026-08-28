<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Webpatser\Torque\Dashboard\Http\Middleware\DetectCspMismatch;
use Webpatser\Torque\Dashboard\TorqueDashboardController;

/*
|--------------------------------------------------------------------------
| CSP self-check
|--------------------------------------------------------------------------
| A script-src without 'unsafe-eval' silently kills Alpine and wire:
| expressions unless livewire.csp_safe is on. The middleware turns that
| silent failure into a banner and a log line.
*/

beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);
    TorqueDashboardController::register();
    Gate::define('viewTorque', fn ($user): bool => true);
});

it('flags a script-src without unsafe-eval when csp_safe is off', function () {
    $message = DetectCspMismatch::mismatch("default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'", false);

    expect($message)->toContain("'unsafe-eval'")->toContain('csp_safe');
});

it('falls back to default-src when script-src is absent', function () {
    expect(DetectCspMismatch::mismatch("default-src 'self'", false))->not->toBeNull()
        ->and(DetectCspMismatch::mismatch("default-src 'self' 'unsafe-eval'", false))->toBeNull();
});

it('stays quiet when eval is allowed, when csp_safe is on, or without a policy', function () {
    expect(DetectCspMismatch::mismatch("script-src 'self' 'UNSAFE-EVAL'", false))->toBeNull()
        ->and(DetectCspMismatch::mismatch("script-src 'self'", true))->toBeNull()
        ->and(DetectCspMismatch::mismatch(null, false))->toBeNull()
        ->and(DetectCspMismatch::mismatch('frame-ancestors none', false))->toBeNull();
});

it('caches the finding, logs it once, and clears it when the policy is fixed', function () {
    Cache::flush();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $line) => str_contains($line, "'unsafe-eval'"));
    config(['livewire.csp_safe' => false]);

    $middleware = new DetectCspMismatch;
    $request = request();
    $response = response('ok')->withHeaders(['Content-Security-Policy' => "script-src 'self'"]);

    $middleware->terminate($request, $response);
    $middleware->terminate($request, $response);

    expect(Cache::get(DetectCspMismatch::CACHE_KEY))->toContain('csp_safe');

    $middleware->terminate($request, response('ok')->withHeaders(['Content-Security-Policy' => "script-src 'self' 'unsafe-eval'"]));

    expect(Cache::get(DetectCspMismatch::CACHE_KEY))->toBeNull();
});

it('renders the cached finding as a banner in the dashboard chrome', function () {
    Cache::put(DetectCspMismatch::CACHE_KEY, 'Policy problem: fix csp_safe.', 60);

    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    expect($html)->toContain('class="notice warn"')->toContain('Policy problem: fix csp_safe.');

    Cache::forget(DetectCspMismatch::CACHE_KEY);

    expect($this->actingAs(torqueTestUser())->get('/torque')->getContent())->not->toContain('class="notice warn"');
});

it('is registered on the dashboard routes', function () {
    $middleware = app('router')->getRoutes()->getByName('torque.overview')->gatherMiddleware();

    expect($middleware)->toContain(DetectCspMismatch::class);
});
