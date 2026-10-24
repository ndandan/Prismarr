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
 * The id comes straight from a URL segment and ends up in a POST body, so it is
 * validated against the exact node-id shape before anything is built from it.
 *
 * UnifiClient memoises GETs only; this is a POST, so every call would reach the
 * console. The per-id 300 s cache is what keeps a click-happy admin from doing
 * that. Only successes are cached (a failure or an empty result retries on the
 * next click), and expired entries are pruned on every write so a long-lived
 * worker's cache holds at most the clients looked at in the last TTL.
 */
final class UnifiSparkReader implements ResetInterface
{
    private const PATH_REPORT = '/stat/report/5minutes.user';

    /** Five-minute buckets don't change faster than this. */
    private const TTL = 300;

    /** The sparkline's window. */
    private const WINDOW_SECONDS = 3600;

    /** Test seam — the one clock for both the report window and cache expiry. */
    public ?int $nowOverride = null;

    /**
     * Keyed by node id. int|string because PHP turns an all-digit id such as
     * '112233445566' into an int key; every lookup normalises the same way, so
     * that is invisible here.
     *
     * @var array<int|string, array{at: int, rows: list<array{ts: int, downBytes: float, upBytes: float}>}>
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

        $rows = $this->map($this->unifi->fetch(self::PATH_REPORT, [
            'attrs' => ['time', 'rx_bytes', 'tx_bytes'],
            'macs'  => [implode(':', str_split($nodeId, 2))],
            'start' => ($now - self::WINDOW_SECONDS) * 1000,
            'end'   => $now * 1000,
        ]));
        if ($rows === null) {
            return null; // don't cache — retry on the next selection
        }

        foreach ($this->cache as $id => $entry) {
            if (($now - $entry['at']) >= self::TTL) {
                unset($this->cache[$id]);
            }
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
}
