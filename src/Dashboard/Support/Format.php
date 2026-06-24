<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Support;

use Webpatser\Torque\Dashboard\Http\JobPresenter;

/**
 * Display formatting helpers for the dashboard views.
 *
 * Ports the former React `fmt` helpers. Wall-clock helpers take millisecond
 * epochs (as produced by {@see JobPresenter}).
 */
final class Format
{
    /**
     * Thousands-separated integer.
     */
    public static function int(int|float $n): string
    {
        return number_format(round($n));
    }

    /**
     * Fixed-precision number.
     */
    public static function num(int|float $n, int $decimals = 1): string
    {
        return number_format((float) $n, $decimals, '.', '');
    }

    /**
     * Relative "Ns ago" string from a millisecond epoch.
     */
    public static function ago(int $ms): string
    {
        $s = (int) floor((self::nowMs() - $ms) / 1000);

        return match (true) {
            $s < 60 => $s.'s ago',
            $s < 3600 => intdiv($s, 60).'m ago',
            $s < 86400 => intdiv($s, 3600).'h ago',
            default => intdiv($s, 86400).'d ago',
        };
    }

    /**
     * Human duration from a whole-second count.
     */
    public static function dur(int|float $s): string
    {
        $s = (int) round($s);

        return match (true) {
            $s < 60 => $s.'s',
            $s < 3600 => intdiv($s, 60).'m '.($s % 60).'s',
            default => intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m',
        };
    }

    /**
     * `HH:MM:SS.cc` clock from a millisecond epoch (centisecond precision).
     */
    public static function clock(int $ms): string
    {
        $seconds = intdiv($ms, 1000);
        $centis = str_pad((string) intdiv($ms % 1000, 10), 2, '0', STR_PAD_LEFT);

        return gmdate('H:i:s', $seconds).'.'.$centis;
    }

    /**
     * `HH:MM:SS` clock from a millisecond epoch.
     */
    public static function hms(int $ms): string
    {
        return gmdate('H:i:s', intdiv($ms, 1000));
    }

    /**
     * Megabyte string from a byte count, or null when zero/absent.
     */
    public static function memMb(int|float|string|null $bytes): ?string
    {
        $n = (float) $bytes;

        if ($n === 0.0) {
            return null;
        }

        return number_format($n / 1_048_576, 1, '.', '').'MB';
    }

    /**
     * Current wall-clock time in milliseconds.
     */
    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
