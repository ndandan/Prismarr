<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Client for the Unraid 7 GraphQL API (POST <url>/graphql, `x-api-key` header).
 * Optional service — if `unraid_url` isn't configured (or the kill switch is
 * off), calls return null without exception. Read-only: only queries, never
 * mutations; the settings page tells the admin to create a viewer-scoped key.
 *
 * overview() fires one small query PER GROUP (array / info / metrics / docker
 * / ups) instead of one combined document: a GraphQL validation error (schema
 * drift between Unraid versions, missing UPS) fails the whole document, so
 * per-group queries let the widget render whatever the server does support.
 *
 * overview() shape (every leaf nullable — parse is fully defensive):
 *  [
 *    'array'  => ['state' => ?string,
 *                 'capacity' => ['free' => ?float, 'used' => ?float, 'total' => ?float], // kilobytes
 *                 'disks'    => list<['name' => ?string, 'temp' => ?int, 'status' => ?string,
 *                                     'size' => ?float, 'free' => ?float, 'used' => ?float]>,
 *                 'parities' => list<['name' => ?string, 'temp' => ?int, 'status' => ?string]>,
 *                 'caches'   => list<['name' => ?string, 'temp' => ?int,
 *                                     'size' => ?float, 'free' => ?float, 'used' => ?float]>],
 *    'system' => ['uptime' => ?string (raw ISO), 'uptimeEpoch' => ?int, 'cpuBrand' => ?string,
 *                 'cores' => ?int, 'threads' => ?int,
 *                 'cpuPercent' => ?float, 'memPercent' => ?float, 'memTotal' => ?float, 'memUsed' => ?float],
 *    'docker' => ['running' => int, 'total' => int, 'stopped' => list<string>],
 *    'ups'    => ?['name' => ?string, 'battery' => ?int, 'runtime' => ?int (minutes), 'load' => ?float],
 *  ]
 */
class UnraidClient implements ResetInterface
{
    /** Trivial read used by HealthService::probeFor() and ping(). */
    public const QUERY_PING = 'query { info { os { uptime } } }';

    private const QUERY_ARRAY   = 'query { array { state capacity { kilobytes { free used total } } disks { name temp status fsSize fsFree fsUsed } parities { name temp status } caches { name temp fsSize fsFree fsUsed } } }';
    private const QUERY_INFO    = 'query { info { os { uptime } cpu { brand cores threads } } }';
    private const QUERY_METRICS = 'query { metrics { cpu { percentTotal } memory { percentTotal total used } } }';
    private const QUERY_DOCKER  = 'query { docker { containers { names state } } }';
    private const QUERY_UPS     = 'query { upsDevices { name battery { chargeLevel estimatedRuntime } power { loadPercentage } } }';

    /** Widget polls every 30s; TTL keeps a paint + poll from double-querying. */
    private const OVERVIEW_TTL = 20.0;

    private ?array $overviewCache = null;
    private float  $overviewCacheAt = 0.0;

    /**
     * Set by gql() when a request fails at the transport layer (connect
     * refused / timed out / DNS) as opposed to an application-level GraphQL
     * error. overview() reads it to fail fast on a dead host instead of
     * waiting out a connect timeout on every remaining group query. Protected
     * so tests can simulate a transport failure without real cURL.
     */
    protected bool $transportDown = false;

    private ?string $baseUrl = null;
    private string  $apiKey = '';
    private bool    $skipTlsVerify = false;
    private bool    $enabled = true;
    private bool    $configLoaded = false;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->configLoaded) return;
        $this->baseUrl       = $this->config->get('unraid_url');
        $this->apiKey        = $this->config->get('unraid_api_key') ?? '';
        $this->skipTlsVerify = $this->config->get('unraid_skip_tls_verify') === '1';
        $this->enabled       = $this->config->get('unraid_enabled') !== '0';
        $this->configLoaded  = true;
    }

    public function reset(): void
    {
        $this->configLoaded    = false;
        $this->baseUrl         = null;
        $this->apiKey          = '';
        $this->skipTlsVerify   = false;
        $this->enabled         = true;
        $this->overviewCache   = null;
        $this->overviewCacheAt = 0.0;
        $this->transportDown   = false;
    }

    public function ping(): bool
    {
        $data = $this->gql(self::QUERY_PING);
        return isset($data['info']);
    }

    public function overview(): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === null || $this->baseUrl === ''
            || $this->apiKey === '') {
            return null;
        }

        $now = microtime(true);
        if ($this->overviewCache !== null && ($now - $this->overviewCacheAt) < self::OVERVIEW_TTL) {
            return $this->overviewCache;
        }

        $this->transportDown = false;
        $array = $this->mapArray($this->gql(self::QUERY_ARRAY));
        // Fail fast on a dead host: if the first query couldn't even reach the
        // box (connect refused / timed out / DNS), the remaining four group
        // queries would each burn another connect timeout (~12s total). A
        // GraphQL app-level error (HTTP 200 with {errors}) does NOT set this,
        // so a partial-scope key still queries every group.
        if ($this->transportDown) {
            return null; // don't cache — retry next call
        }
        $system = $this->mapSystem($this->gql(self::QUERY_INFO), $this->gql(self::QUERY_METRICS));
        $docker = $this->mapDocker($this->gql(self::QUERY_DOCKER));
        $ups    = $this->mapUps($this->gql(self::QUERY_UPS));

        if ($array === null && $system === null && $docker === null && $ups === null) {
            return null; // fully unreachable — don't cache, retry next call
        }

        $this->overviewCache   = ['array' => $array, 'system' => $system, 'docker' => $docker, 'ups' => $ups];
        $this->overviewCacheAt = $now;
        return $this->overviewCache;
    }

    private function mapArray(?array $data): ?array
    {
        $a = $data['array'] ?? null;
        if (!is_array($a)) return null;

        $kb = $a['capacity']['kilobytes'] ?? [];
        $mapDisk = static fn(array $d): array => [
            'name'   => isset($d['name']) ? (string) $d['name'] : null,
            'temp'   => isset($d['temp']) ? (int) $d['temp'] : null,
            'status' => isset($d['status']) ? (string) $d['status'] : null,
            'size'   => isset($d['fsSize']) ? (float) $d['fsSize'] : null,
            'free'   => isset($d['fsFree']) ? (float) $d['fsFree'] : null,
            'used'   => isset($d['fsUsed']) ? (float) $d['fsUsed'] : null,
        ];

        return [
            'state'    => isset($a['state']) ? (string) $a['state'] : null,
            'capacity' => [
                'free'  => isset($kb['free'])  ? (float) $kb['free']  : null,
                'used'  => isset($kb['used'])  ? (float) $kb['used']  : null,
                'total' => isset($kb['total']) ? (float) $kb['total'] : null,
            ],
            'disks'    => array_map($mapDisk, array_values(array_filter((array) ($a['disks'] ?? []), 'is_array'))),
            'parities' => array_map(static fn(array $p): array => [
                'name'   => isset($p['name']) ? (string) $p['name'] : null,
                'temp'   => isset($p['temp']) ? (int) $p['temp'] : null,
                'status' => isset($p['status']) ? (string) $p['status'] : null,
            ], array_values(array_filter((array) ($a['parities'] ?? []), 'is_array'))),
            'caches'   => array_map($mapDisk, array_values(array_filter((array) ($a['caches'] ?? []), 'is_array'))),
        ];
    }

    private function mapSystem(?array $info, ?array $metrics): ?array
    {
        $i = $info['info']    ?? null;
        $m = $metrics['metrics'] ?? null;
        if (!is_array($i) && !is_array($m)) return null;

        // os.uptime is an ISO-8601 boot timestamp (live-verified:
        // "2026-06-14T03:31:03.786Z"). Parse it to an epoch so the template
        // can render it via the locale-aware relative_date filter; keep the
        // raw string as a fallback for unparseable values.
        $uptimeRaw   = isset($i['os']['uptime']) ? (string) $i['os']['uptime'] : null;
        $uptimeEpoch = $uptimeRaw !== null ? strtotime($uptimeRaw) : false;

        return [
            'uptime'      => $uptimeRaw,
            'uptimeEpoch' => $uptimeEpoch !== false ? $uptimeEpoch : null,
            'cpuBrand'   => isset($i['cpu']['brand'])   ? (string) $i['cpu']['brand']   : null,
            'cores'      => isset($i['cpu']['cores'])   ? (int) $i['cpu']['cores']      : null,
            'threads'    => isset($i['cpu']['threads']) ? (int) $i['cpu']['threads']    : null,
            'cpuPercent' => isset($m['cpu']['percentTotal'])    ? (float) $m['cpu']['percentTotal']    : null,
            'memPercent' => isset($m['memory']['percentTotal']) ? (float) $m['memory']['percentTotal'] : null,
            'memTotal'   => isset($m['memory']['total'])        ? (float) $m['memory']['total']        : null,
            'memUsed'    => isset($m['memory']['used'])         ? (float) $m['memory']['used']         : null,
        ];
    }

    private function mapDocker(?array $data): ?array
    {
        $containers = $data['docker']['containers'] ?? null;
        if (!is_array($containers)) return null;

        $running = 0;
        $stopped = [];
        foreach ($containers as $c) {
            if (!is_array($c)) continue;
            $name = trim((string) (($c['names'][0] ?? '')), '/');
            if (strtoupper((string) ($c['state'] ?? '')) === 'RUNNING') {
                $running++;
            } elseif ($name !== '') {
                $stopped[] = $name;
            }
        }
        return ['running' => $running, 'total' => count($containers), 'stopped' => $stopped];
    }

    private function mapUps(?array $data): ?array
    {
        $ups = $data['upsDevices'][0] ?? null;
        if (!is_array($ups)) return null;

        return [
            'name'    => isset($ups['name']) ? (string) $ups['name'] : null,
            'battery' => isset($ups['battery']['chargeLevel'])      ? (int) $ups['battery']['chargeLevel']        : null,
            // estimatedRuntime is SECONDS (live-verified: 4302 ≈ 72 min on a
            // CP1500 at 15% load) — convert to whole minutes for the widget.
            'runtime' => isset($ups['battery']['estimatedRuntime']) ? (int) round(((float) $ups['battery']['estimatedRuntime']) / 60) : null,
            'load'    => isset($ups['power']['loadPercentage'])     ? (float) $ups['power']['loadPercentage']     : null,
        ];
    }

    /**
     * Execute one GraphQL query document. Returns the decoded `data` object,
     * or null on transport error / non-200 / GraphQL errors without data.
     * Protected so tests can substitute canned payloads.
     */
    protected function gql(string $query): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === null || $this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/graphql';

        $ch = curl_init($url);
        if ($ch === false) return null;
        $opts = $this->curlOptions();
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = (string) json_encode(['query' => $query]);
        $opts[CURLOPT_HTTPHEADER] = $this->authHeaders();
        curl_setopt_array($ch, $opts);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            // Distinguish "host unreachable" (connect/DNS/timeout) from an
            // HTTP/GraphQL error so overview() can short-circuit a dead box.
            if (in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_RESOLVE_HOST], true)) {
                $this->transportDown = true;
            }
            $this->logger->debug('UnraidClient gql failed', ['code' => $code, 'errno' => $errno]);
            return null;
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            // GraphQL errors (bad field, missing scope) come back as
            // {errors:[...]} with data null/absent — treat as group-missing.
            $this->logger->debug('UnraidClient gql returned no data', ['body' => substr((string) $body, 0, 300)]);
            return null;
        }
        return $decoded['data'];
    }

    /** @return array<int, string> */
    private function authHeaders(): array
    {
        $this->ensureConfig();
        return [
            'x-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /** Base cURL options, TLS verification driven by unraid_skip_tls_verify. */
    private function curlOptions(): array
    {
        $this->ensureConfig();
        return [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_CONNECTTIMEOUT  => 3,
            // HTTP(S) only — same SSRF stance as GluetunClient.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Self-signed / myunraid.net certs are the norm on LAN Unraid GUIs;
            // the admin opts out of verification explicitly in settings.
            CURLOPT_SSL_VERIFYPEER  => !$this->skipTlsVerify,
            CURLOPT_SSL_VERIFYHOST  => $this->skipTlsVerify ? 0 : 2,
        ];
    }
}
