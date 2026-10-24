<?php

namespace App\Service\Unifi;

use App\Dashboard\TopologyLayout;
use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Topology-map reader: fetches the three payloads the map is drawn from, hands
 * them to UnifiTopologyBuilder (graph + live rates) and TopologyLayout
 * (geometry), and caches the result for 10 s — the map region's poll cadence.
 *
 * Shape: UnifiTopologyBuilder::build()'s ['nodes', 'edges', 'busiest'] plus
 *  'layout' => TopologyLayout::build() — SVG-ready boxes and curve paths.
 * Null when the console is unreachable, stat/device is unavailable, or it
 * holds no gateway (nothing to root the tree on) — never cached, so the next
 * poll retries.
 *
 * Fetch order is health → device → sta. Health goes first because it is the
 * cheap probe that decides whether the console is alive at all: a transport
 * failure there skips the other two instead of burning a connect timeout each.
 * A missing stat/sta only costs the client leaves, so it degrades to an
 * infrastructure-only map; a missing stat/device leaves nothing to draw.
 *
 * stat/health and stat/sta are also fetched by UnifiLiveReader in the same
 * page cycle. That costs one console call each, not two: UnifiClient memoises
 * GETs for a few seconds, so no sharing code is needed here.
 *
 * Plan speeds come from the optional `unifi_plan_*_mbps` settings. They are
 * free text, so they go through parseMbps(); anything unusable simply means
 * "not set" and the internet edge falls back to the speedtest or the port.
 */
final class UnifiMapReader implements ResetInterface
{
    private const PATH_HEALTH = '/stat/health';
    private const PATH_DEVICE = '/stat/device';
    private const PATH_STA    = '/stat/sta';

    /** Matches the map region's data-dash-poll cadence (10 s). */
    private const TTL = 10.0;

    /** Test seam — advances the reader's clock without sleeping. */
    public float $nowOffset = 0.0;

    private ?array $cache = null;
    private float $cacheAt = 0.0;

    public function __construct(
        private readonly UnifiFetcher $unifi,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function reset(): void
    {
        $this->cache   = null;
        $this->cacheAt = 0.0;
    }

    public function read(): ?array
    {
        $now = microtime(true) + $this->nowOffset;
        if ($this->cache !== null && ($now - $this->cacheAt) < self::TTL) {
            return $this->cache;
        }

        $health = $this->unifi->fetch(self::PATH_HEALTH);
        // Fail fast on a dead console — the remaining calls would each burn
        // another connect timeout.
        if ($this->unifi->transportFailed()) {
            return null; // don't cache — retry next call
        }

        $devices = $this->unifi->fetch(self::PATH_DEVICE);
        if ($devices === null) {
            $this->logger->warning('UnifiMapReader: stat/device returned no data');
            return null; // don't cache — retry next call
        }

        // No client list is a degraded map, not a failed one: the switches and
        // APs are still worth drawing.
        $clients = $this->unifi->fetch(self::PATH_STA) ?? [];

        $topology = UnifiTopologyBuilder::build(
            $devices,
            $clients,
            $health,
            self::parseMbps($this->config->get('unifi_plan_down_mbps')),
            self::parseMbps($this->config->get('unifi_plan_up_mbps')),
        );
        if ($topology === null) {
            $this->logger->warning('UnifiMapReader: no gateway in stat/device');
            return null; // don't cache — retry next call
        }

        $this->cache = $topology + [
            'layout' => TopologyLayout::build($topology['nodes'], $topology['edges']),
        ];
        $this->cacheAt = $now;
        return $this->cache;
    }

    /**
     * A plan speed typed into the admin form → Mb/s, or null for "not set".
     *
     * Null for blank, non-numeric, non-finite and anything below 1: zero and
     * negatives are no plan; a sub-1 Mb/s plan is not real, and the internet
     * edge's integer linkMbps would round it to 0. Comma-grouped input
     * ("1,000") is refused rather than guessed at — it is 1000 in one locale
     * and 1.0 in another, and a wrong denominator would paint the link the
     * wrong load colour.
     */
    public static function parseMbps(?string $raw): ?float
    {
        $raw = trim((string) $raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $mbps = (float) $raw;
        return is_finite($mbps) && $mbps >= 1.0 ? $mbps : null;
    }
}
