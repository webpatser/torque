<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Webpatser\Torque\Dashboard\TorqueDashboardController;

/**
 * Regression guards for the dashboard chrome markup.
 *
 * Every screen polls (`wire:poll` on the content wrapper), and Livewire's morph
 * resets the `style` attribute of any element whose `_x_isShown` marker differs
 * between the live node and the freshly rendered one. That wiped Alpine's
 * `display: none`, so the refresh popover re-opened on every tick and both theme
 * icons showed at once. The fix is structural (wire:ignore + classes instead of
 * inline styles), so assert on the markup rather than on behaviour.
 */
beforeEach(function () {
    config()->set('torque.dashboard.enabled', true);

    // The service provider registers routes before the test body can tweak
    // config, so re-register to pick up the enabled flag.
    TorqueDashboardController::register();

    Gate::define('viewTorque', fn ($user): bool => true);
});

it('renders the refresh popover as an Alpine-owned, morph-ignored panel', function () {
    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    // The panel opts out of morphing entirely.
    expect($html)->toContain('wire:ignore class="popover"');

    // Its options round-trip through Alpine ($wire), never through wire:click:
    // a server round-trip would re-render the panel and reopen it.
    expect($html)
        ->not->toContain('wire:click="setPollInterval')
        ->and($html)->toContain('$wire.setPollInterval(')
        ->and($html)->toContain('$wire.pollInterval ===');

    // The active option is a class, not an inline style, so Alpine stays the
    // only writer of the style attribute inside the popover.
    expect($html)->toContain('class="popover-item mono"');
});

it('shields the theme toggle icons from the morph', function () {
    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    expect($html)->toContain('wire:ignore class="icon-swap"');
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
