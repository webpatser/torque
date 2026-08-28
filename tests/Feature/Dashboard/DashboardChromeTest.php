<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Webpatser\Torque\Dashboard\TorqueDashboardController;
use Webpatser\Torque\Torque;

/**
 * Regression guards for the dashboard chrome markup.
 *
 * The chrome contains no Alpine expressions on purpose. Alpine compiles every
 * directive expression (x-data, x-show, @click, :class, $store, x-text) with
 * `new Function`, which a Content-Security-Policy without 'unsafe-eval' blocks.
 * Under such a policy the expressions failed silently: the refresh popover was
 * stuck open, both theme icons showed and clicks did nothing, while Livewire
 * (which needs no eval of its own) kept the page looking alive.
 *
 * A single nonce'd vanilla script in the layout drives the chrome instead,
 * through delegated `data-torque-action` hooks that survive Livewire morphs and
 * wire:navigate. The fix is structural, so assert on the markup.
 */
beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);

    // The service provider registers routes before the test body can tweak
    // config, so re-register to pick up the enabled flag.
    TorqueDashboardController::register();

    Gate::define('viewTorque', fn ($user): bool => true);
});

afterEach(function () {
    Torque::cspNonce(null);
});

it('renders no Alpine directive attributes anywhere in the dashboard', function (string $path) {
    $html = $this->actingAs(torqueTestUser())->get($path)->assertOk()->getContent();

    expect($html)
        ->not->toMatch('/\sx-[a-z]+[=\s>]/')
        ->not->toMatch('/\s@[a-z][a-z.]*=/')
        ->not->toMatch('/\s:[a-z][a-z-]*=/')
        ->and($html)->not->toContain('$store')
        ->and($html)->not->toContain('$wire.');
})->with([
    'overview' => '/torque',
    'feed' => '/torque/feed',
    'queues' => '/torque/queues',
    'dead-letter' => '/torque/dead',
]);

it('drives the chrome through delegated data-torque-action hooks', function () {
    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    expect($html)
        ->toContain('data-torque-action="toggle-nav"')
        ->toContain('data-torque-action="toggle-theme"')
        ->toContain('data-torque-action="toggle-popover"')
        ->toContain('data-torque-action="close-popover"');
});

it('renders the refresh popover closed and shielded from the morph', function () {
    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    // The panel opts out of morphing, so an open panel survives a poll tick.
    expect($html)->toContain('wire:ignore class="popover"');

    // Closed by default: `open` is added by the script, never rendered.
    expect($html)
        ->not->toContain('class="popover open"')
        ->and($html)->toContain('aria-expanded="false"');

    // Options are plain Livewire now, no Alpine round-trip.
    expect($html)
        ->toContain('wire:click="setPollInterval(')
        ->and($html)->not->toContain('$wire.setPollInterval(');

    // The active option is rendered server-side; the script moves the class on
    // click, since the wire:ignore'd panel is never repainted by the server.
    expect($html)->toContain('class="popover-item mono active"');
});

it('renders both theme icons and lets CSS pick one off the html attribute', function () {
    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    expect($html)
        ->toContain('class="icon-sun"')
        ->toContain('class="icon-moon"')
        ->and($html)->not->toContain('wire:ignore class="icon-swap"');
});

// Table rows only render with Redis-backed data, so assert on the templates:
// the rendered pages are already covered by the no-Alpine test above.
it('navigates rows through a data attribute instead of an Alpine click', function (string $view) {
    $source = torqueDashboardView($view);

    expect($source)
        ->toContain('data-torque-href="')
        ->not->toContain('Livewire.navigate(');
})->with(['overview', 'feed', 'queues', 'dead']);

it('copies the job UUID through the chrome script, not Alpine', function () {
    $source = torqueDashboardView('inspector-detail');

    expect($source)
        ->toContain('data-torque-action="copy"')
        ->toContain('data-torque-copy="{{ $job[\'id\'] }}"')
        ->toContain('data-torque-copy-label');
});

it('stamps the chrome script with the CSP nonce when one is configured', function () {
    $nonce = Str::random(40);

    Torque::cspNonce($nonce);

    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    // Both the pre-paint theme script and the chrome script carry the nonce,
    // and no inline script slips through without one.
    expect(substr_count($html, "<script nonce=\"{$nonce}\">"))->toBeGreaterThanOrEqual(2)
        ->and($html)->not->toContain('<script>')
        ->and($html)->toContain('data-torque-action');
});

it('wraps dashboard tables so they scroll instead of overflowing the card', function (string $path) {
    $html = $this->actingAs(torqueTestUser())->get($path)->assertOk()->getContent();

    expect($html)->toContain('<div class="tbl-wrap">');
})->with([
    'overview' => '/torque',
    'feed' => '/torque/feed',
    'queues' => '/torque/queues',
    'dead-letter' => '/torque/dead',
]);

it('lays out the split screens with collapsible grid classes', function () {
    $feed = $this->actingAs(torqueTestUser())->get('/torque/feed')->assertOk()->getContent();

    // Inline grid-template-columns cannot be overridden by the stacking media
    // query, so the split must come from a class.
    expect($feed)
        ->toContain('class="grid-2-wide"')
        ->toContain('class="card sticky"')
        ->and($feed)->not->toContain('grid-template-columns: minmax(0,1.55fr)');
});

it('keeps the full job class name in a tooltip, since the cell truncates it', function () {
    $html = Blade::render(
        '<x-torque::jobname :ns="$ns" :cls="$cls"/>',
        ['ns' => 'App\\Jobs\\', 'cls' => 'ProcessPodcast'],
    );

    expect($html)->toContain('title="App\Jobs\ProcessPodcast"');
});
