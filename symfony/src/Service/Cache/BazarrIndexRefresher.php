<?php

namespace App\Service\Cache;

use App\Service\Media\BazarrClient;
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

        // Guardrail 6: only a clean, non-empty fetch may overwrite. An
        // unreachable Bazarr yields [] plus a recorded lastError; caching
        // that would blank every badge for the whole hard window. An empty
        // list with no recorded error is indistinguishable from "the library
        // is momentarily unreadable" here (mirrors MediaLibraryRefresher) —
        // leave the previous good value alone either way.
        if ($this->client->getLastError() !== null) {
            $this->logger->warning('Bazarr movie index refresh failed', ['error' => $this->client->getLastError()]);

            return;
        }
        if ($rows === []) {
            return;
        }

        $status = [];
        $langs  = [];
        foreach ($rows as $m) {
            if (!isset($m['radarrId'])) {
                continue;
            }
            $id          = (int) $m['radarrId'];
            $status[$id] = BazarrSubtitleIndex::computeMovieStatus($m);
            $langs[$id]  = BazarrSubtitleIndex::extractMovieLangs($m);
        }

        // One timestamp for every key written from this fetch, so the group
        // shares a soft window instead of drifting apart.
        $now = time();
        $this->swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, $langs, BazarrSubtitleIndex::HARD_TTL, $now);
        $this->swr->write(BazarrSubtitleIndex::KEY_MOVIES, $status, BazarrSubtitleIndex::HARD_TTL, $now);
    }

    private function refreshSeries(): void
    {
        $rows = $this->client->getSeries();
        if ($this->client->getLastError() !== null) {
            $this->logger->warning('Bazarr series index refresh failed', ['error' => $this->client->getLastError()]);

            return;
        }
        if ($rows === []) {
            return;
        }

        $status = [];
        foreach ($rows as $s) {
            if (isset($s['sonarrSeriesId'])) {
                $status[(int) $s['sonarrSeriesId']] = BazarrSubtitleIndex::computeSeriesStatus($s);
            }
        }

        $this->swr->write(BazarrSubtitleIndex::KEY_SERIES, $status, BazarrSubtitleIndex::HARD_TTL, time());
    }
}
