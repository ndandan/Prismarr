<?php

namespace App\Controller;

use App\Entity\ServiceInstance;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TautulliClient;
use App\Service\Media\TmdbClient;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dashboard landing page — aggregates the most relevant recent signals from
 * every configured service into a single screen. Every widget fails open:
 * if a client throws (service down, misconfigured, HTTP timeout) we log a
 * warning and render the widget in its empty state rather than crash the
 * whole page. Session 9c will wire the UI preferences (timezone, date
 * format, density…) into this template.
 */
class DashboardController extends AbstractController
{
    private const UPCOMING_DAYS       = 7;
    private const MAX_REQUESTS        = 5;
    private const MAX_RECOMMENDATIONS = 16;
    private const MAX_RECENT          = 16;
    private const MAX_WATCHLIST       = 16;
    // #30 — short server-side cache for the heavy per-widget upstream calls.
    // Radarr/Sonarr aren't client-cached (unlike TMDb), so the async widget
    // fragments would each hit them live; this dedupes the calls shared across
    // the parallel fragments and makes revisits within the window instant.
    private const WIDGET_CACHE_TTL    = 45;

    /**
     * Per-request memoization for the expensive library listings — each
     * of `getMovies()` / `getSeries()` is called by 3 different widgets
     * (stats, recent additions, hero spotlight). Without this cache every
     * dashboard paint would hit Radarr/Sonarr 3× for the same payload.
     * @var list<array<string, mixed>>|null
     */
    private ?array $moviesCache = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $seriesCache = null;

    public function __construct(
        private readonly HealthService $health,
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly JellyseerrClient $jellyseerr,
        private readonly TmdbClient $tmdb,
        private readonly WatchlistItemRepository $watchlistRepo,
        private readonly ServiceInstanceProvider $instances,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly CacheInterface $cache,
        private readonly TautulliClient $tautulli,
    ) {}

    /**
     * #30 — wrap an expensive upstream aggregate in a short shared cache.
     * An empty result (every instance failed / nothing configured) is NOT
     * cached: it expires immediately so the next paint retries instead of
     * pinning a transient failure for the whole window. Keyed globally (the
     * dashboard aggregates across instances); the TTL self-heals topology
     * changes and the pool is purged on /admin/settings save.
     *
     * @param callable():array $fn
     * @return array<mixed>
     */
    private function cached(string $key, callable $fn): array
    {
        return $this->cache->get('dash.' . $key, function (ItemInterface $item) use ($fn) {
            $result = $fn();
            $item->expiresAfter($result === [] ? 0 : self::WIDGET_CACHE_TTL);
            return $result;
        });
    }

    /**
     * Slug helper for the deep-link URLs the dashboard renders into films
     * and series. Dashboard pages aren't instance-scoped (they aggregate
     * across instances), so we always link to the user's default Radarr /
     * Sonarr — the films/series page itself can switch via the sidebar.
     */
    private function defaultSlug(string $type): string
    {
        return $this->instances->getDefault($type)?->getSlug() ?? $type . '-1';
    }

    /**
     * Aggregate Radarr movies across every enabled instance, tagging each
     * row with `_instanceSlug` so the consumers (recent additions, hero
     * spotlight) can deep-link to the right instance instead of always
     * pointing at the default. Same fan-out / per-request memoization
     * pattern as before — `safeFetch` swallows per-instance failures so
     * one ailing Radarr 4K doesn't blank out the whole dashboard.
     */
    private function movies(): array
    {
        return $this->moviesCache ??= $this->cached('movies', function () {
            $out = [];
            foreach ($this->instances->getEnabled(ServiceInstance::TYPE_RADARR) as $inst) {
                $rows = $this->safeFetch(
                    'library.movies.' . $inst->getSlug(),
                    fn() => $this->radarr->withInstance($inst)->getMovies(),
                ) ?? [];
                foreach ($rows as $row) {
                    $row['_instanceSlug'] = $inst->getSlug();
                    $row['_instanceName'] = $inst->getName();
                    $out[] = $row;
                }
            }
            return $out;
        });
    }

    private function series(): array
    {
        return $this->seriesCache ??= $this->cached('series', function () {
            $out = [];
            foreach ($this->instances->getEnabled(ServiceInstance::TYPE_SONARR) as $inst) {
                $rows = $this->safeFetch(
                    'library.series.' . $inst->getSlug(),
                    fn() => $this->sonarr->withInstance($inst)->getSeries(),
                ) ?? [];
                foreach ($rows as $row) {
                    $row['_instanceSlug'] = $inst->getSlug();
                    $row['_instanceName'] = $inst->getName();
                    $out[] = $row;
                }
            }
            return $out;
        });
    }

    #[Route('/tableau-de-bord', name: 'app_dashboard')]
    public function index(): Response
    {
        // Issue #27 — every upstream-dependent widget (hero, stats, upcoming,
        // requests, health, recommendations, recent additions) now loads from
        // its own async fragment endpoint after first paint, so index() makes
        // no remote calls: it renders the shell + skeletons instantly. Only the
        // watchlist (local DB) and the cheap service-configured flags stay here.
        $configured = [
            'radarr'     => $this->health->isConfigured('radarr'),
            'sonarr'     => $this->health->isConfigured('sonarr'),
            'jellyseerr' => $this->health->isConfigured('jellyseerr'),
            'tmdb'       => $this->health->isConfigured('tmdb'),
            'tautulli'   => $this->health->isConfigured('tautulli'),
        ];

        return $this->render('dashboard/index.html.twig', [
            'watchlist'           => $this->watchlist(),
            'services_configured' => $configured,
        ]);
    }

    /**
     * Async fragment (#27) — the "Recent additions" poster row, fetched after
     * the dashboard shell has painted so the initial render stays fast. Fails
     * open: an empty body (no items / fetch failure) hides the section
     * client-side. Skipped entirely when neither library is configured.
     */
    #[Route('/tableau-de-bord/widget/recent', name: 'app_dashboard_widget_recent')]
    public function widgetRecent(): Response
    {
        if (!$this->health->isConfigured('radarr') && !$this->health->isConfigured('sonarr')) {
            return new Response('');
        }
        set_time_limit(60);

        return $this->render('dashboard/_recent_additions.html.twig', [
            'recent_additions' => $this->recentAdditions(),
        ]);
    }

    /**
     * Async fragment (#27) — TMDb trending poster row. Empty body hides the
     * section client-side. Skipped when TMDb isn't configured.
     */
    #[Route('/tableau-de-bord/widget/recommendations', name: 'app_dashboard_widget_recommendations')]
    public function widgetRecommendations(): Response
    {
        if (!$this->health->isConfigured('tmdb')) {
            return new Response('');
        }
        set_time_limit(60);

        return $this->render('dashboard/_recommendations.html.twig', [
            'recommendations' => $this->recommendations(),
        ]);
    }

    /**
     * Async fragment (#27) — the upcoming 7-day mini-calendar. Returns the
     * empty-state message when there's nothing (so the card keeps showing);
     * an empty body only happens when neither library is configured, hiding
     * the card client-side.
     */
    #[Route('/tableau-de-bord/widget/upcoming', name: 'app_dashboard_widget_upcoming')]
    public function widgetUpcoming(): Response
    {
        if (!$this->health->isConfigured('radarr') && !$this->health->isConfigured('sonarr')) {
            return new Response('');
        }
        set_time_limit(60);
        $upcoming = $this->upcomingReleases();

        return $this->render('dashboard/_upcoming.html.twig', [
            'upcoming'      => $upcoming,
            'upcoming_days' => $this->upcomingByDay($upcoming),
        ]);
    }

    /**
     * Async fragment (#27) — pending Jellyseerr requests list. Only reached
     * when Jellyseerr is configured (the card is otherwise not rendered);
     * returns the empty-state message when there are no pending requests.
     */
    #[Route('/tableau-de-bord/widget/requests', name: 'app_dashboard_widget_requests')]
    public function widgetRequests(): Response
    {
        if (!$this->health->isConfigured('jellyseerr')) {
            return new Response('');
        }
        set_time_limit(60);

        return $this->render('dashboard/_requests.html.twig', [
            'jellyseerr_requests' => $this->pendingRequests(),
        ]);
    }

    /**
     * Async fragment (#27) — services health chips. The card is always present;
     * the pings can be slow (timeouts on a down service), so it loads after
     * first paint. Unconfigured services come back as null and are filtered out.
     */
    #[Route('/tableau-de-bord/widget/health', name: 'app_dashboard_widget_health')]
    public function widgetHealth(): Response
    {
        set_time_limit(60);

        return $this->render('dashboard/_health.html.twig', [
            'services_health' => $this->servicesHealth(),
        ]);
    }

    /**
     * Async fragment — current Plex activity from Tautulli. Skipped entirely
     * (empty body → hidden client-side) when Tautulli isn't configured /
     * enabled. Otherwise renders the widget body; the fragment re-fetches on a
     * 10 s interval (see index.html.twig) and fails open to an error state, so
     * a down/misconfigured Tautulli never breaks the dashboard.
     */
    #[Route('/tableau-de-bord/widget/plex', name: 'app_dashboard_widget_plex')]
    public function widgetPlex(): Response
    {
        if (!$this->health->isConfigured('tautulli')) {
            return new Response('');
        }
        set_time_limit(60);

        $activity = $this->tautulli->getActivity();

        // History changes slowly; cache it ~60s so the 10s now-playing poll
        // doesn't hit get_history every time. The relative time label is now
        // formatted in the template via the relative_date Twig filter.
        $history = $this->cached('plex_history', fn() => $this->tautulli->getHistory(8));

        $streaming = ($activity['streamCount'] ?? 0) > 0;

        return $this->render('dashboard/_plex_activity.html.twig', [
            'plex'        => $activity,
            'plex_history'=> $history,
            'plex_tab'    => $streaming ? 'now' : 'recent',
        ]);
    }

    /**
     * Async fragment (#27) — hero spotlight + library stats. Pulls the three
     * heaviest sources (TMDb recommendations, Radarr/Sonarr library counts and
     * the upcoming count) off the initial render; the greeting/clock stay in
     * index.html.twig so the hero shell paints instantly.
     */
    #[Route('/tableau-de-bord/widget/hero', name: 'app_dashboard_widget_hero')]
    public function widgetHero(): Response
    {
        set_time_limit(60);
        $recommendations = $this->health->isConfigured('tmdb') ? $this->recommendations() : [];
        $upcoming = ($this->health->isConfigured('radarr') || $this->health->isConfigured('sonarr'))
            ? $this->upcomingReleases() : [];

        return $this->render('dashboard/_hero.html.twig', [
            'hero_spotlight' => $this->pickHeroSpotlight($recommendations),
            'stats'          => $this->stats(),
            'upcoming'       => $upcoming,
        ]);
    }

    /**
     * @return array{films: ?int, series: ?int}
     */
    private function stats(): array
    {
        // qBittorrent counts used to live here but a single getTorrents()
        // call on a loaded daemon can add several seconds to the dashboard
        // paint. Active-download monitoring belongs on /qbittorrent anyway,
        // so the widget and the "en cours" hero stat were dropped.
        $movies = $this->movies();
        $series = $this->series();

        return [
            'films'  => $movies === [] ? null : count($movies),
            'series' => $series === [] ? null : count($series),
        ];
    }

    /**
     * Merges Radarr + Sonarr calendars over the next N days, keeping only
     * items with a future release/air date. Radarr's `getCalendar(7, 0)`
     * returns movies whose *any* of {digitalAt, inCinemasAt, physicalAt}
     * falls inside the window — so a movie that came out 2 months ago in
     * cinemas but has a Blu-ray release next week will appear. We pick the
     * earliest future date per item (and its matching badge) so the user
     * sees the genuinely upcoming event, not a stale one.
     */
    private function upcomingReleases(): array
    {
        return $this->cached('upcoming', function () {
        // Compare by calendar day (midnight) so events earlier today —
        // a morning episode, a midnight digital release — are still
        // classified as "today" rather than silently filtered out as past.
        $today = new \DateTimeImmutable('today');

        // Phase D — fan out across every enabled Radarr/Sonarr instance and
        // dedupe identical entries. Two Radarr instances both tracking the
        // same movie would otherwise double the upcoming card; we collapse
        // by (type, tmdbId/year/title) and keep the earliest date.
        $items = [];
        $movieKey = fn(array $m): string => 'movie:' . ($m['tmdbId'] ?? ($m['title'] ?? '?') . ':' . ($m['year'] ?? '?'));
        // tvdbId is stable across Sonarr instances; seriesId is per-instance
        // and would split the same episode into 2 rows when two Sonarr
        // instances both follow the show. SonarrClient::getCalendar()
        // surfaces tvdbId since v1.1.0 — fall back to seriesTitle for
        // legacy payloads that omit it.
        $episodeKey = fn(array $e): string => 'episode:'
            . ($e['tvdbId'] ?? $e['seriesTitle'] ?? '?')
            . ':S' . ($e['season'] ?? 0) . 'E' . ($e['episode'] ?? 0);
        $seen = [];

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_RADARR) as $inst) {
            $movies = $this->safeFetch(
                'upcoming.radarr.' . $inst->getSlug(),
                fn() => $this->radarr->withInstance($inst)->getCalendar(self::UPCOMING_DAYS, 0),
            ) ?? [];
            foreach ($movies as $m) {
                $next = $this->pickNextReleaseDate($m, $today);
                if ($next === null) continue;
                $key = $movieKey($m);
                if (isset($seen[$key])) continue; // first instance to surface the movie wins
                $seen[$key] = true;
                $items[] = [
                    'type'     => 'movie',
                    'id'       => $m['id'] ?? null,
                    'title'    => $m['title'] ?? '—',
                    'subtitle' => $m['year'] ? ((string) $m['year']) : null,
                    'badge'    => $next['badge'],
                    'poster'   => $m['poster'] ?? null,
                    'date'     => $next['at'],
                ];
            }
        }
        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_SONARR) as $inst) {
            $episodes = $this->safeFetch(
                'upcoming.sonarr.' . $inst->getSlug(),
                fn() => $this->sonarr->withInstance($inst)->getCalendar(self::UPCOMING_DAYS, 0),
            ) ?? [];
            foreach ($episodes as $e) {
                $airDate = $e['airDate'] ?? null;
                if (!$airDate instanceof \DateTimeImmutable) continue;
                if ($airDate->setTime(0, 0) < $today) continue;
                $key = $episodeKey($e);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $sxe = sprintf('S%02dE%02d', $e['season'] ?? 0, $e['episode'] ?? 0);
                $items[] = [
                    'type'     => 'episode',
                    'id'       => $e['seriesId'] ?? null,
                    'title'    => $e['seriesTitle'] ?? '—',
                    'subtitle' => $sxe . ($e['title'] && $e['title'] !== '—' ? ' — ' . $e['title'] : ''),
                    'badge'    => $e['network'] ?? null,
                    'poster'   => $e['poster'] ?? null,
                    'date'     => $airDate,
                ];
            }
        }

        usort($items, fn($a, $b) => $a['date'] <=> $b['date']);

        return $items;
        });
    }

    /**
     * Group an already-sorted list of upcoming items into a 7-day calendar
     * structure [iso_date => {date, dayOfWeek, dayOfMonth, isToday, items}].
     * Missing days are still present with an empty items[] so the template
     * can render a fixed 7-column grid.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, array{date: \DateTimeImmutable, dow: string, dom: int, isToday: bool, items: list<array<string, mixed>>}>
     */
    private function upcomingByDay(array $items): array
    {
        $today = (new \DateTimeImmutable('today'));
        $days = [];

        for ($d = 0; $d < self::UPCOMING_DAYS; $d++) {
            $date = $today->modify("+{$d} days");
            $iso  = $date->format('Y-m-d');
            $days[$iso] = [
                'date'    => $date,
                'dow'     => mb_strtoupper(mb_substr($this->localizedDayName($date), 0, 3)),
                'dom'     => (int) $date->format('j'),
                'isToday' => $d === 0,
                'items'   => [],
            ];
        }

        foreach ($items as $item) {
            if (!$item['date'] instanceof \DateTimeImmutable) {
                continue;
            }
            $iso = $item['date']->format('Y-m-d');
            if (isset($days[$iso])) {
                $days[$iso]['items'][] = $item;
            }
        }

        return $days;
    }

    private function localizedDayName(\DateTimeImmutable $d): string
    {
        $keys = [
            'dashboard.weekdays.mon', 'dashboard.weekdays.tue', 'dashboard.weekdays.wed',
            'dashboard.weekdays.thu', 'dashboard.weekdays.fri', 'dashboard.weekdays.sat',
            'dashboard.weekdays.sun',
        ];

        return $this->translator->trans($keys[(int) $d->format('N') - 1]);
    }

    /**
     * Return the earliest release date that is today or later for a Radarr
     * movie, together with a human-readable badge identifying which date
     * it is (digital / cinema / physical). The comparison is done at
     * calendar-day granularity so a digital release set to 02:00 today
     * still counts as "today" even at 14:00.
     * Null if every date is strictly in the past or missing.
     *
     * @param array<string, mixed> $movie
     * @return array{at: \DateTimeImmutable, badge: string}|null
     */
    private function pickNextReleaseDate(array $movie, \DateTimeImmutable $today): ?array
    {
        $candidates = array_filter([
            ['at' => $movie['digitalAt']   ?? null, 'badge' => $this->translator->trans('dashboard.release_badge.digital')],
            ['at' => $movie['inCinemasAt'] ?? null, 'badge' => $this->translator->trans('dashboard.release_badge.cinema')],
            ['at' => $movie['physicalAt']  ?? null, 'badge' => $this->translator->trans('dashboard.release_badge.bluray')],
        ], fn($c) => $c['at'] instanceof \DateTimeImmutable && $c['at']->setTime(0, 0) >= $today);

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn($a, $b) => $a['at'] <=> $b['at']);

        return $candidates[0];
    }

    /**
     * Jellyseerr's `/request` API returns `media.tmdbId` but no title or
     * poster. We enrich each entry via TmdbClient — detail lookups are
     * cached 1h by the client so the overhead is amortized across paints.
     * Capped at MAX_REQUESTS (5) so a spike in pending requests can't
     * balloon the paint cost.
     *
     * @return list<array{id: int, type: string, tmdbId: int, title: string, poster: ?string, requestedBy: string, requestedAt: ?string}>
     */
    private function pendingRequests(): array
    {
        $data = $this->safeFetch(
            'jellyseerr.requests',
            fn() => $this->jellyseerr->getRequests(self::MAX_REQUESTS, 0, 'pending'),
        ) ?? [];

        $results = $data['results'] ?? $data;
        $out = [];

        foreach ($results as $req) {
            $tmdbId    = $req['media']['tmdbId'] ?? null;
            $mediaType = $req['media']['mediaType'] ?? $req['type'] ?? 'movie';
            if (!$tmdbId) {
                continue;
            }

            $details = $this->safeFetch(
                "jellyseerr.tmdb.{$mediaType}.{$tmdbId}",
                fn() => $mediaType === 'tv'
                    ? $this->tmdb->getTv((int) $tmdbId)
                    : $this->tmdb->getMovie((int) $tmdbId),
            ) ?? [];

            $out[] = [
                'id'          => (int) ($req['id'] ?? 0),
                'type'        => $mediaType,
                'tmdbId'      => (int) $tmdbId,
                'title'       => $details['title'] ?? $details['name'] ?? ('TMDb #' . $tmdbId),
                'poster'      => $details['poster_path'] ?? null,
                'requestedBy' => $req['requestedBy']['displayName'] ?? $req['requestedBy']['email'] ?? '—',
                'requestedAt' => $req['createdAt'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, name: string, status: string, latencyMs: ?int}>
     *
     * v1.1.0 — radarr/sonarr expand to one chip PER enabled instance, named
     * after the instance (Radarr 1080p, Radarr 4K…), matching the topbar
     * dropdown / `/api/health/services` rather than collapsing to a single
     * aggregate dot. Mono-instance services (prowlarr, jellyseerr, qbit, tmdb)
     * keep one chip each. Unconfigured entries (isHealthy null) are dropped.
     */
    private function servicesHealth(): array
    {
        $chips = [];

        foreach ([ServiceInstance::TYPE_RADARR, ServiceInstance::TYPE_SONARR] as $type) {
            foreach ($this->instances->getEnabled($type) as $inst) {
                try {
                    $s = $this->health->statusFor($type, $inst->getSlug());
                } catch (\Throwable) {
                    $s = ['status' => 'down', 'latencyMs' => null];
                }
                if ($s['status'] === null) continue; // instance has no credentials yet
                $chips[] = ['id' => $type, 'name' => $inst->getName(), 'status' => $s['status'], 'latencyMs' => $s['latencyMs']];
            }
        }

        $labels = ['prowlarr' => 'Prowlarr', 'jellyseerr' => 'Seerr', 'qbittorrent' => 'qBittorrent', 'tmdb' => 'TMDb', 'tautulli' => 'Tautulli'];
        foreach ($labels as $service => $label) {
            try {
                $s = $this->health->statusFor($service);
            } catch (\Throwable) {
                $s = ['status' => null, 'latencyMs' => null];
            }
            if ($s['status'] === null) continue; // not configured / disabled
            $chips[] = ['id' => $service, 'name' => $label, 'status' => $s['status'], 'latencyMs' => $s['latencyMs']];
        }

        return $chips;
    }

    private function recommendations(): array
    {
        $payload = $this->safeFetch(
            'tmdb.trending',
            fn() => $this->tmdb->getTrendingAll('week'),
        ) ?? [];

        // TMDb wraps its lists in {page, results: [...], total_pages, ...}.
        // Slicing the wrapper directly would yield scalar metadata entries.
        $items = $payload['results'] ?? [];

        return array_slice($items, 0, self::MAX_RECOMMENDATIONS);
    }

    /**
     * Merged list of the most recently added Radarr movies + Sonarr series,
     * sorted by `addedAt` desc. Used for the single "Recent additions" row at
     * the bottom of the dashboard.
     */
    private function recentAdditions(): array
    {
        $movies = $this->movies();
        $series = $this->series();

        $epoch = new \DateTimeImmutable('1970-01-01');
        $items = [];

        $now = new \DateTimeImmutable();
        foreach ($movies as $m) {
            $downloaded = ($m['hasFile'] ?? false) === true;
            // _instanceSlug is set by movies() per row — link points at the
            // exact instance the movie lives in (Radarr default OR 4K OR
            // anime) so clicking the tile lands the user on the right page.
            $slug = $m['_instanceSlug'] ?? $this->defaultSlug(ServiceInstance::TYPE_RADARR);
            $items[] = [
                'type'         => 'movie',
                'title'        => $m['title'] ?? '—',
                'subtitle'     => $this->relativeDate($m['addedAt'] ?? null, $now),
                'poster'       => $m['poster'] ?? null,
                'badge'        => $downloaded ? $this->translator->trans('dashboard.lib_badge.downloaded') : null,
                'is_downloaded'=> $downloaded,
                'addedAt'      => $m['addedAt'] ?? null,
                'href'         => $this->generateUrl('app_media_films', ['slug' => $slug]) . '?open=' . ($m['id'] ?? ''),
            ];
        }
        foreach ($series as $s) {
            $slug = $s['_instanceSlug'] ?? $this->defaultSlug(ServiceInstance::TYPE_SONARR);
            $items[] = [
                'type'     => 'series',
                'title'    => $s['title'] ?? '—',
                'subtitle' => $this->relativeDate($s['addedAt'] ?? null, $now),
                'poster'   => $s['poster'] ?? null,
                'badge'    => $s['network'] ?? null,
                'addedAt'  => $s['addedAt'] ?? null,
                'href'     => $this->generateUrl('app_media_series', ['slug' => $slug]) . '?open=' . ($s['id'] ?? ''),
            ];
        }

        usort($items, fn($a, $b) => ($b['addedAt'] ?? $epoch) <=> ($a['addedAt'] ?? $epoch));

        return array_slice($items, 0, self::MAX_RECENT);
    }

    /**
     * Find a single library row for the quick-look. Prefers the already-cached
     * movies()/series() aggregate (zero upstream calls on a warm dashboard);
     * falls back to a direct client fetch for the given instance on a miss.
     *
     * @return array<string, mixed>|null
     */
    private function findLibraryRow(string $type, string $slug, int $id): ?array
    {
        $rows = $type === 'series' ? $this->series() : $this->movies();
        $match = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) !== $id) {
                continue;
            }
            // Prefer the row from the requested instance; otherwise keep the first hit.
            if (($row['_instanceSlug'] ?? null) === $slug) {
                return $row;
            }
            $match ??= $row;
        }
        if ($match !== null) {
            return $match;
        }

        // Cache miss / not loaded — fetch directly from the requested instance.
        $svcType = $type === 'series' ? ServiceInstance::TYPE_SONARR : ServiceInstance::TYPE_RADARR;
        $inst = $this->instances->getBySlug($svcType, $slug);
        if ($inst === null) {
            return null;
        }
        return $this->safeFetch(
            "quicklook.{$type}.{$slug}.{$id}",
            fn() => $type === 'series'
                ? $this->sonarr->withInstance($inst)->getSerie($id)
                : $this->radarr->withInstance($inst)->getMovie($id),
        );
    }

    /**
     * Build the read-only quick-look view-model for a Radarr/Sonarr library item.
     * Returns null when the item can't be resolved (caller renders a graceful body).
     *
     * @return array<string, mixed>|null
     */
    private function quickLookLibrary(string $type, string $slug, int $id): ?array
    {
        $row = $this->findLibraryRow($type, $slug, $id);
        if ($row === null) {
            return null;
        }

        $hasFile   = ($row['hasFile'] ?? false) === true;
        $monitored = ($row['monitored'] ?? false) === true;
        $badgeKind = $hasFile ? 'downloaded' : ($monitored ? 'monitored' : 'missing');
        $badgeKey  = 'dashboard.quicklook.status.' . $badgeKind;

        if ($type === 'series') {
            $metaLine  = $row['network'] ?? null;
            $actionUrl = $this->generateUrl('app_media_series', ['slug' => $slug]) . '?open=' . $id;
        } else {
            $runtime   = $row['runtime'] ?? null;
            $metaLine  = $runtime ? ($runtime . ' min') : null;
            $actionUrl = $this->generateUrl('app_media_films', ['slug' => $slug]) . '?open=' . $id;
        }

        return [
            'title'       => $row['title'] ?? '—',
            'year'        => $row['year'] ?? null,
            'poster'      => $row['poster'] ?? null,
            'backdrop'    => $row['fanart'] ?? null,
            'overview'    => $row['overview'] ?? null,
            'genres'      => array_slice($row['genres'] ?? [], 0, 4),
            'rating'      => $row['ratings'] ?? null,
            'metaLine'    => $metaLine,
            'statusBadge' => ['label' => $this->translator->trans($badgeKey), 'kind' => $badgeKind],
            'actionUrl'   => $actionUrl,
            'actionLabel' => $this->translator->trans('dashboard.quicklook.manage'),
        ];
    }

    /**
     * Personal watchlist — up to MAX_WATCHLIST most recently starred items.
     * Read straight from the local DB so it's always fast even when every
     * remote service is down. Each item carries tmdbId + mediaType so the
     * dashboard tile can deep-link into the Discover modal.
     */
    private function watchlist(): array
    {
        try {
            $items = $this->watchlistRepo->findAllOrdered();
        } catch (\Throwable $e) {
            $this->logger->warning('Dashboard watchlist failed: {msg}', ['msg' => $e->getMessage()]);
            return [];
        }

        return array_slice(array_map(fn($w) => [
            'tmdbId'    => $w->getTmdbId(),
            'mediaType' => $w->getMediaType(),
            'title'     => $w->getTitle(),
            'poster'    => $w->getPosterPath(),
            'year'      => $w->getYear(),
            'vote'      => $w->getVote(),
        ], $items), 0, self::MAX_WATCHLIST);
    }

    /**
     * Pick a "spotlight" movie for the hero banner. Priority:
     *   1. A random movie from the local Radarr library with a fanart —
     *      "Envie de le revoir ?" vibe, feels personal because it's
     *      already in the user's collection.
     *   2. Otherwise fall back to a TMDb trending item (first result with
     *      a backdrop_path).
     *   3. Null if neither source yields anything → flat gradient hero.
     *
     * @param list<array<string, mixed>> $recommendations
     */
    private function pickHeroSpotlight(array $recommendations): ?array
    {
        $withFanart = array_values(array_filter($this->movies(), fn($m) => !empty($m['fanart']) && !empty($m['title'])));

        if ($withFanart !== []) {
            $m = $withFanart[array_rand($withFanart)];
            // Pick the slug of the instance the spotlight movie actually lives
            // in (multi-instance) so the CTA opens the right page. _instanceSlug
            // is injected by movies(); fall back on default for safety.
            $slug = $m['_instanceSlug'] ?? $this->defaultSlug(ServiceInstance::TYPE_RADARR);
            return [
                'source'    => 'library',
                'url'       => $m['fanart'],
                'title'     => $m['title'],
                'overview'  => $this->truncate($m['overview'] ?? null, 220),
                'year'      => $m['year'] ?? null,
                'runtime'   => $m['runtime'] ?? null,
                'quality'   => $m['quality'] ?? null,
                'rating'    => $m['ratings'] ?? null,
                'genres'    => array_slice($m['genres'] ?? [], 0, 3),
                'badge'     => $m['hasFile']
                    ? $this->translator->trans('dashboard.hero_badge.in_library')
                    : $this->translator->trans('dashboard.hero_badge.monitored'),
                'cta'       => $this->translator->trans('dashboard.hero_badge.cta_view'),
                'detailUrl' => $m['id'] ? $this->generateUrl('app_media_films', ['slug' => $slug]) . '?open=' . $m['id'] : null,
            ];
        }

        foreach ($recommendations as $item) {
            if (!empty($item['backdrop_path'])) {
                $type = ($item['media_type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
                $year = !empty($item['release_date']) ? (int) substr($item['release_date'], 0, 4)
                      : (!empty($item['first_air_date']) ? (int) substr($item['first_air_date'], 0, 4) : null);
                return [
                    'source'    => 'tmdb',
                    'url'       => 'https://image.tmdb.org/t/p/w1280' . $item['backdrop_path'],
                    'title'     => $item['title'] ?? $item['name'] ?? null,
                    'overview'  => $this->truncate($item['overview'] ?? null, 220),
                    'year'      => $year,
                    'runtime'   => null,
                    'quality'   => null,
                    'rating'    => $item['vote_average'] ?? null,
                    'genres'    => [],
                    'badge'     => $this->translator->trans('dashboard.hero_badge.trending'),
                    'cta'       => $this->translator->trans('dashboard.hero_badge.cta_discover'),
                    'detailUrl' => $item['id'] ? $this->generateUrl('tmdb_index') . '?detail=' . $type . '/' . $item['id'] : null,
                ];
            }
        }

        return null;
    }

    /**
     * Friendly "Aujourd'hui / Hier / il y a 3 j / il y a 2 sem." label
     * for a past DateTimeImmutable, or null if date is missing.
     */
    private function relativeDate(?\DateTimeImmutable $at, \DateTimeImmutable $now): ?string
    {
        if ($at === null) {
            return null;
        }

        $days = (int) $now->diff($at)->days;
        if ($days === 0) return $this->translator->trans('dashboard.relative.today');
        if ($days === 1) return $this->translator->trans('dashboard.relative.yesterday');
        if ($days < 7)   return $this->translator->trans('dashboard.relative.days_ago',   ['count' => $days]);
        if ($days < 30)  return $this->translator->trans('dashboard.relative.weeks_ago',  ['count' => (int) round($days / 7)]);
        if ($days < 365) return $this->translator->trans('dashboard.relative.months_ago', ['count' => (int) round($days / 30)]);
        return $this->translator->trans('dashboard.relative.years_ago', ['count' => (int) round($days / 365)]);
    }

    private function truncate(?string $s, int $max): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }

    /**
     * Execute a fetch that may hit a remote service and return `null` on any
     * failure (with a warning logged). Keeps the dashboard resilient: a dead
     * Radarr doesn't mean a blank page, just an empty widget.
     *
     * "Service not configured" is a deliberate user state (issue #9 — they
     * never enabled Jellyseerr / VPN / etc.), so we skip it silently rather
     * than spamming a warning every dashboard render.
     */
    private function safeFetch(string $label, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\App\Exception\ServiceNotConfiguredException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Dashboard widget failed [{label}]: {message}', [
                'label'     => $label,
                'message'   => $e->getMessage(),
                'exception' => $e::class,
            ]);
            return null;
        }
    }
}
