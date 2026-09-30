<?php

namespace App\Service\Wokeometer;

/**
 * One `GET /media` page as classified by WokeometerClient.
 *
 * The client never throws: every call yields exactly one of these, and the
 * sync service decides what to do from `outcome` alone (continue, back off,
 * retry with the same Idempotency-Key, or stop).
 *
 *  - `ok`             2xx with a JSON object whose `data` is a list
 *  - `unconfigured`   disabled / no key — NO request was made
 *  - `auth`           401 (missing / invalid / revoked key)
 *  - `forbidden`      403 (API access suspended)
 *  - `out_of_credits` 402
 *  - `rate_limited`   429 — `retryAfter` is always set
 *  - `conflict`       409 (Idempotency-Key reused for another request / still processing)
 *  - `transient`      5xx — retry with the SAME Idempotency-Key
 *  - `invalid`        400 / 404 / other 4xx / unexpected status / malformed 2xx body
 *  - `transport`      curl error or HTTP code 0 — retry with the SAME Idempotency-Key
 *
 * `rows` are normalized (see WokeometerClient::normalizeRow()); `message` is
 * the sanitized provider message (control chars stripped, key redacted,
 * ≤ 255 chars) — never the raw body. `droppedRows` counts `data` entries
 * that failed normalization (no usable id, or a `media_type` outside
 * movie|tv); a non-empty page where EVERY entry was dropped is `invalid`.
 *
 * @phpstan-type WokeometerRow array{
 *     wokeometerId: string,
 *     mediaType: string,
 *     title: string,
 *     releaseDate: ?string,
 *     wokeScore: ?int,
 *     tldr: ?string,
 *     slug: ?string,
 *     isAnalyzed: ?bool,
 *     lastUpdated: int,
 *     parentWokeometerId: ?string,
 *     seasonNumber: ?int,
 *     externalSource: ?string,
 *     externalId: ?string,
 *     tmdbId: ?int
 * }
 */
final readonly class WokeometerPageResult
{
    public const OK             = 'ok';
    public const UNCONFIGURED   = 'unconfigured';
    public const AUTH           = 'auth';
    public const FORBIDDEN      = 'forbidden';
    public const OUT_OF_CREDITS = 'out_of_credits';
    public const RATE_LIMITED   = 'rate_limited';
    public const CONFLICT       = 'conflict';
    public const TRANSIENT      = 'transient';
    public const INVALID        = 'invalid';
    public const TRANSPORT      = 'transport';

    /**
     * @param list<WokeometerRow> $rows
     */
    public function __construct(
        public string $outcome,
        public int $httpCode = 0,
        public array $rows = [],
        public ?string $nextCursor = null,
        public ?int $creditsRemaining = null,
        public ?int $creditsCharged = null,
        public bool $replayed = false,
        public ?int $retryAfter = null,
        public ?string $message = null,
        public int $droppedRows = 0,
    ) {}

    public function isOk(): bool
    {
        return $this->outcome === self::OK;
    }
}
