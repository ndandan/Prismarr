<?php

namespace App\Controller;

use App\Controller\Concerns\ApiClientErrorTrait;
use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrLangs;
use App\Service\Media\BazarrPosterResolver;
use App\Service\Media\BazarrSubtitleIndex;
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
        $wantedMovies = [];
        $wantedEpisodes = [];
        $counts = ['movies' => 0, 'episodes' => 0, 'providers' => 0];

        try {
            if (!$this->bazarr->ping()) {
                $error = true;
            } else {
                $counts = $this->bazarr->getBadgeCounts();
                $wantedMovies = $this->bazarr->getWantedMovies();
                $wantedEpisodes = $this->bazarr->getWantedEpisodes();
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Bazarr index failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('bazarr/index.html.twig', [
            'error'           => $error,
            'counts'          => $counts,
            'wanted_movies'   => $wantedMovies,
            'wanted_episodes' => $wantedEpisodes,
            'service_url'     => $this->config->get('bazarr_url'),
        ]);
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
     * @return array{cards: list<array{title: string, year: int|string|null, poster: string|null, substate: string, count: int, missingLangs: list<string>, seriesId: int|null}>, languages: list<string>}
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
     * JSON error. movieLanguages() already degrades to the untracked shape on
     * every one of those paths (an empty movie-langs map on a failed fetch
     * simply misses the lookup), so this action has nothing left to branch
     * on; a modal opening on a down Bazarr just renders no chips instead of
     * an error toast.
     */
    #[Route('/api/subtitles/movie/{radarrId}', name: 'api_subtitles_movie', methods: ['GET'], requirements: ['radarrId' => '\d+'])]
    public function apiSubtitlesMovie(int $radarrId): JsonResponse
    {
        return $this->json(['ok' => true, ...$this->bazarrIndex->movieLanguages($radarrId)]);
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
     * Drops the subtitle-status cache on success: the badge this movie renders
     * everywhere else is derived from Bazarr's missing-subtitle counts, which
     * this call just changed (spec §7.4).
     */
    #[Route('/api/download/movie', name: 'api_download_movie', methods: ['POST'])]
    public function apiDownloadMovie(Request $request): JsonResponse
    {
        $ok = $this->bazarr->downloadMovie($request->request->all());
        if ($ok) {
            $this->bazarrIndex->invalidate();
        }

        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Download a specific subtitle result for an episode. No CSRF token —
     * follows the Deluge convention (#[IsGranted] + same-origin fetch only).
     */
    #[Route('/api/download/episode', name: 'api_download_episode', methods: ['POST'])]
    public function apiDownloadEpisode(Request $request): JsonResponse
    {
        $ok = $this->bazarr->downloadEpisode($request->request->all());
        if ($ok) {
            $this->bazarrIndex->invalidate();
        }

        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Trigger Bazarr's automatic "search missing" for one Radarr movie.
     */
    #[Route('/api/auto/movie/{radarrId}', name: 'api_auto_movie', methods: ['POST'], requirements: ['radarrId' => '\d+'])]
    public function apiAutoMovie(int $radarrId): JsonResponse
    {
        $ok = $this->bazarr->searchMissingMovie($radarrId);
        if ($ok) {
            $this->bazarrIndex->invalidate();
        }

        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Bazarr', $this->bazarr);
    }

    /**
     * Trigger Bazarr's automatic "search missing" for one Sonarr series.
     */
    #[Route('/api/auto/series/{seriesId}', name: 'api_auto_series', methods: ['POST'], requirements: ['seriesId' => '\d+'])]
    public function apiAutoSeries(int $seriesId): JsonResponse
    {
        $ok = $this->bazarr->searchMissingSeries($seriesId);
        if ($ok) {
            $this->bazarrIndex->invalidate();
        }

        return $ok ? $this->json(['ok' => true]) : $this->jsonClientError('Bazarr', $this->bazarr);
    }
}
