<?php
namespace App\Dashboard;

/**
 * Pure geometry helper for the Network widget's inline-SVG 24h usage chart.
 * Turns UnifiClient::overview()['usage24h'] (hourly wan byte buckets) into
 * ready-to-render coordinate strings so the Twig fragment stays dumb. No
 * I/O, no state; null when fewer than 2 usable points (nothing to chart).
 * Hour labels use the server timezone via date() — same convention as the
 * rest of the app's server-rendered times.
 */
final class NetworkUsageChart
{
    public const WIDTH  = 600.0;
    public const HEIGHT = 120.0;
    private const PAD_TOP = 8.0;

    /**
     * @param ?list<array{ts: int, downBytes: float, upBytes: float}> $series
     * @return ?array{downArea: string, downLine: string, upLine: string,
     *                points: list<array{x: float, w: float, label: string}>,
     *                downTotal: string, upTotal: string,
     *                startLabel: string, endLabel: string}
     */
    public static function build(?array $series): ?array
    {
        $rows = [];
        foreach ((array) ($series ?? []) as $r) {
            if (!is_array($r) || !is_numeric($r['ts'] ?? null)) continue;
            $rows[] = [
                'ts'   => (int) $r['ts'],
                'down' => is_numeric($r['downBytes'] ?? null) ? max(0.0, (float) $r['downBytes']) : 0.0,
                'up'   => is_numeric($r['upBytes'] ?? null)   ? max(0.0, (float) $r['upBytes'])   : 0.0,
            ];
        }
        $n = count($rows);
        if ($n < 2) return null;
        usort($rows, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $peak = 1.0; // floor of 1 avoids division by zero on an all-idle day
        foreach ($rows as $r) {
            $peak = max($peak, $r['down'], $r['up']);
        }

        $stepX = self::WIDTH / ($n - 1);
        $y = static fn(float $v): float =>
            round(self::HEIGHT - ($v / $peak) * (self::HEIGHT - self::PAD_TOP), 1);

        $downPts = $upPts = $points = [];
        foreach ($rows as $i => $r) {
            $x = round($i * $stepX, 1);
            $downPts[] = (int) $x . ',' . ((int) $y($r['down']) === $y($r['down']) && $y($r['down']) === (int) $y($r['down']) ? (int) $y($r['down']) : $y($r['down']));
            $upPts[]   = (int) $x . ',' . ((int) $y($r['up']) === $y($r['up']) && $y($r['up']) === (int) $y($r['up']) ? (int) $y($r['up']) : $y($r['up']));
            $points[]  = [
                'x'     => round(max(0.0, $x - $stepX / 2), 1),
                'w'     => round($stepX, 1),
                'label' => date('H:i', $r['ts'])
                    . ' — ↓ ' . self::bytes($r['down'])
                    . ' · ↑ ' . self::bytes($r['up']),
            ];
        }

        $downAreaStart = '0,' . (int) self::HEIGHT;
        $downAreaEnd = (int) self::WIDTH . ',' . (int) self::HEIGHT;

        return [
            'downArea'   => $downAreaStart . ' ' . implode(' ', $downPts) . ' ' . $downAreaEnd,
            'downLine'   => implode(' ', $downPts),
            'upLine'     => implode(' ', $upPts),
            'points'     => $points,
            'downTotal'  => self::bytes(array_sum(array_column($rows, 'down'))),
            'upTotal'    => self::bytes(array_sum(array_column($rows, 'up'))),
            'startLabel' => date('H:i', $rows[0]['ts']),
            'endLabel'   => date('H:i', $rows[$n - 1]['ts']),
        ];
    }

    /** 0 → '0 B', 2_500_000 → '2.5 MB', 1.85e9 → '1.9 GB'. Trailing .0 dropped. */
    public static function bytes(float $b): string
    {
        foreach ([['GB', 1e9], ['MB', 1e6], ['KB', 1e3]] as [$unit, $div]) {
            if ($b >= $div) {
                return rtrim(rtrim(number_format($b / $div, 1, '.', ''), '0'), '.') . ' ' . $unit;
            }
        }
        return round($b) . ' B';
    }
}
