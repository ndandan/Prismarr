<?php

namespace App\Service\Media;

use App\Service\Wokeometer\WokeometerPageResult;
use App\Service\Wokeometer\WokeometerSettings;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read-only client for the Wokeometer Developer API (`GET /media` only).
 *
 * PAID API: every successful GET costs one credit, including an empty page.
 * This is therefore the ONLY class allowed to talk to wokeometer.app, and it
 * is called ONLY by WokeometerSyncService::runChunk() inside the messenger
 * worker. It is deliberately NOT wired into HealthService / the
 * ServiceHealthCache breaker (no ping, no Test button — a ping would bill).
 *
 * Shape follows BazarrClient::request() (raw ext-curl, lazy config, never
 * throws, `protected exec()` seam for tests) with the TransmissionClient
 * CURLOPT_HEADER capture so credit / idempotency / Retry-After headers can
 * be read. Every call yields one WokeometerPageResult whose `outcome` the
 * sync service acts on.
 *
 * Secrets: the key travels only in the `Authorization: Bearer` header —
 * never in the URL, logs, or the result's `message` (redacted as
 * belt-and-braces). Logs carry `path, type, code, outcome, message` only.
 *
 * Docs: https://wokeometer.app/developers (api-recon §1–§6, §13–§15).
 *
 * @phpstan-import-type WokeometerRow from WokeometerPageResult
 */
class WokeometerClient implements ResetInterface
{
    public const SERVICE    = 'wokeometer';
    public const BASE_URL   = 'https://wokeometer.app/api/v1';
    public const PAGE_LIMIT = 50;

    private const PATH                = '/media';
    private const TYPES               = ['movie', 'tv'];
    private const DEFAULT_RETRY_AFTER = 60;
    private const MESSAGE_MAX         = 255;

    /** Printable ASCII, no spaces — anything else could split the header. */
    private const HEADER_TOKEN = '/^[\x21-\x7E]+$/D';

    /** Idempotency keys are UUIDs; keep them to a header-safe alphabet. */
    private const IDEMPOTENCY_KEY = '/^[A-Za-z0-9-]{1,64}$/D';

    private bool $configLoaded = false;
    private bool $enabled = false;
    private string $apiKey = '';

    public function __construct(
        private readonly WokeometerSettings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function reset(): void
    {
        $this->configLoaded = false;
        $this->enabled      = false;
        $this->apiKey       = '';
    }

    /** Enabled AND a (header-safe) key is present. */
    public function ready(): bool
    {
        $this->ensureConfig();
        return $this->enabled && $this->apiKey !== '';
    }

    private function ensureConfig(): void
    {
        if ($this->configLoaded) {
            return;
        }
        $key = $this->settings->apiKey();
        $this->enabled = $this->settings->isEnabled();
        // A key with control characters / spaces would inject into the
        // header block — treat it as no key rather than send it.
        $this->apiKey = ($key !== null && preg_match(self::HEADER_TOKEN, $key) === 1) ? $key : '';
        $this->configLoaded = true;
    }

    /**
     * One page of `GET /media`. Sends ONLY the documented params (unknown
     * params → 400): `type`, `limit`, `after` (when non-null) and
     * `updated_since` (when non-null, UTC ISO-8601 with `Z`).
     *
     * @param string      $type              `movie` | `tv`
     * @param string|null $after             Previous page's `next_cursor`
     * @param int|null    $updatedSinceEpoch Inclusive lower bound on `last_updated`
     * @param string      $idempotencyKey    Persisted by the caller BEFORE the call; reused on retry
     */
    public function listMedia(string $type, ?string $after, ?int $updatedSinceEpoch, string $idempotencyKey): WokeometerPageResult
    {
        if (!$this->ready()) {
            return $this->fail(WokeometerPageResult::UNCONFIGURED, 0, $type, 'not configured');
        }
        if (!in_array($type, self::TYPES, true)) {
            return $this->fail(WokeometerPageResult::INVALID, 0, $type, 'unsupported media type');
        }
        if (preg_match(self::IDEMPOTENCY_KEY, $idempotencyKey) !== 1) {
            return $this->fail(WokeometerPageResult::INVALID, 0, $type, 'invalid idempotency key');
        }

        $query = ['type' => $type, 'limit' => self::PAGE_LIMIT];
        if ($after !== null) {
            $query['after'] = $after;
        }
        if ($updatedSinceEpoch !== null) {
            $query['updated_since'] = gmdate('Y-m-d\TH:i:s\Z', $updatedSinceEpoch);
        }

        $ch = curl_init(self::BASE_URL . self::PATH . '?' . http_build_query($query));
        if ($ch === false) {
            return $this->fail(WokeometerPageResult::TRANSPORT, 0, $type, 'curl init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HEADER          => true,
            CURLOPT_CONNECTTIMEOUT  => 8,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_NOSIGNAL        => true, // critical under FrankenPHP/Alpine
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HTTPHEADER      => $this->requestHeaders($this->apiKey, $idempotencyKey),
        ]);

        ['body' => $raw, 'code' => $code, 'error' => $err, 'headerSize' => $headerSize] = $this->exec($ch);

        // Transport failure (DNS / TLS / timeout / reset). The server may
        // already have billed the page — the caller retries with the SAME
        // Idempotency-Key, which replays for free.
        if ($raw === false || $err !== '' || $code === 0) {
            return $this->fail(WokeometerPageResult::TRANSPORT, $code, $type, $err !== '' ? $err : 'connection failed');
        }

        $headers = self::parseHeaders(substr($raw, 0, max(0, $headerSize)));
        $body    = substr($raw, max(0, $headerSize));
        $json    = json_decode($body, true);
        $json    = is_array($json) ? $json : null;

        $creditsRemaining = self::nonNegativeIntOrNull($headers['x-api-credits-remaining'] ?? null);
        $creditsCharged   = self::nonNegativeIntOrNull($headers['x-api-credits-charged'] ?? null);
        $replayed         = strtolower(trim($headers['idempotency-replayed'] ?? '')) === 'true';

        if ($code >= 200 && $code < 300) {
            if ($json === null || array_is_list($json) || !isset($json['data']) || !is_array($json['data']) || !array_is_list($json['data'])) {
                return $this->fail(WokeometerPageResult::INVALID, $code, $type, 'invalid JSON response', $creditsRemaining, $creditsCharged, $replayed);
            }

            $rows = [];
            foreach ($json['data'] as $item) {
                if (is_array($item) && ($row = self::normalizeRow($item, $type)) !== null) {
                    $rows[] = $row;
                }
            }
            $cursor = $json['next_cursor'] ?? null;

            return new WokeometerPageResult(
                outcome: WokeometerPageResult::OK,
                httpCode: $code,
                rows: $rows,
                nextCursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
                creditsRemaining: $creditsRemaining,
                creditsCharged: $creditsCharged,
                replayed: $replayed,
            );
        }

        $outcome = match (true) {
            $code === 401 => WokeometerPageResult::AUTH,
            $code === 402 => WokeometerPageResult::OUT_OF_CREDITS,
            $code === 403 => WokeometerPageResult::FORBIDDEN,
            $code === 409 => WokeometerPageResult::CONFLICT,
            $code === 429 => WokeometerPageResult::RATE_LIMITED,
            $code >= 500  => WokeometerPageResult::TRANSIENT,
            default       => WokeometerPageResult::INVALID,
        };

        $retryAfter = null;
        if ($outcome === WokeometerPageResult::RATE_LIMITED) {
            // Header (integer seconds) → edge body `retry_after` → 60 s.
            $retryAfter = self::nonNegativeIntOrNull($headers['retry-after'] ?? null)
                ?? self::nonNegativeIntOrNull($json['retry_after'] ?? null)
                ?? self::DEFAULT_RETRY_AFTER;
        }

        return $this->fail(
            $outcome,
            $code,
            $type,
            self::errorMessage($json) ?? 'unexpected HTTP status',
            $creditsRemaining,
            $creditsCharged,
            $replayed,
            $retryAfter,
        );
    }

    /**
     * Request headers. Protected so tests can assert them (a curl handle
     * cannot be read back for CURLOPT_HTTPHEADER).
     *
     * @return list<string>
     */
    protected function requestHeaders(string $apiKey, string $idempotencyKey): array
    {
        return [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'Idempotency-Key: ' . $idempotencyKey,
        ];
    }

    /**
     * cURL execution seam: performs the transfer and returns the raw facts
     * listMedia() classifies on. `body` is the FULL response (CURLOPT_HEADER)
     * and `headerSize` splits it. Protected so unit tests feed fabricated
     * responses — there is no live Wokeometer (and no spendable credit) in
     * the test suite.
     *
     * @return array{body: string|false, code: int, error: string, headerSize: int}
     */
    protected function exec(\CurlHandle $ch): array
    {
        /** @var string|false $body */
        $body       = curl_exec($ch);
        $code       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err        = curl_error($ch);
        curl_close($ch);

        return ['body' => $body, 'code' => $code, 'error' => $err, 'headerSize' => $headerSize];
    }

    private function fail(
        string $outcome,
        int $code,
        string $type,
        string $message,
        ?int $creditsRemaining = null,
        ?int $creditsCharged = null,
        bool $replayed = false,
        ?int $retryAfter = null,
    ): WokeometerPageResult {
        $message = $this->sanitize($message);

        $this->logger->warning('Wokeometer request failed', [
            'path'    => self::PATH,
            'type'    => $this->sanitize($type),
            'code'    => $code,
            'outcome' => $outcome,
            'message' => $message,
        ]);

        return new WokeometerPageResult(
            outcome: $outcome,
            httpCode: $code,
            creditsRemaining: $creditsRemaining,
            creditsCharged: $creditsCharged,
            replayed: $replayed,
            retryAfter: $retryAfter,
            message: $message,
        );
    }

    /** Strip control chars, redact the key (and its `wok_`-less body), cap at 255 chars. */
    private function sanitize(string $text): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        $key = $this->settings->apiKey();
        if ($key !== null && $key !== '') {
            $secrets = [$key];
            if (str_starts_with($key, 'wok_') && strlen($key) > 8) {
                $secrets[] = substr($key, 4);
            }
            $text = str_replace($secrets, '[redacted]', $text);
        }

        return mb_substr($text, 0, self::MESSAGE_MAX);
    }

    /**
     * `{"error":{"code","message"}}` (application) or `{"error":"<string>"}`
     * (Cloudflare edge 429). Null when neither is a scalar.
     *
     * @param array<mixed>|null $json
     */
    private static function errorMessage(?array $json): ?string
    {
        $error = $json['error'] ?? null;
        if (is_array($error)) {
            $error = $error['message'] ?? null;
        }
        if (!is_scalar($error) || is_bool($error)) {
            return null;
        }
        $error = (string) $error;

        return trim($error) !== '' ? $error : null;
    }

    /**
     * Lowercased header map from the LAST header block (a `100 Continue` or
     * similar interim response may precede the real one). Later duplicates win.
     *
     * @return array<string, string>
     */
    private static function parseHeaders(string $raw): array
    {
        $blocks = preg_split('/\r?\n\r?\n/', trim($raw)) ?: [];
        $block  = '';
        foreach ($blocks as $candidate) {
            if (trim($candidate) !== '') {
                $block = $candidate;
            }
        }

        $headers = [];
        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue; // status line or garbage
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /**
     * Tolerant row normalization: missing keys → null, unknown keys ignored,
     * wrong scalar types coerced or nulled. Rows without an `id` are dropped.
     *
     * @param array<mixed> $item
     * @return WokeometerRow|null
     */
    private static function normalizeRow(array $item, string $requestedType): ?array
    {
        $id = self::stringOrNull($item['id'] ?? null);
        if ($id === null) {
            return null;
        }

        $score = self::intOrNull($item['woke_score'] ?? null);
        if ($score !== null && ($score < 0 || $score > 10)) {
            $score = null;
        }

        $release = self::stringOrNull($item['release_date'] ?? null);

        $source = self::stringOrNull($item['external_source'] ?? null);
        $source = $source !== null ? strtolower($source) : null;

        $externalId = self::stringOrNull($item['external_id'] ?? null);

        $tmdbId = null;
        if ($source === 'tmdb' && $externalId !== null && ctype_digit($externalId) && strlen($externalId) <= 18 && (int) $externalId > 0) {
            $tmdbId = (int) $externalId;
        }

        return [
            'wokeometerId'       => $id,
            'mediaType'          => self::stringOrNull($item['media_type'] ?? null) ?? $requestedType,
            'title'              => self::stringOrNull($item['title'] ?? null, trim: false) ?? '',
            'releaseDate'        => $release !== null ? substr($release, 0, 10) : null,
            'wokeScore'          => $score,
            'tldr'               => self::stringOrNull($item['tldr'] ?? null, trim: false),
            'slug'               => self::stringOrNull($item['slug'] ?? null),
            'isAnalyzed'         => self::boolOrNull($item['is_analyzed'] ?? null),
            'lastUpdated'        => self::epoch($item['last_updated'] ?? null),
            'parentWokeometerId' => self::stringOrNull($item['parent_id'] ?? null),
            'seasonNumber'       => self::intOrNull($item['season_number'] ?? null),
            'externalSource'     => $source,
            'externalId'         => $externalId,
            'tmdbId'             => $tmdbId,
        ];
    }

    /** Strings and non-bool scalars → string; '' (after trim) → null. */
    private static function stringOrNull(mixed $value, bool $trim = true): ?string
    {
        if (is_string($value)) {
            $s = $value;
        } elseif (is_int($value) || is_float($value)) {
            $s = (string) $value;
        } else {
            return null;
        }
        if ($trim) {
            $s = trim($s);
        }

        return trim($s) !== '' ? $s : null;
    }

    /** Ints, integral floats and (optionally signed) digit strings → int. */
    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/D', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /** Header counters / delays: an int >= 0 or nothing (an HTTP-date Retry-After → null). */
    private static function nonNegativeIntOrNull(mixed $value): ?int
    {
        $int = self::intOrNull($value);

        return $int !== null && $int >= 0 ? $int : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1'  => true,
                'false', '0' => false,
                default      => null,
            };
        }

        return null;
    }

    /** ISO-8601 → UTC epoch; missing / empty / unparseable → 0 (never "now"). */
    private static function epoch(mixed $value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }
}
