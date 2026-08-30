<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Per-movie subtitle-language detail: BazarrSubtitleIndex::movieLanguages(),
 * built from the SAME getMovies() pass that feeds the badge status tuples.
 * Consumed by the film-detail modal (Task 4) via the JSON endpoint (Task 2).
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexLanguagesTest extends TestCase
{
    /** ServiceInstanceProvider reporting $radarr enabled Radarr / $sonarr enabled Sonarr instances. */
    private function instances(int $radarr = 1, int $sonarr = 1): ServiceInstanceProvider
    {
        $make = static fn (string $type, int $n) => $n > 0
            ? array_map(
                static fn (int $i) => new ServiceInstance($type, $type . '-' . $i, ucfirst($type) . ' ' . $i, 'http://x', 'k'),
                range(1, $n),
            )
            : [];

        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getEnabled')->willReturnCallback(
            static fn (string $type) => $type === ServiceInstance::TYPE_RADARR
                ? $make(ServiceInstance::TYPE_RADARR, $radarr)
                : $make(ServiceInstance::TYPE_SONARR, $sonarr),
        );

        return $provider;
    }

    private function index(
        BazarrClient $client,
        ?CacheItemPoolInterface $pool = null,
        ?ServiceInstanceProvider $instances = null,
    ): BazarrSubtitleIndex {
        return new BazarrSubtitleIndex($client, $pool ?? new ArrayAdapter(), $instances ?? $this->instances());
    }

    public function testMovieLanguagesReturnsPresentMissingTracked(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([[
            'radarrId'          => 5,
            'profileId'         => 1,
            'subtitles'         => [['name' => 'English', 'code2' => 'en', 'hi' => false, 'forced' => false]],
            'missing_subtitles' => [['name' => 'French', 'code2' => 'fr', 'hi' => false, 'forced' => false]],
        ]]);
        $client->method('getLastError')->willReturn(null);

        $this->assertSame([
            'present' => [['lang' => 'en', 'hi' => false, 'forced' => false]],
            'missing' => [['lang' => 'fr', 'hi' => false, 'forced' => false]],
            'tracked' => true,
        ], $this->index($client)->movieLanguages(5));
    }

    public function testAbsentMovieIsUntracked(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 5, 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => []]]);
        $client->method('getLastError')->willReturn(null);

        $this->assertSame(
            ['present' => [], 'missing' => [], 'tracked' => false],
            $this->index($client)->movieLanguages(999),
        );
    }

    public function testMovieWithoutProfileIsUntracked(): void
    {
        // Untracked in Bazarr (no subtitle profile) → tracked:false, empty
        // lists, even though the raw dict carries a subtitles array.
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([[
            'radarrId'          => 5,
            'profileId'         => null,
            'subtitles'         => [['code2' => 'en']],
            'missing_subtitles' => [],
        ]]);
        $client->method('getLastError')->willReturn(null);

        $this->assertSame(
            ['present' => [], 'missing' => [], 'tracked' => false],
            $this->index($client)->movieLanguages(5),
        );
    }

    public function testMultiInstanceGateHidesLanguages(): void
    {
        // Two enabled Radarr instances: ids collide, so fail closed exactly
        // like the badge — no fetch, tracked:false.
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $index = $this->index($client, new ArrayAdapter(), $this->instances(radarr: 2));

        $this->assertSame(
            ['present' => [], 'missing' => [], 'tracked' => false],
            $index->movieLanguages(5),
        );
    }

    public function testOneFetchFillsBothStatusAndLanguageMaps(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->willReturn([[
            'radarrId'          => 5,
            'profileId'         => 1,
            'subtitles'         => [['code2' => 'en']],
            'missing_subtitles' => [['code2' => 'fr']],
        ]]);
        $client->method('getSeries')->willReturn([]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client);
        $this->assertSame('missing', $index->movieStatus(5)['state']); // builds the tuple map
        $this->assertTrue($index->movieLanguages(5)['tracked']);       // reuses the SAME fetch
    }

    public function testLanguageMapCachedAcrossRequests(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->willReturn([[
            'radarrId'          => 5,
            'profileId'         => 1,
            'subtitles'         => [['code2' => 'en']],
            'missing_subtitles' => [],
        ]]);
        $client->method('getLastError')->willReturn(null);

        $this->assertTrue($this->index($client, $pool)->movieLanguages(5)['tracked']);
        // Fresh service, same cache.app pool → served from cache, no 2nd fetch.
        $this->assertTrue($this->index($client, $pool)->movieLanguages(5)['tracked']);
    }

    public function testFailedFetchLeavesLanguageMapUncached(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([]);
        $client->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/movies', 'message' => 'circuit open']);

        $this->assertSame(
            ['present' => [], 'missing' => [], 'tracked' => false],
            $this->index($client, $pool)->movieLanguages(5),
        );
        $this->assertFalse($pool->getItem('bazarr_subtitle_index.movie_langs')->isHit());
    }

    public function testInvalidateDropsLanguageCacheKey(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 5, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => []]]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, $pool);
        $index->movieLanguages(5);
        $this->assertTrue($pool->getItem('bazarr_subtitle_index.movie_langs')->isHit());

        $index->invalidate();
        $this->assertFalse($pool->getItem('bazarr_subtitle_index.movie_langs')->isHit());
    }
}
