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

#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexTest extends TestCase
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
        // BazarrSubtitleIndex's gate() now delegates to the shared predicate
        // (ServiceInstanceProvider::hasExactlyOneEnabled) instead of counting
        // getEnabled() itself — stub it from the same $radarr/$sonarr counts
        // so this mock still behaves like the real provider.
        $provider->method('hasExactlyOneEnabled')->willReturnCallback(
            static fn (string $type) => ($type === ServiceInstance::TYPE_RADARR ? $radarr : $sonarr) === 1,
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

    public function testComputeMovieMissingWithCount(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus([
            'profileId' => 1, 'subtitles' => [['code2' => 'en']],
            'missing_subtitles' => [['code2' => 'fr'], ['code2' => 'es']],
        ]);
        $this->assertSame('missing', $s['state']);
        $this->assertSame(2, $s['count']);
    }

    public function testComputeMovieComplete(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus([
            'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => [],
        ]);
        $this->assertSame('complete', $s['state']);
    }

    public function testComputeMovieHiddenWhenNoProfile(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus(['profileId' => null, 'missing_subtitles' => [['code2' => 'fr']]]);
        $this->assertSame('hidden', $s['state']);
    }

    public function testStatusShapeIsStateAndCountOnly(): void
    {
        // `hasProfile` was never read by any template/JS — the shape is now
        // exactly the two keys consumers use.
        $this->assertSame(
            ['state', 'count'],
            array_keys(BazarrSubtitleIndex::computeMovieStatus(['profileId' => 1, 'missing_subtitles' => []])),
        );
        $this->assertSame(
            ['state', 'count'],
            array_keys(BazarrSubtitleIndex::computeMovieStatus(['profileId' => null])),
        );
    }

    public function testComputeSeriesHiddenWhenNoFiles(): void
    {
        $s = BazarrSubtitleIndex::computeSeriesStatus(['profileId' => 1, 'episodeFileCount' => 0, 'episodeMissingCount' => 0]);
        $this->assertSame('hidden', $s['state']);
    }

    public function testComputeSeriesMissing(): void
    {
        $s = BazarrSubtitleIndex::computeSeriesStatus(['profileId' => 1, 'episodeFileCount' => 10, 'episodeMissingCount' => 3]);
        $this->assertSame('missing', $s['state']);
        $this->assertSame(3, $s['count']);
    }

    public function testUnknownMovieIsHidden(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 1, 'profileId' => 1, 'missing_subtitles' => []]]);
        $client->method('getSeries')->willReturn([]);
        $index = $this->index($client);
        $this->assertSame('hidden', $index->movieStatus(999)['state']);
        $this->assertSame('complete', $index->movieStatus(1)['state']);
    }

    public function testMoviesFetchedOncePerRequest(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->willReturn([['radarrId' => 1, 'profileId' => 1, 'missing_subtitles' => []]]);
        $client->method('getSeries')->willReturn([]);
        $index = $this->index($client);
        $index->movieStatus(1);
        $index->movieStatus(1);
        $index->movieStatus(2);
    }

    public function testSuccessfulFetchIsCachedAcrossRequests(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->willReturn([
            ['radarrId' => 1, 'profileId' => 1, 'missing_subtitles' => [['code2' => 'fr']]],
        ]);
        $client->method('getLastError')->willReturn(null);

        $first = $this->index($client, $pool);
        $this->assertSame('missing', $first->movieStatus(1)['state']);

        // A second request (fresh service instance, same cache.app pool) must
        // be served from the pool — getMovies() is expected exactly once.
        $second = $this->index($client, $pool);
        $this->assertSame('missing', $second->movieStatus(1)['state']);
        $this->assertSame(1, $second->movieStatus(1)['count']);
    }

    public function testCachedPayloadHoldsOnlyStatusTuples(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'missing_subtitles' => [], 'title' => 'Big raw dict', 'path' => '/movies/x'],
        ]);
        $client->method('getLastError')->willReturn(null);

        $this->index($client, $pool)->movieStatus(7);

        $stored = $pool->getItem('bazarr_subtitle_index.movies')->get();
        $this->assertSame([7 => ['state' => 'complete', 'count' => 0]], $stored);
    }

    public function testFailedFetchIsNeverCached(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([]);
        // Breaker open / transport failure: the empty map is a symptom, not
        // data — caching it would extend a 10 s outage into a 60 s blackout.
        $client->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/movies', 'message' => 'circuit open']);

        $this->assertSame('hidden', $this->index($client, $pool)->movieStatus(1)['state']);
        $this->assertFalse($pool->getItem('bazarr_subtitle_index.movies')->isHit());
    }

    public function testInvalidateDropsBothPoolItems(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 1, 'profileId' => 1, 'missing_subtitles' => []]]);
        $client->method('getSeries')->willReturn([['sonarrSeriesId' => 5, 'profileId' => 1, 'episodeFileCount' => 3, 'episodeMissingCount' => 0]]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, $pool);
        $index->movieStatus(1);
        $index->seriesStatus(5);
        $this->assertTrue($pool->getItem('bazarr_subtitle_index.movies')->isHit());
        $this->assertTrue($pool->getItem('bazarr_subtitle_index.series')->isHit());

        $index->invalidate();

        $this->assertFalse($pool->getItem('bazarr_subtitle_index.movies')->isHit());
        $this->assertFalse($pool->getItem('bazarr_subtitle_index.series')->isHit());
    }

    public function testResetClearsOnlyTheRequestMemo(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 1, 'profileId' => 1, 'missing_subtitles' => []]]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, $pool);
        $index->movieStatus(1);
        $index->reset();

        $this->assertTrue(
            $pool->getItem('bazarr_subtitle_index.movies')->isHit(),
            'reset() is the worker-mode per-request hook — it must not wipe the shared pool',
        );
    }

    public function testMultipleEnabledRadarrInstancesHideEveryMovieBadge(): void
    {
        // Ids collide across instances, so a badge would show another film's
        // subtitle state (and download for the wrong film). Fail closed.
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, $pool, $this->instances(radarr: 2));

        $this->assertSame('hidden', $index->movieStatus(1)['state']);
        $this->assertFalse(
            $pool->getItem('bazarr_subtitle_index.movies')->isHit(),
            'the gate must run before any pool read/write',
        );
    }

    public function testMultipleEnabledSonarrInstancesHideEverySeriesBadge(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getSeries');

        $index = $this->index($client, new ArrayAdapter(), $this->instances(sonarr: 3));

        $this->assertSame('hidden', $index->seriesStatus(5)['state']);
    }

    public function testZeroEnabledInstancesAlsoHideBadges(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $index = $this->index($client, new ArrayAdapter(), $this->instances(radarr: 0));

        $this->assertSame('hidden', $index->movieStatus(1)['state']);
    }

    public function testSingleEnabledInstanceBehavesNormally(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 1, 'profileId' => 1, 'missing_subtitles' => [['code2' => 'fr']]]]);
        $client->method('getSeries')->willReturn([['sonarrSeriesId' => 5, 'profileId' => 1, 'episodeFileCount' => 3, 'episodeMissingCount' => 2]]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, new ArrayAdapter(), $this->instances(radarr: 1, sonarr: 1));

        $this->assertSame('missing', $index->movieStatus(1)['state']);
        $this->assertSame('missing', $index->seriesStatus(5)['state']);
        $this->assertSame(2, $index->seriesStatus(5)['count']);
    }
}
