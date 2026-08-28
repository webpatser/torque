<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flags a Content-Security-Policy that Livewire's default bundle cannot run under.
 *
 * A `script-src` without `'unsafe-eval'` blocks the `new Function` evaluator
 * behind every Alpine and `wire:` expression. Livewire keeps polling, so the
 * dashboard looks alive while its controls are dead, unless the host also sets
 * `livewire.csp_safe`. The policy is usually added by a global middleware,
 * which runs outside this route middleware, so the header is inspected in
 * {@see terminate()} where the final response is visible. The finding is
 * cached for the shell to render as a banner and logged once per hour.
 */
final class DetectCspMismatch
{
    public const string CACHE_KEY = 'torque:csp-mismatch';

    private const string LOGGED_KEY = 'torque:csp-mismatch:logged';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $message = self::mismatch(
                $response->headers->get('Content-Security-Policy'),
                (bool) config('livewire.csp_safe', false),
            );

            if ($message === null) {
                Cache::forget(self::CACHE_KEY);

                return;
            }

            Cache::put(self::CACHE_KEY, $message, now()->addDay());

            if (Cache::add(self::LOGGED_KEY, 1, now()->addHour())) {
                Log::warning('[torque] '.$message);
            }
        } catch (\Throwable) {
            // Diagnostics must never break the dashboard.
        }
    }

    /**
     * Human-readable description of the problem, or null when the policy and
     * Livewire's bundle agree.
     */
    public static function mismatch(?string $policy, bool $cspSafe): ?string
    {
        if ($policy === null || $cspSafe) {
            return null;
        }

        $directives = [];

        foreach (explode(';', $policy) as $directive) {
            $parts = preg_split('/\s+/', trim($directive)) ?: [];
            $name = strtolower((string) array_shift($parts));

            if ($name !== '') {
                $directives[$name] = $parts;
            }
        }

        $scriptSrc = $directives['script-src'] ?? $directives['default-src'] ?? null;

        if ($scriptSrc === null) {
            return null;
        }

        $allowsEval = array_any($scriptSrc, static fn (string $source): bool => strcasecmp($source, "'unsafe-eval'") === 0);

        if ($allowsEval) {
            return null;
        }

        return "The Content-Security-Policy on this page has no 'unsafe-eval' in script-src, but Livewire is running its default bundle. "
            .'Alpine and wire: expressions are evaluated with new Function and fail silently under this policy. '
            ."Set 'csp_safe' => true in config/livewire.php (or allow 'unsafe-eval').";
    }
}
