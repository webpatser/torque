<?php

declare(strict_types=1);

use Illuminate\Support\HtmlString;
use Webpatser\Torque\Torque;

/**
 * The Torque helper inlines the committed `dist/torque.css` stylesheet into the
 * dashboard layout. The dashboard itself is Livewire 4 + Blade, so there is no
 * JavaScript bundle to ship — Livewire provides the runtime in the host app.
 */
function torquePackageRoot(): string
{
    // .../src/Torque.php -> package root.
    return dirname((string) (new ReflectionClass(Torque::class))->getFileName(), 2);
}

it('exposes the compiled stylesheet path', function () {
    expect(Torque::stylesheetPath())->toEndWith('/dist/torque.css');
});

it('inlines the compiled stylesheet', function () {
    $css = Torque::css();

    expect($css)->toBeInstanceOf(HtmlString::class)
        ->and((string) $css)->toStartWith('<style>')
        ->and((string) $css)->toEndWith('</style>')
        ->and((string) $css)->toContain('tailwindcss');
});

it('throws when the stylesheet bundle is missing', function () {
    $path = torquePackageRoot().'/dist/torque.css';
    $backup = $path.'.testbak';

    rename($path, $backup);

    try {
        expect(fn () => Torque::css())
            ->toThrow(RuntimeException::class, 'Unable to load the Torque dashboard CSS');
    } finally {
        rename($backup, $path);
    }
});

afterEach(function () {
    Torque::cspNonce(null);
});

it('has no CSP nonce by default', function () {
    expect(Torque::cspNonceValue())->toBeNull()
        ->and(Torque::cspNonceAttribute())->toBe('');
});

it('exposes an explicitly set CSP nonce', function () {
    Torque::cspNonce('abc123');

    expect(Torque::cspNonceValue())->toBe('abc123')
        ->and(Torque::cspNonceAttribute())->toBe(' nonce="abc123"');
});

it('escapes the CSP nonce attribute value', function () {
    Torque::cspNonce('"><script>');

    expect(Torque::cspNonceAttribute())->not->toContain('<script>');
});

it('stamps the inlined stylesheet with the CSP nonce when one is set', function () {
    Torque::cspNonce('abc123');

    expect((string) Torque::css())->toStartWith('<style nonce="abc123">');
});
