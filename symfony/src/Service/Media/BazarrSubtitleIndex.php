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

    /** Bazarr Movies-tab grid cards, derived at refresh time from the same getMovies() pass as KEY_MOVIES. */
    public const KEY_MOVIE_CARDS = 'bazarr_subtitle_index.movie_cards';

    /** Bazarr Series-tab grid cards, derived at refresh time from the same getSeries() pass as KEY_SERIES. */
    public const KEY_SERIES_CARDS = 'bazarr_subtitle_index.series_cards';

    /** Top-N (MOST_MISSING_CANDIDATES) movie candidates for the "most missing" strip. */
    public const KEY_MOST_MISSING_MOVIES = 'bazarr_subtitle_index.most_missing_movies';

    /** Top-N (MOST_MISSING_CANDIDATES) series candidates, ranked by aggregate episodeMissingCount. */
    public const KEY_MOST_MISSING_SERIES = 'bazarr_subtitle_index.most_missing_series';

    /** `/api/badges` counts, refreshed alongside the movie dataset (one cheap call, no dedicated message). */
    public const KEY_BADGES = 'bazarr_subtitle_index.badges';

    /**
     * A plain `cache.app` item (NOT an SWR envelope) journalling recent
     * per-id mutation patches, keyed "<kind>:<id>" so repeated mutations on
     * one item collapse to the newest. Read by applyPatchesNewerThan() so a
     * bulk refresh already in flight when a mutation lands can re-apply that
     * patch on top of its own (older) result before writing (spec D3 as
     * amended, defect C2).
     *
     * @var string
     */
    public const KEY_PATCHES = 'bazarr_subtitle_index.patches';

    /** Seconds a journalled patch is kept — comfortably longer than a Bazarr full-list fetch can take. */
    public const PATCH_TTL = 120;

    /** Candidates kept per kind; the controller merges + re-sorts + slices to 16. */
    public const MOST_MISSING_CANDIDATES = 32;

    private const ALL_KEYS = [
        self::KEY_MOVIES, self::KEY_MOVIE_LANGS, self::KEY_SERIES,
        self::KEY_MOVIE_CARDS, self::KEY_SERIES_CARDS,
        self::KEY_MOST_MISSING_MOVIES, self::KEY_MOST_MISSING_SERIES,
        self::KEY_BADGES,
    ];

    /** Seconds. Short enough that a stale badge self-heals without any action. */
    public const SOFT_TTL = 60;

    /** Seconds. Outer bound on how long a value may be served stale. */
    public const HARD_TTL = 600;

    /** Cross-request throttle for the "refresh overdue" log line (see requestRefreshOnce()). */
    private const OVERDUE_LOG_KEY = 'bazarr_subtitle_index.overdue_logged';

    /** Seconds. How long a refresh may go unanswered before it's worth an operator's attention. */
    private const OVERDUE_THRESHOLD = 180;

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

    /**
     * $client is used ONLY by refreshItem() — the post-mutation per-id
     * repair path. The badge read path (movieStatus/movieLanguages/
     * seriesStatus) never calls it; both go through $swr. $cacheApp is used
     * both for the patch journal (KEY_PATCHES) and — see
     * requestRefreshOnce() — the cross-request overdue-log throttle and the
     * breaker check.
     */
    public function __construct(
        private readonly BazarrClient $client,
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly ServiceInstanceProvider $instances,
        private readonly StaleWhileRevalidateCache $swr,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reset(): void
    {
        $this->movies       = null;
        $this->movieLangs   = null;
        $this->series       = null;
        $this->moviesLoaded = false;
        $this->seriesLoaded = false;
        $this->radarrGate   = null;
        $this->sonarrGate   = null;
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

    /**
     * Queue a bulk rebuild of $key without waiting for the next reader to hit
     * a hard miss or a stale soft window. Used by mutation endpoints that
     * cannot patch a specific id in place — e.g. apiDownloadEpisode, which
     * only knows an episode id, not the series id it belongs to — so the
     * queue happens synchronously in the request that performed the mutation
     * (guardrail 9) instead of the fix waiting for someone else's page view.
     */
    public function requestRefresh(string $key): void
    {
        $this->swr->requestRefresh($key);
    }

    /**
     * Post-mutation repair for ONE item. The user who just downloaded a
     * subtitle must see the new count immediately; everyone else catches up
     * within one consumer cycle.
     *
     * Order of operations matters. The patch is journalled BEFORE the pooled
     * maps are touched, so a bulk refresh already in flight (which will
     * overwrite those maps when it lands) can re-apply it — the ordering rule
     * is "the bulk result is authoritative except for patches recorded at or
     * after its fetch start" (spec D3 as amended, defect C2).
     *
     * A failed or empty per-id fetch leaves the pooled maps and the journal
     * untouched (guardrail 6): the previous good value is never overwritten
     * by a hard-missing per-id lookup, and a queued bulk rebuild is still
     * requested so the item self-heals on the next cycle.
     *
     * @param 'movie'|'series' $kind
     */
    public function refreshItem(string $kind, int $id): void
    {
        $statusKey = $kind === 'movie' ? self::KEY_MOVIES : self::KEY_SERIES;
        $rows      = $kind === 'movie' ? $this->client->getMovies([$id]) : $this->client->getSeries([$id]);

        if ($this->client->getLastError() !== null || $rows === []) {
            $this->swr->requestRefresh($statusKey);

            return;
        }

        $row    = $rows[0];
        $status = $kind === 'movie' ? self::computeMovieStatus($row) : self::computeSeriesStatus($row);
        $langs  = $kind === 'movie' ? self::extractMovieLangs($row) : null;

        $this->journalPatch($kind, $id, $status, $langs);
        $this->patchPooledMap($statusKey, $id, $status);
        if ($kind === 'movie') {
            $this->patchPooledMap(self::KEY_MOVIE_LANGS, $id, $langs);
        }

        // Drop the per-request memo so this request re-reads the patched maps.
        $this->reset();

        // Everyone else's view, plus the cards/most-missing/badge datasets
        // derived from the same full-list fetch.
        $this->swr->requestRefresh($statusKey);
    }

    /**
     * Merge one id into a pooled map WITHOUT touching its fetchedAt — a
     * one-row patch must not make the whole map look freshly fetched (the
     * soft-TTL self-heal / stale-request logic must still see the map's real
     * age).
     */
    private function patchPooledMap(string $key, int $id, mixed $value): void
    {
        $hit = $this->swr->read($key, self::SOFT_TTL);
        if ($hit === null || !is_array($hit['value'])) {
            return; // hard miss: the journal + the requested rebuild cover it
        }

        $map      = $hit['value'];
        $map[$id] = $value;

        $item = $this->cacheApp->getItem($key);
        $env  = $item->isHit() ? $item->get() : null;
        $at   = is_array($env) && isset($env['fetchedAt']) ? (int) $env['fetchedAt'] : time();

        $this->swr->write($key, $map, self::HARD_TTL, $at);
    }

    /**
     * Journal one patch under KEY_PATCHES, keyed "<kind>:<id>" so repeated
     * mutations on the same item collapse to the newest. Entries older than
     * PATCH_TTL are dropped opportunistically so the journal never grows
     * unbounded even though the whole item also carries its own
     * expiresAfter(PATCH_TTL).
     *
     * @param 'movie'|'series' $kind
     * @param SubtitleStatus $status
     * @param MovieLangs|null $langs
     */
    private function journalPatch(string $kind, int $id, array $status, ?array $langs): void
    {
        $item    = $this->cacheApp->getItem(self::KEY_PATCHES);
        $journal = $item->isHit() && is_array($item->get()) ? $item->get() : [];

        $now = time();
        foreach ($journal as $patchKey => $entry) {
            if (!is_array($entry) || !isset($entry['at']) || ($now - (int) $entry['at']) >= self::PATCH_TTL) {
                unset($journal[$patchKey]);
            }
        }

        $journal["$kind:$id"] = [
            'at'     => $now,
            'kind'   => $kind,
            'id'     => $id,
            'status' => $status,
            'langs'  => $langs,
        ];

        $item->set($journal);
        $item->expiresAfter(self::PATCH_TTL);
        $this->cacheApp->save($item);
    }

    /**
     * Apply every journalled patch of $kind recorded at or after
     * $fetchStartedAt on top of $statusMap/$langsMap. Called by
     * BazarrIndexRefresher immediately before it writes a freshly fetched
     * bulk result, so a mutation that landed WHILE that fetch was in flight
     * is not silently reverted by it (spec D3 as amended, defect C2). A patch
     * older than $fetchStartedAt is ignored: that fetch's own result already
     * reflects it.
     *
     * @param 'movie'|'series' $kind
     * @param array<int, mixed> $statusMap
     * @param array<int, mixed> $langsMap
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    public function applyPatchesNewerThan(string $kind, int $fetchStartedAt, array $statusMap, array $langsMap): array
    {
        $item    = $this->cacheApp->getItem(self::KEY_PATCHES);
        $journal = $item->isHit() && is_array($item->get()) ? $item->get() : [];

        foreach ($journal as $entry) {
            if (!is_array($entry) || ($entry['kind'] ?? null) !== $kind) {
                continue;
            }
            if ((int) ($entry['at'] ?? 0) < $fetchStartedAt) {
                continue;
            }

            $id             = (int) ($entry['id'] ?? 0);
            $statusMap[$id] = $entry['status'];
            if ($kind === 'movie' && isset($entry['langs']) && is_array($entry['langs'])) {
                $langsMap[$id] = $entry['langs'];
            }
        }

        return [$statusMap, $langsMap];
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
     * Grid cards for the Bazarr Movies tab, derived at refresh time from the
     * SAME getMovies() pass that fills the badge maps — the tab used to run
     * its own full-list fetch on every view (4-8 s).
     *
     * Posters are deliberately absent: BazarrController joins
     * BazarrPosterResolver::postersFor('movie') at render time so poster
     * freshness follows MediaLibraryCache, not Bazarr's soft window.
     *
     * Not multi-instance gated: unlike the per-id badge lookups, the tab is
     * its own page (not overlaid on Radarr/Sonarr grids) and the underlying
     * fetch is already scoped to Bazarr's one paired Radarr/Sonarr instance.
     *
     * @return array{state: 'ready'|'warming', cards: list<array{title: string, year: int|string|null, substate: string, count: int, missingLangs: list<string>, seriesId: int|null, movieId: int|null}>, languages: list<string>}
     */
    public function movieCards(): array
    {
        $hit = $this->readDataset(self::KEY_MOVIE_CARDS, self::KEY_MOVIES);
        if ($hit === null || !is_array($hit['cards'] ?? null) || !is_array($hit['languages'] ?? null)) {
            return ['state' => 'warming', 'cards' => [], 'languages' => []];
        }

        return ['state' => 'ready', 'cards' => array_values($hit['cards']), 'languages' => array_values($hit['languages'])];
    }

    /**
     * Grid cards for the Bazarr Series tab. Same shape and contract as
     * movieCards(); see that method's docblock.
     *
     * @return array{state: 'ready'|'warming', cards: list<array{title: string, year: int|string|null, substate: string, count: int, missingLangs: list<string>, seriesId: int|null, movieId: int|null}>, languages: list<string>}
     */
    public function seriesCards(): array
    {
        $hit = $this->readDataset(self::KEY_SERIES_CARDS, self::KEY_SERIES);
        if ($hit === null || !is_array($hit['cards'] ?? null) || !is_array($hit['languages'] ?? null)) {
            return ['state' => 'warming', 'cards' => [], 'languages' => []];
        }

        return ['state' => 'ready', 'cards' => array_values($hit['cards']), 'languages' => array_values($hit['languages'])];
    }

    /**
     * The Bazarr landing page's "most missing" candidates: BOTH kinds,
     * concatenated and unsorted across kinds (the controller merges + sorts
     * by missingCount desc with a title tie-break, then slices to
     * BazarrController::MOST_MISSING_LIMIT). 'warming' is returned when
     * EITHER half is a hard miss, since the strip needs both to be meaningful.
     *
     * @return array{state: 'ready'|'warming', items: list<array{kind: 'movie'|'series', id: int|null, title: string, year: int|string|null, missingCount: int}>}
     */
    public function mostMissing(): array
    {
        $movies = $this->readDataset(self::KEY_MOST_MISSING_MOVIES, self::KEY_MOVIES);
        $series = $this->readDataset(self::KEY_MOST_MISSING_SERIES, self::KEY_SERIES);

        if ($movies === null || $series === null) {
            return ['state' => 'warming', 'items' => []];
        }

        /** @var list<array{kind: 'movie'|'series', id: int|null, title: string, year: int|string|null, missingCount: int}> $items */
        $items = array_merge(array_values($movies), array_values($series));

        return ['state' => 'ready', 'items' => $items];
    }

    /**
     * `/api/badges` counts for the Bazarr topbar/tab chips. Refreshed
     * alongside the movie dataset (see BazarrIndexRefresher) since it is one
     * cheap call — giving it its own refresh key would double queue traffic
     * for no benefit.
     *
     * @return array{state: 'ready'|'warming', counts: array{movies: int, episodes: int, providers: int}}
     */
    public function badgeCounts(): array
    {
        $hit = $this->readDataset(self::KEY_BADGES, self::KEY_MOVIES);
        if (!is_array($hit)) {
            return ['state' => 'warming', 'counts' => ['movies' => 0, 'episodes' => 0, 'providers' => 0]];
        }

        return [
            'state'  => 'ready',
            'counts' => [
                'movies'    => (int) ($hit['movies'] ?? 0),
                'episodes'  => (int) ($hit['episodes'] ?? 0),
                'providers' => (int) ($hit['providers'] ?? 0),
            ],
        ];
    }

    /**
     * Shared read for the tab datasets: serve fresh or stale, ask the
     * consumer for a rebuild of $refreshKey when stale or missing, and never
     * fetch. Deliberately not memoized per-request (unlike movieMap() /
     * seriesMap()): each of the four dataset reads is called at most once per
     * page render, and memoizing a large card list on the service would work
     * against worker-mode-safety guardrail 3 (no unbounded cross-request
     * retention).
     *
     * @return array<mixed>|null null = hard miss (caller renders "warming")
     */
    private function readDataset(string $key, string $refreshKey): ?array
    {
        $hit = $this->swr->read($key, self::SOFT_TTL);
        if ($hit === null) {
            $this->requestRefreshOnce($refreshKey);

            return null;
        }

        if ($hit['state'] === 'stale') {
            $this->swr->requestRefresh($refreshKey);
        }

        return is_array($hit['value']) ? $hit['value'] : null;
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

    /**
     * Hard miss: ask for a rebuild, and — rate-limited across requests, not
     * just this one — shout if the consumer never answers.
     *
     * Everything below the dispatch is wrapped in one try/catch: this runs on
     * the badge render path (up to 588 times per Films-page load), so a
     * broken pool adapter here must degrade to "skip the log line", never a
     * 500. requestRefresh() above already has its own internal try/catch
     * (StaleWhileRevalidateCache's own contract), so it's excluded from this
     * one on purpose — a dispatch failure there is already handled/logged.
     */
    private function requestRefreshOnce(string $key): void
    {
        $this->swr->requestRefresh($key);

        try {
            if (!$this->swr->refreshIsOverdue($key, self::OVERDUE_THRESHOLD)) {
                return;
            }

            // A cold cache means every rendered badge (and every concurrent
            // request) reaches this branch — throttle the log line itself
            // across requests via a short-lived pool marker, the same shape
            // StaleWhileRevalidateCache's own coalescing marker uses.
            $marker = $this->cacheApp->getItem(self::OVERDUE_LOG_KEY);
            if ($marker->isHit()) {
                return;
            }
            $marker->set(true);
            $marker->expiresAfter(self::OVERDUE_THRESHOLD);
            $this->cacheApp->save($marker);

            if ((new ServiceHealthCache($this->cacheApp))->isDown(BazarrClient::SERVICE)) {
                // The open breaker is already the actionable signal — don't
                // also point at the messenger worker, which is likely fine.
                $this->logger->warning(
                    'Bazarr subtitle index refresh overdue — Bazarr appears to be down (circuit breaker open)',
                    ['key' => $key],
                );

                return;
            }

            $this->logger->error(
                'Bazarr subtitle index refresh overdue — is the messenger-worker service running?',
                ['key' => $key],
            );
        } catch (\Throwable) {
            // Best-effort operational logging only; never let it affect the
            // response.
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
