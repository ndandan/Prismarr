<?php

namespace App\Service\Unifi;

/**
 * Maps the Network 11.x v2 `aggregated-dashboard` payload onto the series
 * shapes the classic stat/report mappers produce, so the charts need no change.
 *
 * Why it exists: Network 11.0 replaced the statistics engine. On 11.x the
 * classic report endpoints (stat/report/hourly.site, archive.speedtest, …)
 * still answer `rc: ok` but with ZERO rows, so history comes from
 * GET /proxy/network/v2/api/site/<site>/aggregated-dashboard?historySeconds=N
 * instead, narrowed with `&widgets=wan_activity[,speed_test]` (28 KB instead of 187 KB).
 *
 * Field facts, verified on a live 11.0.99 console (2026-11-12):
 *  - wan_activity.total_activity.history: one point per bucket — hourly for a
 *    7 d window, 5-minute for 24 h — with `timestamp` in epoch MILLISECONDS.
 *  - `avg_rx_rate_bps` / `avg_tx_rate_bps` are BYTES per second despite the
 *    name: points × bucket length summed exactly to the summary's rx_bytes.
 *  - Buckets the engine has no sample for (before the 11.x migration, the
 *    in-progress one) come back as zero rates with client_count 0.
 *  - speed_test.data is the LATEST run per WAN, not a history; an unused
 *    WAN reports `time: null` and zero speeds.
 * Direction matches the classic WAN convention: rx is download.
 *
 * Pure: no I/O, no state.
 */
final class WanActivity
{
    /** Bucket length to assume when the series is too short to measure one. */
    private const DEFAULT_BUCKET_SECONDS = 3600;

    /**
     * @param  ?array<mixed> $dashboard the decoded aggregated-dashboard object
     * @param  int $rollUpSeconds > 0 sums buckets into periods of this length
     *                            (24 h of 5-minute buckets → hourly, like hourly.site)
     * @return ?list<array{ts: int, downBytes: float, upBytes: float}> ascending
     */
    public static function usage(?array $dashboard, int $rollUpSeconds = 0): ?array
    {
        $history = $dashboard['wan_activity']['total_activity']['history'] ?? null;
        if (!is_array($history)) return null;

        $rows = [];
        foreach ($history as $p) {
            if (!is_array($p) || !is_numeric($p['timestamp'] ?? null)) continue;
            $rx = is_numeric($p['avg_rx_rate_bps'] ?? null) ? max(0.0, (float) $p['avg_rx_rate_bps']) : 0.0;
            $tx = is_numeric($p['avg_tx_rate_bps'] ?? null) ? max(0.0, (float) $p['avg_tx_rate_bps']) : 0.0;
            $clients = is_numeric($p['client_count'] ?? null) ? (int) $p['client_count'] : 0;
            $rows[] = [
                'ts'      => (int) round(((float) $p['timestamp']) / 1000),
                'rx'      => $rx,
                'tx'      => $tx,
                'sampled' => $rx > 0 || $tx > 0 || $clients > 0,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $bucket = self::bucketSeconds($rows);

        // Trim unsampled buckets from both ends only: an empty bucket INSIDE the
        // series is a real gap (an outage) and stays, as a zero.
        while ($rows !== [] && !$rows[0]['sampled']) array_shift($rows);
        while ($rows !== [] && !$rows[array_key_last($rows)]['sampled']) array_pop($rows);
        if ($rows === []) return null;

        $out = [];
        foreach ($rows as $r) {
            $ts = $rollUpSeconds > 0 ? intdiv($r['ts'], $rollUpSeconds) * $rollUpSeconds : $r['ts'];
            $out[$ts] ??= ['ts' => $ts, 'downBytes' => 0.0, 'upBytes' => 0.0];
            $out[$ts]['downBytes'] += $r['rx'] * $bucket;
            $out[$ts]['upBytes']   += $r['tx'] * $bucket;
        }

        return array_values($out);
    }

    /**
     * The latest real speedtest run at or after $sinceTs, as a one-point
     * series in UnifiHistoryReader's speedtest shape — or null.
     *
     * Only one run: the payload holds the latest run PER WAN, and joining two
     * WANs' runs into one line would draw a trend that never happened.
     *
     * @param  ?array<mixed> $dashboard
     * @return ?list<array{ts: int, downMbps: ?float, upMbps: ?float, latencyMs: ?int}>
     */
    public static function speedtests(?array $dashboard, int $sinceTs): ?array
    {
        $runs = $dashboard['speed_test']['data'] ?? null;
        if (!is_array($runs)) return null;

        $num = static fn(array $r, string $k): ?float => is_numeric($r[$k] ?? null) ? (float) $r[$k] : null;
        $best = null;
        foreach ($runs as $r) {
            if (!is_array($r) || !is_numeric($r['time'] ?? null) || (float) $r['time'] <= 0) continue;
            $ts = (int) round(((float) $r['time']) / 1000);
            if ($ts < $sinceTs || ($best !== null && $ts <= $best['ts'])) continue;
            $down = $num($r, 'download_mbps');
            $up   = $num($r, 'upload_mbps');
            if (($down ?? 0.0) <= 0 && ($up ?? 0.0) <= 0) continue;
            $lat = $num($r, 'latency_ms');
            $best = ['ts' => $ts, 'downMbps' => $down, 'upMbps' => $up,
                     'latencyMs' => $lat === null ? null : (int) round($lat)];
        }

        return $best === null ? null : [$best];
    }

    /**
     * The commonest gap between consecutive points — the engine's bucket
     * length for this window (3600 for 7 d, 300 for 24 h).
     *
     * @param list<array{ts: int}> $rows ascending
     */
    private static function bucketSeconds(array $rows): int
    {
        $gaps = [];
        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $d = $rows[$i]['ts'] - $rows[$i - 1]['ts'];
            if ($d > 0) $gaps[$d] = ($gaps[$d] ?? 0) + 1;
        }
        if ($gaps === []) return self::DEFAULT_BUCKET_SECONDS;
        arsort($gaps);

        return (int) array_key_first($gaps);
    }
}
