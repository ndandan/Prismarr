<?php

namespace App\Tests\Service\Media;

use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\MediaLibraryCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Short-TTL per-instance cache for the heavy Radarr/Sonarr library list.
 * ArrayAdapter is used as the contracts-cache backend so we exercise the
 * real get()/delete()/expiry semantics without the filesystem pool.
 */
class MediaLibraryCacheTest extends TestCase
{
    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope { return new Envelope($message); }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function cache(?ArrayAdapter $pool = null): MediaLibraryCache
    {
        return new MediaLibraryCache($this->swr($pool ?? new ArrayAdapter()));
    }

    public function testMoviesFetchesOnceThenServesFromCache(): void
    {
        $cache = $this->cache();
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
        $cache = $this->cache();
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return []; };

        $cache->movies('radarr-1', $fetch);
        $cache->movies('radarr-1', $fetch);

        $this->assertSame(2, $calls, 'empty result must expire immediately so the next load retries');
    }

    public function testInstancesAreKeyedIndependently(): void
    {
        $cache = $this->cache();

        $a = $cache->movies('radarr-1', fn() => [['id' => 1]]);
        $b = $cache->movies('radarr-4k', fn() => [['id' => 99]]);

        $this->assertSame([['id' => 1]], $a);
        $this->assertSame([['id' => 99]], $b);
    }

    public function testMoviesAndSeriesDoNotCollide(): void
    {
        $cache = $this->cache();

        $movies = $cache->movies('x-1', fn() => [['id' => 1]]);
        $series = $cache->series('x-1', fn() => [['id' => 2]]);

        $this->assertSame([['id' => 1]], $movies);
        $this->assertSame([['id' => 2]], $series);
    }

    public function testInvalidateDropsTheCachedList(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return [['id' => 1]]; };

        $cache->movies('radarr-1', $fetch);
        $cache->invalidate('radarr', 'radarr-1');
        $cache->movies('radarr-1', $fetch);

        $this->assertSame(2, $calls, 'invalidate() must force a re-fetch on the next load');
    }

    public function testInvalidateSonarrTargetsSeriesKey(): void
    {
        $cache = $this->cache();
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

    public function testAnEntryPastTheSoftTtlIsServedStaleWithoutRefetching(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        // Pre-seed an entry fetched two minutes ago: stale (soft 45 s), well
        // inside the hard window (600 s).
        $swr->write('media.movies.radarr-1', [['id' => 1]], MediaLibraryCache::HARD_TTL, time() - 120);

        $calls = 0;
        $rows  = (new MediaLibraryCache($swr))->movies('radarr-1', function () use (&$calls) { $calls++; return [['id' => 2]]; });

        $this->assertSame([['id' => 1]], $rows, 'a stale entry must be served as-is');
        $this->assertSame(0, $calls, 'a stale read must NOT re-fetch inline');
    }

    public function testAStaleReadRequestsARefresh(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('media.movies.radarr-1', [['id' => 1]], MediaLibraryCache::HARD_TTL, time() - 120);

        (new MediaLibraryCache($swr))->movies('radarr-1', fn() => [['id' => 2]]);

        $this->assertTrue(
            $pool->getItem('media.movies.radarr-1.refreshing')->isHit(),
            'a stale read must ask for a background refresh',
        );
    }

    public function testAHardMissStillBlocksAndFetchesInline(): void
    {
        $pool  = new ArrayAdapter();
        $calls = 0;

        $rows = (new MediaLibraryCache($this->swr($pool)))->movies('radarr-1', function () use (&$calls) { $calls++; return [['id' => 7]]; });

        $this->assertSame([['id' => 7]], $rows);
        $this->assertSame(1, $calls, 'the page cannot render without the library, so a hard miss blocks');
    }
}
