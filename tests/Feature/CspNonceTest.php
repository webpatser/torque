<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Torque;

beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);

    // The service provider boots (and registers routes) before the test body
    // can tweak config, so re-register to pick up the enabled flag / path.
    TorqueDashboardController::register();

    Gate::define('viewTorque', fn ($user): bool => true);
});

afterEach(function () {
    Torque::cspNonce(null);
});

it('renders the dashboard without a nonce attribute when none is set', function () {
    $response = $this->actingAs(torqueTestUser())->get('/torque');

    $response->assertOk();

    $content = $response->getContent();

    expect($content)
        ->toContain('<style>')
        ->not->toContain('<style nonce=')
        ->toContain('<script>')
        ->not->toContain('<script nonce=');
});

it('stamps the dashboard style and inline script tags with an explicit nonce', function () {
    $nonce = Str::random(40);

    Torque::cspNonce($nonce);

    $response = $this->actingAs(torqueTestUser())->get('/torque');

    $response->assertOk();

    $content = $response->getContent();

    expect($content)
        ->toContain("<style nonce=\"{$nonce}\">")
        ->toContain("<script nonce=\"{$nonce}\">");
});
