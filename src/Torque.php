<?php

declare(strict_types=1);

namespace Webpatser\Torque;

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
     * Inline the compiled dashboard stylesheet.
     *
     * @throws RuntimeException When the stylesheet has not been built yet.
     */
    public static function css(): HtmlString
    {
        if (($css = @file_get_contents(self::stylesheetPath())) === false) {
            throw new RuntimeException('Unable to load the Torque dashboard CSS. Run the frontend build first (npm run build).');
        }

        return new HtmlString("<style>{$css}</style>");
    }

    /**
     * Absolute path to the compiled dashboard stylesheet.
     */
    public static function stylesheetPath(): string
    {
        return __DIR__.'/../dist/torque.css';
    }
}
