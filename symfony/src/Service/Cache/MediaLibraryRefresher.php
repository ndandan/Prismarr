<?php

namespace App\Service\Cache;

use App\Entity\ServiceInstance;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds `media.movies.<slug>` / `media.series.<slug>` inside the
 * messenger-worker process, so the ~3.5 s full-library fetch never occupies a
 * FrankenPHP web worker.
 *
 * LIBRARY_TIMEOUT is mandatory here: this entry is shared by the library
 * pages, the dashboard, the search index and BazarrPosterResolver, so
 * whichever code refills it decides the budget for all of them. The 8 s / 4 s
 * client defaults return [] on a large library, which is indistinguishable
 * from "the library is empty" (review 2026-08-28, finding 1).
 */
final class MediaLibraryRefresher implements CacheRefresherInterface
{
    private const PREFIX_MOVIES = 'media.movies.';
    private const PREFIX_SERIES = 'media.series.';

    public function __construct(
        private readonly ServiceInstanceProvider $instances,
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly StaleWhileRevalidateCache $swr,
        private readonly ServiceHealthCache $health,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(string $key): bool
    {
        return str_starts_with($key, self::PREFIX_MOVIES) || str_starts_with($key, self::PREFIX_SERIES);
    }

    public function refresh(string $key): void
    {
        $isMovies = str_starts_with($key, self::PREFIX_MOVIES);
        $slug     = substr($key, strlen($isMovies ? self::PREFIX_MOVIES : self::PREFIX_SERIES));
        if ($slug === '') {
            return;
        }

        // Idempotence: a duplicate message costs one cache read and stops here.
        $hit = $this->swr->read($key, MediaLibraryCache::TTL);
        if ($hit !== null && $hit['state'] === 'fresh') {
            return;
        }

        $type     = $isMovies ? ServiceInstance::TYPE_RADARR : ServiceInstance::TYPE_SONARR;
        $service  = $isMovies ? 'radarr' : 'sonarr';
        $instance = $this->instances->getBySlug($type, $slug);
        if ($instance === null || !$instance->isEnabled()) {
            return;
        }

        // Guardrail 5: never hammer a service its own breaker just marked down.
        if ($this->health->isDown($service, $slug)) {
            return;
        }

        try {
            $rows = $isMovies
                ? $this->radarr->withInstance($instance)->getMovies(RadarrClient::LIBRARY_TIMEOUT)
                : $this->sonarr->withInstance($instance)->getSeries(SonarrClient::LIBRARY_TIMEOUT);
        } catch (\Throwable $e) {
            $this->logger->warning('Library refresh failed', [
                'key' => $key, 'exception' => $e::class, 'message' => $e->getMessage(),
            ]);

            return;
        }

        // Guardrail 6: an empty list is a failed fetch — leave the good value alone.
        if ($rows === []) {
            return;
        }

        $this->swr->write($key, $rows, MediaLibraryCache::HARD_TTL);
    }
}
