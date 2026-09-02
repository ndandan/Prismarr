<?php

namespace App\Controller;

use App\Controller\Concerns\ApiClientErrorTrait;
use App\Entity\ServiceInstance;
use App\Service\Cache\BazarrIndexRefresher;
use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrLangs;
use App\Service\Media\BazarrPosterResolver;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-only Bazarr subtitle-management section: the Wanted shell plus the
 * Movies/Series/History tabs, all under one route prefix so
 * ServiceRouteGuardSubscriber's `app_bazarr_` rule covers every page. The
 * `app_bazarr_api_*` JSON routes are carved out of that rule
 * (`exclude_prefix`) — background fetches need the JSON error shape below,
 * not a 302 to HTML.
 *
 * Fails closed like every other media-client controller: an unreachable or
 * unconfigured Bazarr renders the shell with the error banner instead of a
 * 500, matching Tautulli/UniFi.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/bazarr', name: 'app_bazarr_')]
class BazarrController extends AbstractController
{
    use ApiClientErrorTrait;

    /** Landing "most missing" poster row: enough to fill a horizontal scroller without becoming a scroll itself. */
    private const MOST_MISSING_LIMIT = 16;

    public function __construct(
        private readonly BazarrClient $bazarr,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly BazarrSubtitleIndex $bazarrIndex,
        private readonly ServiceInstanceProvider $instances,
        private readonly BazarrPosterResolver $posters,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $error = false;
        $mostMissing = [];
        $counts = ['movies' => 0, 'episodes' => 0, 'providers' => 0];

        try {
            if (!$this->bazarr->ping()) {
                $error = true;
            } else {
                $counts = $this->bazarr->getBadgeCounts();
                $wantedMovies = $this->bazarr->getWantedMovies();
                $wantedEpisodes = $this->bazarr->getWantedEpisodes();
                $mostMissing = $this->buildMostMissing(
                    $wantedMovies,
                    $wantedEpisodes,
                    $this->posters->postersFor('movie'),
                    $this->posters->postersFor('series'),
                );
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Bazarr index failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('bazarr/index.html.twig', [
            'error'        => $error,
            'counts'       => $counts,
            'most_missing' => $mostMissing,
            'service_url'  => $this->config->get('bazarr_url'),
        ]);
    }

    /**
     * Collapse the wanted movie/episode lists into one ranked "most missing"
     * set for the landing overview's poster row. Each source row (a raw
     * Bazarr movie/episode dict, same shape buildCards() consumes) becomes a
     * light {kind, id, title, year, poster, missingCount} tuple; `id` is
     * whichever of radarrId/sonarrSeriesId the row carries — the SAME id the
     * per-item auto-search button and the series detail link key off, so a
     * row missing it just renders with no button/link rather than a broken
     * one.
     *
     * postersFor() is called ONCE per kind by the caller (index()) and the
     * resulting maps are threaded through here — never re-fetched per row.
     * A poster miss (multi-instance gate, or the item just isn't in the
     * library) degrades to a no-poster tile in the template; it never breaks
     * the row.
     *
     * Ranked by missingCount desc (ties broken by title, case-insensitively)
     * and capped at MOST_MISSING_LIMIT — this is a glanceable "what needs the
     * most work" strip, not a substitute for the full Movies/Series grids.
     *
     * @param list<array<string, mixed>> $wantedMovies
     * @param list<array<string, mixed>> $wantedEpisodes
     * @param array<int, string>         $moviePosters
     * @param array<int, string>         $seriesPosters
     *
     * @return list<array{kind: 'movie'|'series', id: int|null, title: string, year: int|string|null, poster: string|null, missingCount: int}>
     */
    private function buildMostMissing(array $wantedMovies, array $wantedEpisodes, array $moviePosters, array $seriesPosters): array
    {
        $items = [];

        foreach ($wantedMovies as $row) {
            $id = isset($row['radarrId']) ? (int) $row['radarrId'] : null;
            $items[] = [
                'kind'         => 'movie',
                'id'           => $id,
                'title'        => (string) ($row['title'] ?? $row['name'] ?? '—'),
                'year'         => $row['year'] ?? null,
                'poster'       => $id !== null ? ($moviePosters[$id] ?? null) : null,
                'missingCount' => is_countable($row['missing_subtitles'] ?? null) ? count($row['missing_subtitles']) : 0,
            ];
        }

        foreach ($wantedEpisodes as $row) {
            $id    = isset($row['sonarrSeriesId']) ? (int) $row['sonarrSeriesId'] : null;
            $title = trim(((string) ($row['seriesTitle'] ?? $row['title'] ?? '—')) . ' ' . ((string) ($row['episode_number'] ?? '')));
            $items[] = [
                'kind'         => 'series',
                'id'           => $id,
                'title'        => $title !== '' ? $title : '—',
                'year'         => null,
                'poster'       => $id !== null ? ($seriesPosters[$id] ?? null) : null,
                'missingCount' => is_countable($row['missing_subtitles'] ?? null) ? count($row['missing_subtitles']) : 0,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $diff = $b['missingCount'] <=> $a['missingCount'];

            return $diff !== 0 ? $diff : strcasecmp($a['title'], $b['title']);
        });

        return array_slice($items, 0, self::MOST_MISSING_LIMIT);
    }

    #[Route('/movies', name: 'movies')]
    public function movies(): Response
    {
        $error = false;
        $cards = [];
        $languages = [];

        try {
            if (!$this->bazarr->ping()) {
                $error = true;
            } else {
                ['cards' => $cards, 'languages' => $languages] = $this->buildCards($this->bazarr->getMovies(), 'movie');
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Bazarr movies failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('bazarr/movies.html.twig', [
            'error'       => $error,
            'cards'       => $cards,
            'languages'   => $languages,
            'service_url' => $this->config->get('bazarr_url'),
        ]);
    }

    #[Route('/series', name: 'series')]
    public function series(): Response
    {
        $error = false;
        $cards = [];
        $languages = [];

        try {
            if (!$this->bazarr->ping()) {
                $error = true;
            } else {
                ['cards' => $cards, 'languages' => $languages] = $this->buildCards($this->bazarr->getSeries(), 'series');
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Bazarr series failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('bazarr/series.html.twig', [
            'error'       => $error,
            'cards'       => $cards,
            'languages'   => $languages,
            'service_url' => $this->config->get('bazarr_url'),
        ]);
    }

    /**
     * Enrich Bazarr's OWN movie/series rows into the compact card shape the
     * poster-grid + filter bar consume client-side.
     *
     * The status + missing-language codes are computed DIRECTLY from these rows
     * (BazarrSubtitleIndex::computeMovieStatus/computeSeriesStatus +
     * BazarrLangs::extract) — this is Bazarr's own list, keyed by its own
     * radarrId/sonarrSeriesId, so there is no id-collision risk and neither the
     * multi-instance gate nor a per-card index lookup applies here.
     *
     * Posters come from postersFor() (called ONCE per action, library-gated): a
     * multi-instance install yields an empty map, so every card degrades to a
     * no-poster tile while the list + status still render — the correct degraded
     * state.
     *
     * `substate` collapses BazarrSubtitleIndex's 'hidden' state to 'not-tracked'
     * for the tab (no subtitle profile, or a series with zero episode files).
     * `languages` is the distinct set of missing-language codes across every
     * card, feeding the Language filter <select>.
     *
     * @param list<array<string, mixed>> $rows
     * @param 'movie'|'series'            $kind
     *
     * @return array{cards: list<array{title: string, year: int|string|null, poster: string|null, substate: string, count: int, missingLangs: list<string>, seriesId: int|null, movieId: int|null}>, languages: list<string>}
     */
    private function buildCards(array $rows, string $kind): array
    {
        $posters = $this->posters->postersFor($kind);
        $idKey   = $kind === 'movie' ? 'radarrId' : 'sonarrSeriesId';

        $cards   = [];
        $langSet = [];
        foreach ($rows as $row) {
            $status = $kind === 'movie'
                ? BazarrSubtitleIndex::computeMovieStatus($row)
                : BazarrSubtitleIndex::computeSeriesStatus($row);
            $substate = $status['state'] === 'hidden' ? 'not-tracked' : $status['state'];

            $codes = [];
            foreach (BazarrLangs::extract($row)['missing'] as $lang) {
                $codes[$lang['lang']] = true;
                $langSet[$lang['lang']] = true;
            }

            $id = isset($row[$idKey]) ? (int) $row[$idKey] : null;

            $cards[] = [
                'title'        => (string) ($row['title'] ?? ''),
                'year'         => $row['year'] ?? null,
                'poster'       => $id !== null ? ($posters[$id] ?? null) : null,
                'substate'     => $substate,
                'count'        => $status['count'],
                'missingLangs' => array_keys($codes),
                'seriesId'     => $kind === 'series' ? $id : null,
                'movieId'      => $kind === 'movie' ? $id : null,
            ];
        }

        ksort($langSet);

        return ['cards' => $cards, 'languages' => array_keys($langSet)];
    }

    #[Route('/history', name: 'history')]
    public function history(): Response
    {
        $error = false;
        $historyMovies = [];
        $historyEpisodes = [];

        try {
            if (!$this->bazarr->ping()) {
                $error = true;
            } else {
                $historyMovies = $this->bazarr->getHistoryMovies();
                $historyEpisodes = $this->bazarr->getHistoryEpisodes();
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Bazarr history failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('bazarr/history.html.twig', [
            'error'            => $error,
            'history_movies'   => $historyMovies,
            'history_episodes' => $historyEpisodes,
            'service_url'      => $this->config->get('bazarr_url'),
        ]);
    }

    /**
     * Episode drill-down for one Sonarr series. `series_title` is a
     * best-effort lookup against the already-consumed getSeries() list (no
     * dedicated "get one series" client method exists) — a miss just falls
     * back to a generic "Series #{id}" heading in the template.
     */
    #[Route('/series/{seriesId}', name: 'series_detail', requirements: ['seriesId' => '\d+'])]
    public function seriesDetail(int $seriesId): Response
    {
        $error = false;
        $episodes = [];
        $seriesTitle = null;

        try {
            if (!$this->bazarr->ping()) {
                $error = true;
            } else {
                $episodes = $this->bazarr->getEpisodes($seriesId);
                foreach ($this->bazarr->getSeries() as $s) {
                    if ((int) ($s['sonarrSeriesId'] ?? 0) === $seriesId) {
                        $seriesTitle = (string) ($s['title'] ?? '');
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Bazarr series detail failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('bazarr/series_detail.html.twig', [
            'error'        => $error,
            'series_id'    => $seriesId,
            'series_title' => $seriesTitle,
            'episodes'     => $episodes,
            'service_url'  => $this->config->get('bazarr_url'),
        ]);
    }

    /**
     * Manual subtitle search for one Radarr movie. searchMovie() returns a
     * provider-result list, either bare or wrapped in a `{data: [...]}`
     * envelope depending on the Bazarr version — the `$r['data'] ?? $r`
     * unwrap handles both.
     */
    #[Route('/api/search/movie/{radarrId}', name: 'api_search_movie', methods: ['GET'], requirements: ['radarrId' => '\d+'])]
    public function apiSearchMovie(int $radarrId): JsonResponse
    {
        $r = $this->bazarr->searchMovie($radarrId);

        return $r !== null
            ? $this->json(['ok' => true, 'results' => $r['data'] ?? $r])
            : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Manual subtitle search for one Sonarr episode. Same envelope handling
     * as apiSearchMovie().
     */
    #[Route('/api/search/episode/{episodeId}', name: 'api_search_episode', methods: ['GET'], requirements: ['episodeId' => '\d+'])]
    public function apiSearchEpisode(int $episodeId): JsonResponse
    {
        $r = $this->bazarr->searchEpisode($episodeId);

        return $r !== null
            ? $this->json(['ok' => true, 'results' => $r['data'] ?? $r])
            : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Present/missing subtitle languages for one Radarr movie, fetched by the
     * film-detail modal on open. Fail-closed like the badge it sits beside:
     * a gated (multi-instance) / untracked / absent movie — AND a genuinely
     * unreachable Bazarr — all answer the same 200 `tracked:false`, never a
     * JSON error. Reads via movieLanguagesSingle() (Task 8): the map is
     * consulted first (fresh or stale, zero extra Bazarr load), and only a
     * genuine hard miss makes ONE per-id `getMovies([$radarrId])` call rather
     * than waiting on the bulk 5,382-row refill — this endpoint answers for
     * exactly one movie per open, so the single-item fallback is safe here
     * (never call it from a grid/list — see BazarrSubtitleIndex::movieStatusSingle()
     * docblock and TemplateStructureGuardTest). Every failure path — gated,
     * untracked, absent, or a failed fallback fetch — still degrades to the
     * same untracked shape, so this action has nothing left to branch on; a
     * modal opening on a down Bazarr just renders no chips instead of an
     * error toast.
     */
    #[Route('/api/subtitles/movie/{radarrId}', name: 'api_subtitles_movie', methods: ['GET'], requirements: ['radarrId' => '\d+'])]
    public function apiSubtitlesMovie(int $radarrId): JsonResponse
    {
        return $this->json(['ok' => true, ...$this->bazarrIndex->movieLanguagesSingle($radarrId)]);
    }

    /**
     * Present/missing subtitle languages per Sonarr episode for one series,
     * fetched by the series-detail modal on open. Same fail-closed contract
     * as apiSubtitlesMovie(): the multi-instance Sonarr gate answers 200
     * `tracked:false, episodes:{}` (a bare sonarrEpisodeId is ambiguous across
     * two enabled Sonarr instances), and any other failure — an unreachable
     * Bazarr, an empty/absent series — degrades to `tracked:true` with an
     * empty or partial episodes map rather than a JSON error, since
     * BazarrClient::getEpisodes() already fails closed to `[]` on a transport
     * failure. Episodes missing `sonarrEpisodeId` are skipped — nothing to
     * key them by.
     *
     * Unlike the movie endpoint this reads BazarrClient directly rather than
     * through BazarrSubtitleIndex: there is no cross-request cache for the
     * per-episode detail (the series drill-down page is a low-traffic modal
     * open, not a badge rendered on every card), so the extra caching layer
     * would only add complexity for no real benefit here.
     */
    #[Route('/api/subtitles/series/{sonarrSeriesId}', name: 'api_subtitles_series', methods: ['GET'], requirements: ['sonarrSeriesId' => '\d+'])]
    public function apiSubtitlesSeries(int $sonarrSeriesId): JsonResponse
    {
        if (!$this->instances->hasExactlyOneEnabled(ServiceInstance::TYPE_SONARR)) {
            return $this->json(['ok' => true, 'tracked' => false, 'episodes' => []]);
        }

        $episodes = [];
        foreach ($this->bazarr->getEpisodes($sonarrSeriesId) as $episode) {
            if (!isset($episode['sonarrEpisodeId'])) {
                continue;
            }
            $episodes[(int) $episode['sonarrEpisodeId']] = BazarrLangs::extract($episode);
        }

        return $this->json(['ok' => true, 'tracked' => true, 'episodes' => $episodes]);
    }

    /**
     * Download a specific subtitle result for a movie. No CSRF token —
     * follows the Deluge convention (#[IsGranted] + same-origin fetch only).
     *
     * Refetches just this movie and patches the badge/langs maps in place on
     * success — dropping the whole pool (the old invalidate() behaviour)
     * would make the very next visitor pay a fresh 'pending' hard miss for
     * the item the user just fixed. See BazarrSubtitleIndex::refreshItem().
     *
     * Without a usable radarrid there is nothing to patch in place: queue a
     * bulk rebuild of the movies + badges datasets instead of invalidate()'s
     * blanket delete (fix round 1, IMPORTANT 3) — invalidate() would also
     * blank the langs/cards/most-missing maps this mutation never touched,
     * turning every badge on the Films page 'pending' until the next full
     * rebuild lands. A stale-with-one-stale-row index beats a hard-missing
     * one; the queued rebuild fixes it within one consumer cycle either way.
     */
    #[Route('/api/download/movie', name: 'api_download_movie', methods: ['POST'])]
    public function apiDownloadMovie(Request $request): JsonResponse
    {
        $ok = $this->bazarr->downloadMovie($request->request->all());
        if ($ok) {
            $radarrId = $request->request->getInt('radarrid');
            if ($radarrId > 0) {
                $this->bazarrIndex->refreshItem('movie', $radarrId);
            } else {
                $this->bazarrIndex->requestRefresh(BazarrSubtitleIndex::KEY_MOVIES);
                $this->bazarrIndex->requestRefresh(BazarrSubtitleIndex::KEY_BADGES);
            }
        }

        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Download a specific subtitle result for an episode. No CSRF token —
     * follows the Deluge convention (#[IsGranted] + same-origin fetch only).
     *
     * The POST body carries an episodeid, not a series id, so there is
     * nothing per-id to patch in place — queue a bulk rebuild of the series
     * map and the badge counts instead of invalidate()'s blanket delete (fix
     * round 1, IMPORTANT 3): invalidate() would also blank movies/cards/
     * most-missing that this mutation never touched, turning every badge on
     * the Films page 'pending' until the next full rebuild lands.
     */
    #[Route('/api/download/episode', name: 'api_download_episode', methods: ['POST'])]
    public function apiDownloadEpisode(Request $request): JsonResponse
    {
        $ok = $this->bazarr->downloadEpisode($request->request->all());
        if ($ok) {
            $this->bazarrIndex->requestRefresh(BazarrSubtitleIndex::KEY_SERIES);
            $this->bazarrIndex->requestRefresh(BazarrSubtitleIndex::KEY_BADGES);
        }

        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Trigger Bazarr's automatic "search missing" for one Radarr movie.
     *
     * Bazarr's search-missing is asynchronous on its own side, so the
     * immediate per-id refetch below may still report the old count — the
     * queued bulk rebuild (refreshItem()'s own requestRefresh) is what
     * eventually corrects it. Same behaviour as before this task, just
     * without the 7 s invalidate()-then-refill penalty.
     */
    #[Route('/api/auto/movie/{radarrId}', name: 'api_auto_movie', methods: ['POST'], requirements: ['radarrId' => '\d+'])]
    public function apiAutoMovie(int $radarrId): JsonResponse
    {
        $ok = $this->bazarr->searchMissingMovie($radarrId);
        if ($ok) {
            $this->bazarrIndex->refreshItem('movie', $radarrId);
        }

        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Trigger Bazarr's automatic "search missing" for one Sonarr series. See
     * apiAutoMovie()'s note on Bazarr's own search being asynchronous.
     */
    #[Route('/api/auto/series/{seriesId}', name: 'api_auto_series', methods: ['POST'], requirements: ['seriesId' => '\d+'])]
    public function apiAutoSeries(int $seriesId): JsonResponse
    {
        $ok = $this->bazarr->searchMissingSeries($seriesId);
        if ($ok) {
            $this->bazarrIndex->refreshItem('series', $seriesId);
        }

        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Force an inline rebuild of the Bazarr datasets and report truthfully on
     * what it achieved. This is the ONLY inline Bazarr fetch left in the app:
     * admin-only, explicitly user-driven (the warming panel's Retry button),
     * rate-limited by the refresher's own freshness check, and bounded by
     * BazarrClient's own 3 s connect / 8 s total timeouts — up to THREE
     * client calls can happen inline (getMovies + getBadgeCounts for the
     * movies refresh, getSeries for the series refresh), so the worst case is
     * roughly 3x8s (~24s) before this responds. It exists so a dead
     * messenger-worker is recoverable from the UI instead of leaving the tab
     * warming forever.
     *
     * Fix round 1, IMPORTANT 1: does NOT invalidate() up front. A stuck index
     * is already hard-missing or stale — that is why an admin is clicking
     * Retry — so BazarrIndexRefresher::refresh()'s own "already fresh"
     * early-return does not fire anyway. Blanking the pool first would leave
     * every badge hard-missing for the whole inline fetch, AND leave nothing
     * rebuilt at all if the fetch fails or the breaker is open — exactly the
     * case an admin needing Retry is most likely to be in, which would make
     * the response's unconditional old `{"ok": true}` a lie. Instead this
     * reads the resulting freshness back through the index's non-blocking
     * datasetState() afterwards and reports it, and answers with fail-closed
     * JSON (HTTP 200, never a 500) even when the breaker is open — the
     * breaker check below never calls the client at all.
     *
     * @return JsonResponse {ok: bool, movies: 'fresh'|'stale'|'pending', series: 'fresh'|'stale'|'pending', reason: 'breaker_open'|'fetch_failed'|null}
     */
    #[Route('/api/refresh', name: 'api_refresh', methods: ['POST'])]
    public function apiRefresh(BazarrIndexRefresher $refresher, ServiceHealthCache $health): JsonResponse
    {
        if ($health->isDown(BazarrClient::SERVICE)) {
            return $this->json([
                'ok'     => false,
                'movies' => $this->bazarrIndex->datasetState(BazarrSubtitleIndex::KEY_MOVIES),
                'series' => $this->bazarrIndex->datasetState(BazarrSubtitleIndex::KEY_SERIES),
                'reason' => 'breaker_open',
            ]);
        }

        $refresher->refresh(BazarrSubtitleIndex::KEY_MOVIES);
        $refresher->refresh(BazarrSubtitleIndex::KEY_SERIES);

        $movies = $this->bazarrIndex->datasetState(BazarrSubtitleIndex::KEY_MOVIES);
        $series = $this->bazarrIndex->datasetState(BazarrSubtitleIndex::KEY_SERIES);
        $ok     = $movies === 'fresh' && $series === 'fresh';

        return $this->json([
            'ok'     => $ok,
            'movies' => $movies,
            'series' => $series,
            'reason' => $ok ? null : 'fetch_failed',
        ]);
    }
}
