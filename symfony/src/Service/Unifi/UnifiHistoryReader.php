<?php

namespace App\Service\Unifi;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Network-tab `history` group: the two slow-moving series behind the trends
 * panels. 300 s TTL — a 7-day hourly chart and a 30-day speedtest archive do
 * not change meaningfully faster than that, and both are heavier report
 * queries than the live endpoints.
 *
 * Shape (every leaf nullable — parse is fully defensive):
 *  [
 *    'usage7d'    => ?list<['ts' => int (epoch s), 'downBytes' => float, 'upBytes' => float]>,
 *    'speedtests' => ?list<['ts' => int (epoch s), 'downMbps' => ?float,
 *                           'upMbps' => ?float, 'latencyMs' => ?int]>,
 *  ]
 *
 * Direction convention matches UnifiClient: wan-rx is download, wan-tx is
 * upload. Report timestamps are epoch MILLISECONDS.
 *
 * FIELD MAP verified against the live console in plan Task 0 — see
 * docs/superpowers/plans/2026-07-24-unifi-probe-output.md.
 *
 * Network 11.x returns zero rows from both classic reports; the series then
 * come from the v2 aggregated-dashboard via WanActivity (where the 30-day
 * speedtest "archive" shrinks to the latest run — all v2 exposes).
 */
final class UnifiHistoryReader implements ResetInterface
{
    private const PATH_REPORT    = '/stat/report/hourly.site';
    private const PATH_SPEEDTEST = '/stat/report/archive.speedtest';
    /**
     * Network 11.x fallback for both: the classic reports answer ok-but-empty
     * there (see WanActivity). One narrowed call covers both series.
     */
    private const PATH_V2 = '/aggregated-dashboard?historySeconds=604800&widgets=wan_activity,speed_test';

    /** Matches the history region's data-dash-poll cadence (300 s). */
    private const TTL = 300.0;

    private const FIELD_LATENCY = 'latency';
    /** Multiplier from the API's throughput unit to Mbps. 1.0 if already Mbps. */
    private const SPEEDTEST_TO_MBPS = 1.0;

    /** Clock seam — the report windows are testable with a fixed now. */
    public ?int $nowOverride = null;

    private readonly UnifiReadCache $cache;

    public function __construct(
        private readonly UnifiFetcher $unifi,
        private readonly LoggerInterface $logger,
        ?UnifiReadCache $cache = null,
    ) {
        $this->cache = $cache ?? new UnifiReadCache();
    }

    /**
     * Nothing per-request to drop: the memo lives in cache.app (UnifiReadCache)
     * precisely so the services_resetter cannot wipe it between polls.
     */
    public function reset(): void
    {
    }

    public function read(): ?array
    {
        $now = microtime(true);
        $hit = $this->cache->get($this->unifi, 'history', self::TTL, $now);
        if (is_array($hit)) {
            return $hit;
        }

        $ts = $this->nowOverride ?? time();

        $usage = $this->mapUsage($this->unifi->fetch(self::PATH_REPORT, [
            'attrs' => ['time', 'wan-tx_bytes', 'wan-rx_bytes'],
            'start' => ($ts - 7 * 86400) * 1000,
            'end'   => $ts * 1000,
        ]));

        // Fail fast on a dead console: the second report query would burn
        // another connect timeout. An HTTP/application error does NOT trigger
        // this, so a partial API surface still tries both endpoints.
        if ($this->unifi->transportFailed()) {
            return null; // don't cache — retry next call
        }

        $speedtests = $this->mapSpeedtests($this->unifi->fetch(self::PATH_SPEEDTEST, [
            'attrs' => ['time', 'xput_download', 'xput_upload', self::FIELD_LATENCY],
            'start' => ($ts - 30 * 86400) * 1000,
            'end'   => $ts * 1000,
        ]));

        // Network 11.x: fill whichever series the classic reports left empty
        // from the v2 dashboard. Older consoles never get here with data
        // present, so their behaviour is unchanged. Not after a transport
        // failure: the console is gone, don't wait out another timeout.
        if (($usage === null || $speedtests === null) && !$this->unifi->transportFailed()) {
            $dashboard  = $this->unifi->fetchV2(self::PATH_V2);
            $usage    ??= WanActivity::usage($dashboard);
            $speedtests ??= WanActivity::speedtests($dashboard, $ts - 30 * 86400);
        }

        if ($usage === null && $speedtests === null) {
            $this->logger->warning('UnifiHistoryReader: both report queries returned no data');
            return null; // fully unavailable — don't cache, retry next call
        }

        $result = ['usage7d' => $usage, 'speedtests' => $speedtests];
        $this->cache->put($this->unifi, 'history', self::TTL, $now, $result);
        return $result;
    }

    /** Hourly wan byte buckets; `time` is epoch MILLISECONDS. */
    private function mapUsage(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_numeric($row['time'] ?? null)) continue;
            $out[] = [
                'ts'        => (int) round(((float) $row['time']) / 1000),
                'downBytes' => is_numeric($row['wan-rx_bytes'] ?? null) ? (float) $row['wan-rx_bytes'] : 0.0,
                'upBytes'   => is_numeric($row['wan-tx_bytes'] ?? null) ? (float) $row['wan-tx_bytes'] : 0.0,
            ];
        }
        if ($out === []) return null;
        usort($out, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $out;
    }

    /**
     * Speedtest archive rows. Unlike usage buckets, a missing throughput value
     * stays null rather than becoming 0.0 — SpeedtestChart skips null points
     * instead of drawing a fake drop to the floor, and a 0 Mbps run would read
     * as an outage that never happened.
     */
    private function mapSpeedtests(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $num = static fn(array $r, string $k): ?float =>
            is_numeric($r[$k] ?? null) ? (float) $r[$k] : null;

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_numeric($row['time'] ?? null)) continue;
            $down = $num($row, 'xput_download');
            $up   = $num($row, 'xput_upload');
            $lat  = $num($row, self::FIELD_LATENCY);
            $out[] = [
                'ts'        => (int) round(((float) $row['time']) / 1000),
                'downMbps'  => $down === null ? null : $down * self::SPEEDTEST_TO_MBPS,
                'upMbps'    => $up === null ? null : $up * self::SPEEDTEST_TO_MBPS,
                'latencyMs' => $lat === null ? null : (int) round($lat),
            ];
        }
        if ($out === []) return null;
        usort($out, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $out;
    }
}
