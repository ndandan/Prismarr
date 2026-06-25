<?php

namespace App\Tests\Service\Media;

use App\Service\Media\MediaLibraryCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Short-TTL per-instance cache for the heavy Radarr/Sonarr library list.
 * ArrayAdapter is used as the contracts-cache backend so we exercise the
 * real get()/delete()/expiry semantics without the filesystem pool.
 */
class MediaLibraryCacheTest extends TestCase
{
    public function testMoviesFetchesOnceThenServesFromCache(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return [['id' => 1]]; };

        $first  = $cache->movies('radarr-1', $fetch);
        $second = $cache->movies('radarr-1', $fetch);

        $this->assertSame([['id' => 1]], $first);
        $this->assertSame([['id' => 1]], $second);
        $this->assertSame(1, $calls, 'second call must hit the cache, not re-fetch');
    }

    public function testEmptyResultIsNotCached(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return []; };

        $cache->movies('radarr-1', $fetch);
        $cache->movies('radarr-1', $fetch);

        $this->assertSame(2, $calls, 'empty result must expire immediately so the next load retries');
    }

    public function testInstancesAreKeyedIndependently(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());

        $a = $cache->movies('radarr-1', fn() => [['id' => 1]]);
        $b = $cache->movies('radarr-4k', fn() => [['id' => 99]]);

        $this->assertSame([['id' => 1]], $a);
        $this->assertSame([['id' => 99]], $b);
    }

    public function testMoviesAndSeriesDoNotCollide(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());

        $movies = $cache->movies('x-1', fn() => [['id' => 1]]);
        $series = $cache->series('x-1', fn() => [['id' => 2]]);

        $this->assertSame([['id' => 1]], $movies);
        $this->assertSame([['id' => 2]], $series);
    }

    public function testInvalidateDropsTheCachedList(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return [['id' => 1]]; };

        $cache->movies('radarr-1', $fetch);
        $cache->invalidate('radarr', 'radarr-1');
        $cache->movies('radarr-1', $fetch);

        $this->assertSame(2, $calls, 'invalidate() must force a re-fetch on the next load');
    }

    public function testInvalidateSonarrTargetsSeriesKey(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());
        $movieCalls = 0;
        $seriesCalls = 0;

        $cache->movies('s-1', function () use (&$movieCalls) { $movieCalls++; return [['id' => 1]]; });
        $cache->series('s-1', function () use (&$seriesCalls) { $seriesCalls++; return [['id' => 2]]; });

        $cache->invalidate('sonarr', 's-1');

        $cache->movies('s-1', function () use (&$movieCalls) { $movieCalls++; return [['id' => 1]]; });
        $cache->series('s-1', function () use (&$seriesCalls) { $seriesCalls++; return [['id' => 2]]; });

        $this->assertSame(1, $movieCalls, 'invalidating sonarr must not drop the movies cache');
        $this->assertSame(2, $seriesCalls, 'invalidating sonarr must drop the series cache');
    }
}
