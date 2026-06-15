<?php

namespace App\Service\Media;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Short-TTL cache for the heavy Radarr/Sonarr library list.
 *
 * The library pages re-fetch and re-normalize the entire library on every
 * visit, which on a large homelab is the dominant page-load cost. The list
 * changes slowly (adds/deletes/monitor toggles), so a short window is safe
 * and write-through invalidation keeps user actions instantly visible.
 *
 * Keyed per instance slug so one Radarr instance's library never masks
 * another's. An empty result is NOT cached (expires immediately) so a
 * transient total failure isn't pinned for the whole window — mirrors
 * DashboardController's self-heal.
 */
class MediaLibraryCache
{
    /** @internal Exposed for tests; matches DashboardController::WIDGET_CACHE_TTL. */
    public const TTL = 45; // seconds

    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    public function movies(string $slug, callable $fetch): array
    {
        return $this->fetchCached($this->key('movies', $slug), $fetch);
    }

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    public function series(string $slug, callable $fetch): array
    {
        return $this->fetchCached($this->key('series', $slug), $fetch);
    }

    /** Drop the cached list for an instance after a mutating action. */
    public function invalidate(string $type, string $slug): void
    {
        $kind = $type === 'sonarr' ? 'series' : 'movies';
        $this->cache->delete($this->key($kind, $slug));
    }

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    private function fetchCached(string $key, callable $fetch): array
    {
        return $this->cache->get($key, function (ItemInterface $item) use ($fetch) {
            $result = $fetch();
            $item->expiresAfter($result === [] ? 0 : self::TTL);
            return $result;
        });
    }

    private function key(string $kind, string $slug): string
    {
        return 'media.' . $kind . '.' . $slug;
    }
}
