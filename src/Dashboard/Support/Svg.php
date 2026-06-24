<?php

declare(strict_types=1);

namespace Webpatser\Torque\Dashboard\Support;

/**
 * SVG geometry helpers for the dashboard gauges and sparklines.
 *
 * Ports the polar/arc math from the former React viz layer so the Blade gauge
 * components can compute their paths server-side.
 */
final class Svg
{
    /**
     * Cartesian point on a circle, measuring degrees clockwise from 12 o'clock.
     *
     * @return array{0: float, 1: float}
     */
    public static function polar(float $cx, float $cy, float $r, float $deg): array
    {
        $a = ($deg - 90) * M_PI / 180;

        return [$cx + $r * cos($a), $cy + $r * sin($a)];
    }

    /**
     * SVG arc path between two angles on a circle.
     */
    public static function arcPath(float $cx, float $cy, float $r, float $startDeg, float $endDeg): string
    {
        [$sx, $sy] = self::polar($cx, $cy, $r, $endDeg);
        [$ex, $ey] = self::polar($cx, $cy, $r, $startDeg);
        $large = ($endDeg - $startDeg) <= 180 ? 0 : 1;

        return sprintf('M %s %s A %s %s 0 %d 0 %s %s', $sx, $sy, $r, $r, $large, $ex, $ey);
    }

    /**
     * Build the line and area paths for a sparkline over the given series.
     *
     * @param  list<float|int>  $data
     * @return array{line: string, area: string, lastX: float, lastY: float}
     */
    public static function sparkline(array $data, float $w, float $h): array
    {
        $min = min($data);
        $max = max($data);
        $span = ($max - $min) ?: 1;
        $step = $w / (max(count($data) - 1, 1));

        $pts = [];
        foreach (array_values($data) as $i => $v) {
            $pts[] = [$i * $step, $h - 3 - (($v - $min) / $span) * ($h - 6)];
        }

        $line = '';
        foreach ($pts as $i => $p) {
            $line .= ($i ? 'L' : 'M').number_format($p[0], 1, '.', '').' '.number_format($p[1], 1, '.', '').' ';
        }
        $line = trim($line);

        $area = $line." L {$w} {$h} L 0 {$h} Z";
        $last = $pts[count($pts) - 1];

        return ['line' => $line, 'area' => $area, 'lastX' => $last[0], 'lastY' => $last[1]];
    }
}
