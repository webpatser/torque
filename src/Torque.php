<?php

declare(strict_types=1);

namespace Webpatser\Torque;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Serves the bundled dashboard stylesheet inline (Horizon/Pulse-style).
 *
 * The dashboard UI is built with Livewire 4 + Blade. Behaviour ships through
 * Livewire's own runtime (and bundled Alpine) in the host app, so Torque only
 * needs to provide styling: a single self-contained `dist/torque.css`, compiled
 * by `npm run build` and inlined into the dashboard layout. No `@vite`, no asset
 * publishing, no host build step.
 */
final class Torque
{
    /**
     * The explicit CSP nonce set via cspNonce(), if any.
     */
    private static ?string $cspNonce = null;

    /**
     * Inline the compiled dashboard stylesheet.
     *
     * @throws RuntimeException When the stylesheet has not been built yet.
     */
    public static function css(): HtmlString
    {
        if (($css = @file_get_contents(self::stylesheetPath())) === false) {
            throw new RuntimeException('Unable to load the Torque dashboard CSS. Run the frontend build first (npm run build).');
        }

        return new HtmlString(sprintf('<style%s>%s</style>', self::cspNonceAttribute(), $css));
    }

    /**
     * Absolute path to the compiled dashboard stylesheet.
     */
    public static function stylesheetPath(): string
    {
        return __DIR__.'/../dist/torque.css';
    }

    /**
     * Register the Torque dev commands.
     */
    public static function registerDevCommands(): void
    {
        if (class_exists(\Illuminate\Foundation\DevCommands::class)) {
            \Illuminate\Foundation\DevCommands::artisan('torque:start', 'torque');
        }
    }

    /**
     * Set (or clear) the CSP nonce applied to the dashboard's inline style and
     * script tags.
     *
     * Pass null to clear an explicitly set nonce (mainly useful in tests):
     * once cleared, cspNonceValue() falls back to Vite::cspNonce() again.
     */
    public static function cspNonce(?string $nonce): void
    {
        self::$cspNonce = $nonce;
    }

    /**
     * Resolve the active CSP nonce.
     *
     * Returns the explicitly set nonce, or, when none was set, whatever
     * Vite::cspNonce() reports (populated once the host app calls
     * Vite::useCspNonce()), the same convention Livewire follows.
     */
    public static function cspNonceValue(): ?string
    {
        return self::$cspNonce ?? Vite::cspNonce();
    }

    /**
     * The `nonce="..."` HTML attribute for the active CSP nonce, or an empty
     * string when no nonce is set.
     */
    public static function cspNonceAttribute(): string
    {
        $nonce = self::cspNonceValue();

        return $nonce === null ? '' : sprintf(' nonce="%s"', e($nonce));
    }
}
