<?php

namespace App\Service\Cache;

use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrLangs;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\Media\ServiceHealthCache;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds the Bazarr datasets inside the messenger-worker process. Exactly
 * two keys are *requestable* — KEY_MOVIES and KEY_SERIES — and each one fetch
 * writes every map derived from it, so a refresh costs at most one Bazarr call
 * per dataset per soft window (CONTEXT constraint #2).
 */
final class BazarrIndexRefresher implements CacheRefresherInterface
{
    public function __construct(
        private readonly BazarrClient $client,
        private readonly StaleWhileRevalidateCache $swr,
        private readonly ServiceHealthCache $health,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(string $key): bool
    {
        return $key === BazarrSubtitleIndex::KEY_MOVIES || $key === BazarrSubtitleIndex::KEY_SERIES;
    }

    public function refresh(string $key): void
    {
        // Idempotence: a duplicate message costs one cache read.
        $hit = $this->swr->read($key, BazarrSubtitleIndex::SOFT_TTL);
        if ($hit !== null && $hit['state'] === 'fresh') {
            return;
        }

        // Guardrail 5: respect the breaker instead of stacking 8 s timeouts.
        if ($this->health->isDown(BazarrClient::SERVICE)) {
            return;
        }

        $key === BazarrSubtitleIndex::KEY_MOVIES ? $this->refreshMovies() : $this->refreshSeries();
    }

    private function refreshMovies(): void
    {
        $rows = $this->client->getMovies();

        // Guardrail 6: only a clean fetch may overwrite. An unreachable
        // Bazarr yields [] plus a recorded lastError; caching that would
        // blank every badge for the whole hard window. Unlike
        // MediaLibraryRefresher, an EMPTY-but-clean result here is not
        // treated as a failure: BazarrClient::getMovies() also returns []
        // with lastError === null when Bazarr is simply unconfigured/
        // disabled (no HTTP call is even made), which is a legitimate,
        // permanent state — refusing to write in that case would leave every
        // read a hard miss forever (badges stuck on 'pending', a
        // RefreshCacheKey dispatched every 30 s, and an overdue-refresh error
        // logged after 180 s, none of which ever resolves). Every genuine
        // failure path of BazarrClient::request() DOES set lastError, so the
        // check above already covers guardrail 6's intent for this dataset.
        if ($this->client->getLastError() !== null) {
            $this->logger->warning('Bazarr movie index refresh failed', ['error' => $this->client->getLastError()]);

            return;
        }

        $status  = [];
        $langs   = [];
        $cards   = [];
        $langSet = [];
        $candidates = [];

        foreach ($rows as $m) {
            if (!isset($m['radarrId'])) {
                continue;
            }
            $id          = (int) $m['radarrId'];
            $st          = BazarrSubtitleIndex::computeMovieStatus($m);
            $status[$id] = $st;
            $langs[$id]  = BazarrSubtitleIndex::extractMovieLangs($m);

            $codes = [];
            foreach (BazarrLangs::extract($m)['missing'] as $lang) {
                $codes[$lang['lang']]   = true;
                $langSet[$lang['lang']] = true;
            }

            $cards[] = [
                'title'        => (string) ($m['title'] ?? ''),
                'year'         => $m['year'] ?? null,
                'substate'     => $st['state'] === 'hidden' ? 'not-tracked' : $st['state'],
                'count'        => $st['count'],
                'missingLangs' => array_keys($codes),
                'seriesId'     => null,
                'movieId'      => $id,
            ];

            if ($st['state'] === 'missing') {
                $candidates[] = [
                    'kind'         => 'movie', 'id' => $id,
                    'title'        => (string) ($m['title'] ?? '—'), 'year' => $m['year'] ?? null,
                    'missingCount' => $st['count'],
                ];
            }
        }

        ksort($langSet);
        usort($candidates, static fn (array $a, array $b): int
            => ($b['missingCount'] <=> $a['missingCount']) ?: strcasecmp($a['title'], $b['title']));
        $candidates = array_slice($candidates, 0, BazarrSubtitleIndex::MOST_MISSING_CANDIDATES);

        // /api/badges is one cheap call and belongs to the same refresh cycle;
        // giving it its own message would double queue traffic for nothing.
        $counts = $this->client->getBadgeCounts();

        // One timestamp for every key written from this fetch, so the group
        // shares a soft window instead of drifting apart. Badge maps are
        // written LAST so the shortest-lived surface is the last to flip
        // (keys cannot be written atomically together); cards/most_missing
        // precede the status/langs maps other callers gate their reads on.
        $now = time();
        $this->swr->write(BazarrSubtitleIndex::KEY_MOVIE_CARDS, ['cards' => $cards, 'languages' => array_keys($langSet)], BazarrSubtitleIndex::HARD_TTL, $now);
        $this->swr->write(BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, $candidates, BazarrSubtitleIndex::HARD_TTL, $now);
        $this->swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, $langs, BazarrSubtitleIndex::HARD_TTL, $now);
        $this->swr->write(BazarrSubtitleIndex::KEY_MOVIES, $status, BazarrSubtitleIndex::HARD_TTL, $now);
        $this->swr->write(BazarrSubtitleIndex::KEY_BADGES, $counts, BazarrSubtitleIndex::HARD_TTL, $now);
    }

    private function refreshSeries(): void
    {
        $rows = $this->client->getSeries();
        // See refreshMovies(): an empty-but-clean result is a legitimate
        // permanent state (unconfigured/disabled Bazarr, or a genuinely
        // empty Sonarr library), not a failure — only a recorded lastError
        // blocks the write.
        if ($this->client->getLastError() !== null) {
            $this->logger->warning('Bazarr series index refresh failed', ['error' => $this->client->getLastError()]);

            return;
        }

        $status     = [];
        $cards      = [];
        $langSet    = [];
        $candidates = [];

        foreach ($rows as $s) {
            if (!isset($s['sonarrSeriesId'])) {
                continue;
            }
            $id          = (int) $s['sonarrSeriesId'];
            $st          = BazarrSubtitleIndex::computeSeriesStatus($s);
            $status[$id] = $st;

            $codes = [];
            foreach (BazarrLangs::extract($s)['missing'] as $lang) {
                $codes[$lang['lang']]   = true;
                $langSet[$lang['lang']] = true;
            }

            $cards[] = [
                'title'        => (string) ($s['title'] ?? ''),
                'year'         => $s['year'] ?? null,
                'substate'     => $st['state'] === 'hidden' ? 'not-tracked' : $st['state'],
                'count'        => $st['count'],
                'missingLangs' => array_keys($codes),
                'seriesId'     => $id,
                'movieId'      => null,
            ];

            $missingCount = (int) ($s['episodeMissingCount'] ?? 0);
            if ($missingCount > 0) {
                $candidates[] = [
                    'kind'         => 'series', 'id' => $id,
                    'title'        => (string) ($s['title'] ?? '—'), 'year' => $s['year'] ?? null,
                    'missingCount' => $missingCount,
                ];
            }
        }

        ksort($langSet);
        usort($candidates, static fn (array $a, array $b): int
            => ($b['missingCount'] <=> $a['missingCount']) ?: strcasecmp($a['title'], $b['title']));
        $candidates = array_slice($candidates, 0, BazarrSubtitleIndex::MOST_MISSING_CANDIDATES);

        $now = time();
        $this->swr->write(BazarrSubtitleIndex::KEY_SERIES_CARDS, ['cards' => $cards, 'languages' => array_keys($langSet)], BazarrSubtitleIndex::HARD_TTL, $now);
        $this->swr->write(BazarrSubtitleIndex::KEY_MOST_MISSING_SERIES, $candidates, BazarrSubtitleIndex::HARD_TTL, $now);
        $this->swr->write(BazarrSubtitleIndex::KEY_SERIES, $status, BazarrSubtitleIndex::HARD_TTL, $now);
    }
}
