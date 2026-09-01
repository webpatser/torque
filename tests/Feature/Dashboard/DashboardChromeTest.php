<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webpatser\Torque\Dashboard\Livewire\Overview;
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
        // Both topbar pickers go through one generic component-call hook.
        ->toContain('data-torque-action="call"')
        ->toContain('data-torque-method="setPollInterval"')
        ->toContain('data-torque-method="setRange"');
});

it('renders the refresh popover closed and shielded from the morph', function () {
    $html = $this->actingAs(torqueTestUser())->get('/torque')->assertOk()->getContent();

    // Both panels opt out of morphing, so an open panel survives a poll tick.
    expect($html)->toContain('wire:ignore class="popover"')
        ->and(substr_count($html, 'wire:ignore class="popover"'))->toBe(2);

    // Closed by default: `open` is added by the script, never rendered.
    expect($html)
        ->not->toContain('class="popover open"')
        ->and($html)->toContain('aria-expanded="false"');

    // Options call the component through Livewire.find().call() from the chrome
    // script: no wire:click expression, so nothing for a CSP interpreter to reject.
    expect($html)
        ->toContain('data-torque-method="setPollInterval"')
        ->toContain('data-torque-value="5000"')
        ->and($html)->not->toContain('wire:click="setPollInterval(')
        ->and($html)->not->toContain('$wire.setPollInterval(');

    // The script resolves the component id and calls the method by name.
    expect($html)->toContain('component.call(method, value)');

    // The polled region is keyed by the interval so the morph replaces it and
    // Livewire starts a fresh wire:poll timer instead of keeping the old one.
    expect($html)->toContain('wire:key="poll-1000"');

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

it('remembers the chosen interval in the session and rejects unlisted values', function () {
    config(['torque.dashboard.default_poll_interval' => 1000]);

    Livewire::actingAs(torqueTestUser())
        ->test(Overview::class)
        ->assertSet('pollInterval', 1000)
        ->call('setPollInterval', 5000)
        ->assertSet('pollInterval', 5000);

    expect(session('torque.poll_interval'))->toBe(5000);

    // A fresh component on another screen boots with the remembered value.
    Livewire::actingAs(torqueTestUser())->test(Overview::class)->assertSet('pollInterval', 5000);

    // Hand-crafted values outside dashboard.poll_intervals fall back to the default.
    Livewire::actingAs(torqueTestUser())
        ->test(Overview::class)
        ->call('setPollInterval', 1)
        ->assertSet('pollInterval', 1000);

    // Paused (0) is a listed value and is remembered too.
    Livewire::actingAs(torqueTestUser())
        ->test(Overview::class)
        ->call('setPollInterval', 0)
        ->assertSet('pollInterval', 0);

    expect(session('torque.poll_interval'))->toBe(0);
});

/**
 * Blade only compiles a directive at a non-word boundary, so a directive glued
 * to the word before it is left as literal text.
 *
 * Glued to prose (`ago@endif`) that is immediate: the `@if` stays open and the
 * page dies with a PHP parse error a long way from the line that caused it.
 * Glued to another directive (`@endif@if`) it is worse, because it looks fine.
 * Livewire's morph pass appends its `<!--[if ENDBLOCK]-->` machinery straight
 * after the `@endif`, which un-glues the `@if` before Blade's own pass reaches
 * it, so the view renders and the bug sits there for releases. One lived in the
 * workers screen from 0.12.0 to 0.16.7 that way.
 *
 * The pattern below catches both shapes, over every view rather than a hand-kept
 * list of directories. Cheaper to assert than to debug twice.
 */
it('never glues a Blade directive to the word before it', function () {
    $root = __DIR__.'/../../../src/Dashboard/resources/views';

    $views = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $views[] = $file->getPathname();
        }
    }

    // A walk that quietly stops matching would pass every assertion below, and
    // the directory list this replaced had already lost the root-level layout.
    expect(count($views))->toBeGreaterThanOrEqual(20);
    expect($views)->toContain($root.'/dashboard.blade.php');

    foreach ($views as $view) {
        expect(file_get_contents($view))
            ->not->toMatch('/\w@(?:if|else|elseif|endif|foreach|endforeach|forelse|empty|endforelse|php|endphp)\b/');
    }
});

/**
 * A viz component inside a fluid card must scale with its grid track. The
 * throughput chart shipped a literal width="760", and `.card` carries
 * min-width: 0 and must never clip (that would cut off the popovers), so on any
 * viewport narrower than that the bars spilled out from under the card.
 */
it('renders the throughput chart at the width of its card', function () {
    // Asserted on the source: the SVG only renders once there is a series, so
    // a data-free environment would pass an assertion on the markup by default.
    expect(torqueDashboardView('overview'))
        ->toMatch('/viz\.mini-bars[^>]*:w="760"[^>]*\bfull\b/');

    // And the component honours it.
    $svg = (string) file_get_contents(
        __DIR__.'/../../../src/Dashboard/resources/views/components/viz/mini-bars.blade.php',
    );

    expect($svg)->toContain('$full ? \'100%\' : $w');
});
