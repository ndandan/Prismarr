<?php

namespace App\Service\Unifi;

use Symfony\Contracts\Service\ResetInterface;

/**
 * One client's last hour of traffic, for the sparkline in the topology map's
 * detail panel. Fetched lazily, only when the admin selects a client — never
 * as part of the map poll, which would multiply one report query by the
 * client count.
 *
 * read() takes the map's node id (a client's MAC, lowercase hex, no
 * separators) and returns
 *   ?list<['ts' => int (epoch s), 'downBytes' => float, 'upBytes' => float]>
 * sorted ascending — the exact input shape NetworkUsageChart::build() takes —
 * or null for a malformed id, a failed query, or a client with no samples.
 *
 * Direction is the CLIENT's: the report counts from the AP's side, so
 * tx_bytes (AP → client) is what the client downloaded and rx_bytes is what it
 * uploaded. The opposite of the WAN convention UnifiHistoryReader uses, where
 * rx is the download. Report timestamps are epoch MILLISECONDS.
 *
 * Network 11.x returns zero rows from 5minutes.user; the series then comes
 * from v2 app-traffic-rate (see mapV2(), whose direction is the client's own).
 *
 * The id comes straight from a URL segment and ends up in a POST body, so it is
 * validated against the exact node-id shape before anything is built from it.
 *
 * UnifiClient memoises GETs only; this is a POST, so every read() would reach
 * the console. The per-id memo below only dedupes repeated read() calls within
 * ONE request / worker cycle: this is a ResetInterface service, so a fresh
 * kernel (classic FrankenPHP) or services_resetter (worker mode) clears it
 * before the next request. It is NOT a cross-request cache. What actually stops
 * a click-happy admin from re-querying the console is the page's client-side
 * per-id memo of the fetched fragment (map JS, Task 9). Only successes are
 * memoised — a failure or an empty result is retried on the next call.
 */
final class UnifiSparkReader implements ResetInterface
{
    private const PATH_REPORT = '/stat/report/5minutes.user';

    /**
     * Network 11.x fallback: 5minutes.user answers ok-but-empty there (see
     * WanActivity). v2 app-traffic-rate is what 11.0.99's own web app reads
     * per-client traffic from — a POST query, MAC in the body, start/end in
     * epoch MILLISECONDS (seconds return an empty list).
     */
    private const PATH_V2 = '/app-traffic-rate';

    /** v2 bucket length when a point omits interval_seconds. */
    private const V2_BUCKET_SECONDS = 300;

    /** How long a memoised result is served — in practice, the rest of the request. */
    private const TTL = 300;

    /** The sparkline's window. */
    private const WINDOW_SECONDS = 3600;

    /** Test seam — the one clock for both the report window and memo expiry. */
    public ?int $nowOverride = null;

    /**
     * Per-request memo, keyed by node id; emptied by reset() between requests,
     * so it never outlives one (see the class docblock).
     *
     * @var array<string, array{at: int, rows: list<array{ts: int, downBytes: float, upBytes: float}>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly UnifiFetcher $unifi,
    ) {}

    public function reset(): void
    {
        $this->cache = [];
    }

    /** @return ?list<array{ts: int, downBytes: float, upBytes: float}> */
    public function read(string $nodeId): ?array
    {
        // `D` so a trailing "\n" can't satisfy `$`.
        if (preg_match('/^[0-9a-f]{12}$/D', $nodeId) !== 1) {
            return null;
        }

        $now = $this->nowOverride ?? time();

        $hit = $this->cache[$nodeId] ?? null;
        if ($hit !== null && ($now - $hit['at']) < self::TTL) {
            return $hit['rows'];
        }

        $mac   = implode(':', str_split($nodeId, 2));
        $start = ($now - self::WINDOW_SECONDS) * 1000;
        $end   = $now * 1000;

        $rows = $this->map($this->unifi->fetch(self::PATH_REPORT, [
            'attrs' => ['time', 'rx_bytes', 'tx_bytes'],
            'macs'  => [$mac],
            'start' => $start,
            'end'   => $end,
        ]));
        // Network 11.x: the classic report is ok-but-empty. Not after a
        // transport failure — the console is gone, don't wait out another timeout.
        if ($rows === null && !$this->unifi->transportFailed()) {
            $rows = $this->mapV2($this->unifi->fetchV2(
                self::PATH_V2 . "?start=$start&end=$end&includeUnidentified=true",
                ['client_macs' => [$mac]],
            ));
        }
        if ($rows === null) {
            return null; // don't memoise — retry on the next call
        }

        $this->cache[$nodeId] = ['at' => $now, 'rows' => $rows];
        return $rows;
    }

    /**
     * 5-minute byte buckets; `time` is epoch MILLISECONDS.
     *
     * @param  ?array<mixed> $data
     * @return ?list<array{ts: int, downBytes: float, upBytes: float}>
     */
    private function map(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_numeric($row['time'] ?? null)) continue;
            $out[] = [
                'ts'        => (int) round(((float) $row['time']) / 1000),
                'downBytes' => is_numeric($row['tx_bytes'] ?? null) ? (float) $row['tx_bytes'] : 0.0,
                'upBytes'   => is_numeric($row['rx_bytes'] ?? null) ? (float) $row['rx_bytes'] : 0.0,
            ];
        }
        if ($out === []) return null;
        usort($out, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $out;
    }

    /**
     * v2 app-traffic-rate points: `timestamp` epoch MILLISECONDS, average
     * rates in BYTES/s over `interval_seconds` (300; less for the bucket in
     * progress). Verified on 11.0.99: (rx + tx) × interval == total_bytes.
     *
     * Direction is already the CLIENT's here — tx_byte-r is what it sent (a
     * camera's big number) — unlike 5minutes.user above, which counts from
     * the AP's side.
     *
     * @param  ?array<mixed> $data
     * @return ?list<array{ts: int, downBytes: float, upBytes: float}>
     */
    private function mapV2(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $out = [];
        foreach ($data as $p) {
            if (!is_array($p) || !is_numeric($p['timestamp'] ?? null)) continue;
            $secs = is_numeric($p['interval_seconds'] ?? null) && $p['interval_seconds'] > 0
                ? (float) $p['interval_seconds'] : (float) self::V2_BUCKET_SECONDS;
            $rate = static fn(string $k): float => is_numeric($p[$k] ?? null) ? max(0.0, (float) $p[$k]) : 0.0;
            $out[] = [
                'ts'        => (int) round(((float) $p['timestamp']) / 1000),
                'downBytes' => $rate('rx_byte-r') * $secs,
                'upBytes'   => $rate('tx_byte-r') * $secs,
            ];
        }
        if ($out === []) return null;
        usort($out, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $out;
    }
}
