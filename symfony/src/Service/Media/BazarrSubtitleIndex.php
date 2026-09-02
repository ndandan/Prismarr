<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\ServiceInstanceProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * radarrId/sonarrSeriesId -> Bazarr subtitle status lookup, for badge rendering.
 *
 * The badge read path (movieStatus/movieLanguages/seriesStatus) NEVER fetches
 * from Bazarr: a full-library fetch (`/movies?length=-1`, ~7 s on a large
 * library) is far too expensive to run inline behind a decorative badge that
 * renders up to 588 times on the Films page. Instead these three methods only
 * ever read `cache.app` through `StaleWhileRevalidateCache`:
 *   - fresh or stale data is served immediately (bounded staleness), and
 *   - a hard miss answers the new `pending` state and asks the
 *     messenger-worker (BazarrIndexRefresher, via RefreshCacheKey) to rebuild
 *     the dataset off the request path.
 *
 * A request-scoped memo (worker-mode safe via ResetInterface) still sits in
 * front of the pool so a single request's many badge renders cost one pool
 * read per dataset, not one per badge.
 *
 * @phpstan-type SubtitleStatus array{state: 'complete'|'missing'|'hidden'|'pending', count: int}
 * @phpstan-import-type BazarrLang from BazarrLangs
 * @phpstan-type MovieLangs array{present: list<BazarrLang>, missing: list<BazarrLang>, tracked: bool}
 */
class BazarrSubtitleIndex implements ResetInterface
{
    /** @var SubtitleStatus */
    private const HIDDEN = ['state' => 'hidden', 'count' => 0];

    /** @var SubtitleStatus A hard-miss answer: "we don't know yet", not "nothing missing". */
    private const PENDING = ['state' => 'pending', 'count' => 0];

    /** @var MovieLangs Fail-closed language shape: gated / untracked / absent. */
    private const UNTRACKED_LANGS = ['present' => [], 'missing' => [], 'tracked' => false];

    public const KEY_MOVIES      = 'bazarr_subtitle_index.movies';
    public const KEY_MOVIE_LANGS = 'bazarr_subtitle_index.movie_langs';
    public const KEY_SERIES      = 'bazarr_subtitle_index.series';

    private const ALL_KEYS = [self::KEY_MOVIES, self::KEY_MOVIE_LANGS, self::KEY_SERIES];

    /** Seconds. Short enough that a stale badge self-heals without any action. */
    public const SOFT_TTL = 60;

    /** Seconds. Outer bound on how long a value may be served stale. */
    public const HARD_TTL = 600;

    /** @var array<int, SubtitleStatus>|null null = hard miss (index not warmed) */
    private ?array $movies = null;

    /** @var array<int, MovieLangs>|null Filled by the SAME dataset as $movies. */
    private ?array $movieLangs = null;

    /** @var array<int, SubtitleStatus>|null null = hard miss (index not warmed) */
    private ?array $series = null;

    /** Distinguishes "not loaded yet" from "loaded, and it's a hard miss (null)". */
    private bool $moviesLoaded = false;
    private bool $seriesLoaded = false;

    /** Per-request memo of the multi-instance gate (see gate()). */
    private ?bool $radarrGate = null;
    private ?bool $sonarrGate = null;

    /** At most one overdue-refresh error is logged per request. */
    private bool $overdueLogged = false;

    /**
     * $client and $cacheApp are accepted but intentionally NOT stored: the
     * badge read path (movieStatus/movieLanguages/seriesStatus) no longer
     * calls the client or touches the pool directly — both go through $swr
     * now. They stay as constructor parameters only so this class's public
     * signature (positional args other services/tests construct it with)
     * is unchanged apart from the two new arguments appended at the end;
     * storing an unused copy of either would be dead weight PHPStan would
     * (rightly) flag as write-only.
     */
    public function __construct(
        BazarrClient $client,
        CacheItemPoolInterface $cacheApp,
        private readonly ServiceInstanceProvider $instances,
        private readonly StaleWhileRevalidateCache $swr,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reset(): void
    {
        $this->movies        = null;
        $this->movieLangs    = null;
        $this->series        = null;
        $this->moviesLoaded  = false;
        $this->seriesLoaded  = false;
        $this->radarrGate    = null;
        $this->sonarrGate    = null;
        $this->overdueLogged = false;
    }

    /**
     * Drop the memo AND the cross-request pool entries (markers/stamps
     * included, via the SWR primitive). Called by the Bazarr mutation
     * endpoints (subtitle download / auto-search), which change the very
     * numbers the badges display AND the present/missing language lists the
     * detail modal shows.
     */
    public function invalidate(): void
    {
        $this->reset();
        foreach (self::ALL_KEYS as $key) {
            $this->swr->delete($key);
        }
    }

    /** @return SubtitleStatus */
    public function movieStatus(int $radarrId): array
    {
        // Gate first: on a multi-instance install nothing below may run — not
        // even a pool read — because the id is meaningless (see gate()), and
        // spending a Bazarr fetch on it would be pointless.
        if (!$this->gate(ServiceInstance::TYPE_RADARR)) {
            return self::HIDDEN;
        }

        $map = $this->movieMap();

        // A hard-missing map (null memo sentinel) means "we don't know yet",
        // which is NOT the same as "nothing missing" — see PENDING.
        return $map === null ? self::PENDING : ($map[$radarrId] ?? self::HIDDEN);
    }

    /**
     * Per-movie present/missing subtitle languages for the film-detail modal.
     * Same multi-instance gate and fail-closed rule as the badge: a gated,
     * untracked (no subtitle profile), absent, or not-yet-warmed movie
     * answers the empty `tracked:false` shape, never an error.
     *
     * @return MovieLangs
     */
    public function movieLanguages(int $radarrId): array
    {
        if (!$this->gate(ServiceInstance::TYPE_RADARR)) {
            return self::UNTRACKED_LANGS;
        }

        $map = $this->movieLangMap();

        return $map === null ? self::UNTRACKED_LANGS : ($map[$radarrId] ?? self::UNTRACKED_LANGS);
    }

    /** @return SubtitleStatus */
    public function seriesStatus(int $sonarrSeriesId): array
    {
        if (!$this->gate(ServiceInstance::TYPE_SONARR)) {
            return self::HIDDEN;
        }

        $map = $this->seriesMap();

        return $map === null ? self::PENDING : ($map[$sonarrSeriesId] ?? self::HIDDEN);
    }

    /**
     * Multi-instance safety gate. Bazarr pairs with exactly ONE Radarr and one
     * Sonarr, but the badges key on bare per-instance ids (`radarrId` /
     * `sonarrSeriesId`) and render on EVERY instance's pages. With two enabled
     * Radarr instances, "movie 42" means a different film in each, so a badge
     * would show the wrong subtitle state — and its download button would
     * fetch subtitles for the wrong film. Fail closed: hide every badge unless
     * exactly one instance of that type is enabled.
     *
     * Single-instance installs — the common case — are unaffected. Matching on
     * tmdbId instead of the *arr id is the future enhancement if multi-instance
     * badges are ever wanted.
     *
     * Memoized per request: the answer costs one already-request-cached
     * ServiceInstanceProvider read, but this is called once per rendered badge.
     */
    private function gate(string $type): bool
    {
        return match ($type) {
            ServiceInstance::TYPE_RADARR => $this->radarrGate ??= $this->exactlyOneEnabled($type),
            ServiceInstance::TYPE_SONARR => $this->sonarrGate ??= $this->exactlyOneEnabled($type),
            default => false, // unknown type — fail closed, same as multi-instance
        };
    }

    private function exactlyOneEnabled(string $type): bool
    {
        return $this->instances->hasExactlyOneEnabled($type);
    }

    /** @return array<int, SubtitleStatus>|null */
    private function movieMap(): ?array
    {
        $this->loadMovies();

        return $this->movies;
    }

    /** @return array<int, MovieLangs>|null */
    private function movieLangMap(): ?array
    {
        $this->loadMovies();

        return $this->movieLangs;
    }

    /**
     * ONE cached dataset fills BOTH maps. This method NEVER fetches: a hard
     * miss asks the messenger-worker for a rebuild and leaves both maps null,
     * so a 588-badge Films page costs zero Bazarr calls no matter how cold the
     * cache is (spec D1/D3).
     */
    private function loadMovies(): void
    {
        if ($this->moviesLoaded) {
            return;
        }
        $this->moviesLoaded = true;

        $status = $this->swr->read(self::KEY_MOVIES, self::SOFT_TTL);
        $langs  = $this->swr->read(self::KEY_MOVIE_LANGS, self::SOFT_TTL);

        if ($status === null || $langs === null) {
            $this->requestRefreshOnce(self::KEY_MOVIES);
            $this->movies     = null;
            $this->movieLangs = null;

            return;
        }

        if ($status['state'] === 'stale' || $langs['state'] === 'stale') {
            $this->swr->requestRefresh(self::KEY_MOVIES);
        }

        /** @var array<int, SubtitleStatus> $s */
        $s = is_array($status['value']) ? $status['value'] : [];
        /** @var array<int, MovieLangs> $l */
        $l = is_array($langs['value']) ? $langs['value'] : [];
        $this->movies     = $s;
        $this->movieLangs = $l;
    }

    /** @return array<int, SubtitleStatus>|null */
    private function seriesMap(): ?array
    {
        if ($this->seriesLoaded) {
            return $this->series;
        }
        $this->seriesLoaded = true;

        $hit = $this->swr->read(self::KEY_SERIES, self::SOFT_TTL);
        if ($hit === null) {
            $this->requestRefreshOnce(self::KEY_SERIES);
            $this->series = null;

            return null;
        }

        if ($hit['state'] === 'stale') {
            $this->swr->requestRefresh(self::KEY_SERIES);
        }

        /** @var array<int, SubtitleStatus> $map */
        $map = is_array($hit['value']) ? $hit['value'] : [];

        return $this->series = $map;
    }

    /** Hard miss: ask for a rebuild, and shout once if the consumer never answers. */
    private function requestRefreshOnce(string $key): void
    {
        $this->swr->requestRefresh($key);

        if (!$this->overdueLogged && $this->swr->refreshIsOverdue($key, 180)) {
            $this->overdueLogged = true;
            $this->logger->error(
                'Bazarr subtitle index refresh overdue — is the messenger-worker service running?',
                ['key' => $key],
            );
        }
    }

    /**
     * @param array<string, mixed> $movie
     * @return SubtitleStatus
     */
    public static function computeMovieStatus(array $movie): array
    {
        if (($movie['profileId'] ?? null) === null) {
            return self::HIDDEN;
        }

        $missing = is_countable($movie['missing_subtitles'] ?? null) ? count($movie['missing_subtitles']) : 0;

        return $missing > 0
            ? ['state' => 'missing', 'count' => $missing]
            : ['state' => 'complete', 'count' => 0];
    }

    /**
     * Map one movie dict to its present/missing subtitle languages. The
     * untracked rule (no subtitle profile → tracked:false, empty lists) lives
     * HERE, not in BazarrLangs, which is a pure array mapper shared with the
     * episode drill-down.
     *
     * Public: BazarrIndexRefresher (the messenger-worker consumer) calls this
     * directly to build the language map from the same getMovies() pass that
     * feeds the status tuples.
     *
     * @param array<string, mixed> $movie
     * @return MovieLangs
     */
    public static function extractMovieLangs(array $movie): array
    {
        if (($movie['profileId'] ?? null) === null) {
            return self::UNTRACKED_LANGS;
        }

        return BazarrLangs::extract($movie) + ['tracked' => true];
    }

    /**
     * @param array<string, mixed> $series
     * @return SubtitleStatus
     */
    public static function computeSeriesStatus(array $series): array
    {
        if (($series['profileId'] ?? null) === null) {
            return self::HIDDEN;
        }

        $files = (int) ($series['episodeFileCount'] ?? 0);
        if ($files === 0) {
            return self::HIDDEN;
        }

        $missing = (int) ($series['episodeMissingCount'] ?? 0);

        return $missing > 0
            ? ['state' => 'missing', 'count' => $missing]
            : ['state' => 'complete', 'count' => 0];
    }
}
