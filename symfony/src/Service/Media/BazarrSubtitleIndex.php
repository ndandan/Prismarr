<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\ServiceInstanceProvider;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * radarrId/sonarrSeriesId -> Bazarr subtitle status lookup, for badge rendering.
 *
 * Two cache layers, because a full-library Bazarr fetch (`/movies?length=-1`)
 * on every typeahead search request and every quick-look open is far too
 * expensive for a decorative badge:
 *   1. a request-scoped memo (worker-mode safe via ResetInterface), and
 *   2. a 60 s cross-request cache in cache.app holding ONLY the computed
 *      status tuples — the raw Bazarr dicts never enter the pool.
 *
 * The pool is written only when the underlying fetch actually succeeded
 * (`BazarrClient::getLastError() === null`): caching a failed/empty fetch
 * would stretch the client's own 10 s circuit-breaker window into a 60 s
 * badge blackout. Mutating endpoints call invalidate() so a freshly
 * downloaded subtitle shows up immediately instead of up to 60 s later.
 *
 * @phpstan-type SubtitleStatus array{state: 'complete'|'missing'|'hidden', count: int}
 */
class BazarrSubtitleIndex implements ResetInterface
{
    /** @var SubtitleStatus */
    private const HIDDEN = ['state' => 'hidden', 'count' => 0];

    private const CACHE_KEY_MOVIES = 'bazarr_subtitle_index.movies';
    private const CACHE_KEY_SERIES = 'bazarr_subtitle_index.series';

    /** Seconds. Short enough that a stale badge self-heals without any action. */
    private const TTL = 60;

    /** @var array<int, SubtitleStatus>|null */
    private ?array $movies = null;

    /** @var array<int, SubtitleStatus>|null */
    private ?array $series = null;

    /** Per-request memo of the multi-instance gate (see gate()). */
    private ?bool $radarrGate = null;
    private ?bool $sonarrGate = null;

    public function __construct(
        private readonly BazarrClient $client,
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly ServiceInstanceProvider $instances,
    ) {
    }

    public function reset(): void
    {
        $this->movies     = null;
        $this->series     = null;
        $this->radarrGate = null;
        $this->sonarrGate = null;
    }

    /**
     * Drop the memo AND the cross-request pool entries. Called by the Bazarr
     * mutation endpoints (subtitle download / auto-search), which change the
     * very numbers the badges display.
     */
    public function invalidate(): void
    {
        $this->reset();
        $this->cacheApp->deleteItems([self::CACHE_KEY_MOVIES, self::CACHE_KEY_SERIES]);
    }

    /** @return SubtitleStatus */
    public function movieStatus(int $radarrId): array
    {
        // Gate first: on a multi-instance install nothing below may run — not
        // even a pool read — because the id is meaningless (see gate()).
        if (!$this->gate(ServiceInstance::TYPE_RADARR)) {
            return self::HIDDEN;
        }

        return $this->movieMap()[$radarrId] ?? self::HIDDEN;
    }

    /** @return SubtitleStatus */
    public function seriesStatus(int $sonarrSeriesId): array
    {
        if (!$this->gate(ServiceInstance::TYPE_SONARR)) {
            return self::HIDDEN;
        }

        return $this->seriesMap()[$sonarrSeriesId] ?? self::HIDDEN;
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
        return count($this->instances->getEnabled($type)) === 1;
    }

    /** @return array<int, SubtitleStatus> */
    private function movieMap(): array
    {
        if ($this->movies !== null) {
            return $this->movies;
        }

        $item   = $this->cacheApp->getItem(self::CACHE_KEY_MOVIES);
        $cached = $item->isHit() ? $item->get() : null;
        if (is_array($cached)) {
            /** @var array<int, SubtitleStatus> $cached */
            return $this->movies = $cached;
        }

        $map = [];
        foreach ($this->client->getMovies() as $m) {
            if (isset($m['radarrId'])) {
                $map[(int) $m['radarrId']] = self::computeMovieStatus($m);
            }
        }

        $this->store($item, $map);

        return $this->movies = $map;
    }

    /** @return array<int, SubtitleStatus> */
    private function seriesMap(): array
    {
        if ($this->series !== null) {
            return $this->series;
        }

        $item   = $this->cacheApp->getItem(self::CACHE_KEY_SERIES);
        $cached = $item->isHit() ? $item->get() : null;
        if (is_array($cached)) {
            /** @var array<int, SubtitleStatus> $cached */
            return $this->series = $cached;
        }

        $map = [];
        foreach ($this->client->getSeries() as $s) {
            if (isset($s['sonarrSeriesId'])) {
                $map[(int) $s['sonarrSeriesId']] = self::computeSeriesStatus($s);
            }
        }

        $this->store($item, $map);

        return $this->series = $map;
    }

    /**
     * Persist a freshly computed map — but ONLY if the fetch behind it was
     * clean. An unreachable Bazarr (or an open circuit breaker) yields an
     * empty list plus a recorded lastError; caching that would keep every
     * badge hidden for 60 s after the service came back.
     *
     * @param array<int, SubtitleStatus> $map
     */
    private function store(CacheItemInterface $item, array $map): void
    {
        if ($this->client->getLastError() !== null) {
            return;
        }

        $item->set($map);
        $item->expiresAfter(self::TTL);
        $this->cacheApp->save($item);
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
