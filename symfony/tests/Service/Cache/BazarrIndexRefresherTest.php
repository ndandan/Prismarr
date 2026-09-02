<?php

namespace App\Tests\Service\Cache;

use App\Service\Cache\BazarrIndexRefresher;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class BazarrIndexRefresherTest extends TestCase
{
    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope { return new Envelope($message); }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function refresher(ArrayAdapter $pool, BazarrClient $client): BazarrIndexRefresher
    {
        return new BazarrIndexRefresher($client, $this->swr($pool), new ServiceHealthCache($pool), new NullLogger());
    }

    public function testSupportsOnlyTheBazarrDatasetKeys(): void
    {
        $r = $this->refresher(new ArrayAdapter(), $this->createMock(BazarrClient::class));

        $this->assertTrue($r->supports(BazarrSubtitleIndex::KEY_MOVIES));
        $this->assertTrue($r->supports(BazarrSubtitleIndex::KEY_SERIES));
        $this->assertFalse($r->supports('media.movies.radarr-1'));
        $this->assertFalse($r->supports(BazarrSubtitleIndex::KEY_MOVIE_LANGS)); // written BY the movies refresh, never requested on its own
    }

    public function testRefreshWritesBothTheStatusAndLanguageMapsFromOneFetch(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([])->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => [['code2' => 'fr']]],
        ]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $swr = $this->swr($pool);
        $this->assertSame('missing', $swr->read(BazarrSubtitleIndex::KEY_MOVIES, 60)['value'][7]['state']);
        $this->assertTrue($swr->read(BazarrSubtitleIndex::KEY_MOVIE_LANGS, 60)['value'][7]['tracked']);
    }

    public function testRefreshSeriesWritesTheComputedTuples(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getSeries')->with([])->willReturn([
            ['sonarrSeriesId' => 9, 'profileId' => 1, 'episodeFileCount' => 4, 'episodeMissingCount' => 2],
        ]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_SERIES);

        $stored = $this->swr($pool)->read(BazarrSubtitleIndex::KEY_SERIES, 60)['value'];
        $this->assertSame(['state' => 'missing', 'count' => 2], $stored[9]);
    }

    public function testCachedPayloadHoldsOnlyTheStatusAndLanguageTupleKeys(): void
    {
        // Guardrail 3 (worker-mode safety): the raw Bazarr dict — arbitrary
        // size, arbitrary fields — must never enter the pool. Only the small,
        // computed tuple shapes may.
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([[
            'radarrId'          => 7,
            'profileId'         => 1,
            'subtitles'         => [['code2' => 'en']],
            'missing_subtitles' => [],
            'title'             => 'Big raw dict',
            'path'              => '/movies/x',
            'overview'          => str_repeat('lorem ipsum ', 200),
        ]]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $swr = $this->swr($pool);
        $this->assertSame(['state' => 'complete', 'count' => 0], $swr->read(BazarrSubtitleIndex::KEY_MOVIES, 60)['value'][7]);
        $this->assertSame(
            ['present', 'missing', 'tracked'],
            array_keys($swr->read(BazarrSubtitleIndex::KEY_MOVIE_LANGS, 60)['value'][7]),
        );
    }

    public function testASingleFetchStampsEveryWrittenKeyWithTheSameFetchedAt(): void
    {
        // M2 (design review): one $now for every key written from one fetch,
        // so the group shares a soft window instead of drifting apart.
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => []],
        ]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $moviesEnvelope = $pool->getItem(BazarrSubtitleIndex::KEY_MOVIES)->get();
        $langsEnvelope  = $pool->getItem(BazarrSubtitleIndex::KEY_MOVIE_LANGS)->get();
        $this->assertIsArray($moviesEnvelope);
        $this->assertIsArray($langsEnvelope);
        $this->assertSame($langsEnvelope['fetchedAt'], $moviesEnvelope['fetchedAt']);
    }

    public function testACleanEmptyMovieFetchWritesEmptyMapsNotANoOp(): void
    {
        // CRITICAL regression (fix round 1): BazarrClient::getMovies()
        // returns [] with getLastError() === null (no HTTP call at all) when
        // Bazarr is simply unconfigured/disabled — a legitimate, PERMANENT
        // state. Refusing to write in that case (as a naive guardrail-6
        // "empty means failed" check would) leaves every read a hard miss
        // forever: every badge stuck on 'pending', a refresh dispatched every
        // 30 s, an overdue error logged after 180 s, none of it ever
        // resolving. A clean empty fetch MUST still write (empty) maps.
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $swr = $this->swr($pool);
        $moviesHit = $swr->read(BazarrSubtitleIndex::KEY_MOVIES, 60);
        $langsHit  = $swr->read(BazarrSubtitleIndex::KEY_MOVIE_LANGS, 60);
        $this->assertNotNull($moviesHit, 'a clean empty fetch must still be a cache HIT, not a hard miss');
        $this->assertSame([], $moviesHit['value']);
        $this->assertNotNull($langsHit);
        $this->assertSame([], $langsHit['value']);

        // Idempotent: the entry is now fresh, so a duplicate message must not
        // re-fetch. See BazarrSubtitleIndexSwrTest::testAnEmptyButFreshMapAnswersHiddenNotPending
        // for the end-to-end assertion (through BazarrSubtitleIndex itself)
        // that this now answers 'hidden', not 'pending'.
        $neverAgain = $this->createMock(BazarrClient::class);
        $neverAgain->expects($this->never())->method('getMovies');
        $this->refresher($pool, $neverAgain)->refresh(BazarrSubtitleIndex::KEY_MOVIES);
    }

    public function testACleanEmptySeriesFetchWritesAnEmptyMap(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getSeries')->willReturn([]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_SERIES);

        $hit = $this->swr($pool)->read(BazarrSubtitleIndex::KEY_SERIES, 60);
        $this->assertNotNull($hit, 'a clean empty fetch must still be a cache HIT, not a hard miss');
        $this->assertSame([], $hit['value']);
    }

    public function testAFetchThatRecordedAnErrorNeverOverwritesAGoodValue(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [7 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL, time() - 300);

        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([]);
        $client->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/movies', 'message' => 'connection failed']);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $this->assertSame(
            [7 => ['state' => 'complete', 'count' => 0]],
            $this->swr($pool)->read(BazarrSubtitleIndex::KEY_MOVIES, 60)['value'],
        );
    }

    public function testAnOpenCircuitBreakerSkipsTheFetchEntirely(): void
    {
        $pool = new ArrayAdapter();
        (new ServiceHealthCache($pool))->markDown(BazarrClient::SERVICE);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);
    }

    public function testADuplicateMessageOnAFreshEntryIsANoOp(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [7 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);
    }

    public function testOneMovieFetchFillsStatusLangsCardsMostMissingAndBadges(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([])->willReturn([
            ['radarrId' => 7, 'title' => 'A', 'year' => 2001, 'profileId' => 1,
             'subtitles' => [], 'missing_subtitles' => [['code2' => 'fr'], ['code2' => 'en']]],
            ['radarrId' => 8, 'title' => 'B', 'year' => 1999, 'profileId' => 1,
             'subtitles' => [['code2' => 'en']], 'missing_subtitles' => []],
        ]);
        $client->expects($this->once())->method('getBadgeCounts')->willReturn(['movies' => 6219, 'episodes' => 13758, 'providers' => 2]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $swr   = $this->swr($pool);
        $cards = $swr->read(BazarrSubtitleIndex::KEY_MOVIE_CARDS, 60)['value'];
        $this->assertCount(2, $cards['cards']);
        $this->assertSame(['en', 'fr'], $cards['languages'], 'language options are the sorted distinct missing codes');

        $mm = $swr->read(BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, 60)['value'];
        $this->assertSame(7, $mm[0]['id']);
        $this->assertSame(2, $mm[0]['missingCount']);
        $this->assertCount(1, $mm, 'only rows with a positive missing count are candidates');

        $this->assertSame(6219, $swr->read(BazarrSubtitleIndex::KEY_BADGES, 60)['value']['movies']);
    }

    public function testMostMissingCandidatesAreCappedAndSortedDescending(): void
    {
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ['radarrId' => $i, 'title' => 'M' . $i, 'profileId' => 1, 'subtitles' => [],
                       'missing_subtitles' => array_fill(0, $i, ['code2' => 'fr'])];
        }

        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn($rows);
        $client->method('getBadgeCounts')->willReturn(['movies' => 0, 'episodes' => 0, 'providers' => 0]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $mm = $this->swr($pool)->read(BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, 60)['value'];
        $this->assertCount(BazarrSubtitleIndex::MOST_MISSING_CANDIDATES, $mm);
        $this->assertSame(50, $mm[0]['missingCount']);
    }

    public function testSeriesMostMissingRanksByAggregateEpisodeMissingCount(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getSeries')->willReturn([
            ['sonarrSeriesId' => 1, 'title' => 'S1', 'profileId' => 1, 'episodeFileCount' => 10, 'episodeMissingCount' => 4],
            ['sonarrSeriesId' => 2, 'title' => 'S2', 'profileId' => 1, 'episodeFileCount' => 10, 'episodeMissingCount' => 9],
        ]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_SERIES);

        $mm = $this->swr($pool)->read(BazarrSubtitleIndex::KEY_MOST_MISSING_SERIES, 60)['value'];
        $this->assertSame(2, $mm[0]['id']);
        $this->assertSame(9, $mm[0]['missingCount']);
    }

    public function testAFailedBadgeCallDoesNotBlockTheMovieDatasetsAndDoesNotCacheFabricatedZeros(): void
    {
        // Fix round 1 / IMPORTANT 1: BazarrClient::getBadgeCounts() fails
        // CLOSED to all-zeros and records lastError on failure. Bazarr can
        // become unreachable BETWEEN the successful getMovies() call and the
        // getBadgeCounts() call moments later — caching that fabricated
        // all-zero count as success would violate "never cache a failed
        // fetch as success" for a full HARD_TTL (600 s) window. Only the
        // badge write must be skipped; the movie datasets from the earlier
        // clean fetch are unaffected.
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_BADGES, ['movies' => 42, 'episodes' => 7, 'providers' => 1], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 7, 'title' => 'A', 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => []]]);
        $client->method('getBadgeCounts')->willReturn(['movies' => 0, 'episodes' => 0, 'providers' => 0]);
        // getLastError() is checked twice: once right after getMovies() (must
        // answer null so the movie datasets are written), once right after
        // getBadgeCounts() (must answer an error so the fabricated zeros are
        // NOT written).
        $calls = 0;
        $client->method('getLastError')->willReturnCallback(function () use (&$calls) {
            $calls++;

            return $calls === 1 ? null : ['code' => 0, 'method' => 'GET', 'path' => '/badges', 'message' => 'connection failed'];
        });

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $swr = $this->swr($pool);
        $this->assertNotNull($swr->read(BazarrSubtitleIndex::KEY_MOVIES, 60), 'the movie status map must still be written');
        $this->assertNotNull($swr->read(BazarrSubtitleIndex::KEY_MOVIE_LANGS, 60), 'the movie langs map must still be written');
        $this->assertNotNull($swr->read(BazarrSubtitleIndex::KEY_MOVIE_CARDS, 60), 'the movie cards must still be written');
        $this->assertNotNull($swr->read(BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, 60), 'the most-missing candidates must still be written');

        $badges = $swr->read(BazarrSubtitleIndex::KEY_BADGES, 60);
        $this->assertNotNull($badges, 'the previous good badge value must survive');
        $this->assertSame(['movies' => 42, 'episodes' => 7, 'providers' => 1], $badges['value'], 'a failed badge fetch must never overwrite the previous good value with fabricated zeros');
    }

    public function testMovieDatasetWriteOrderFollowsTheArchitectureDocM2(): void
    {
        // M2: cards -> most_missing -> badges -> langs -> status, so the
        // longest-lived read paths (movieStatus/movieLanguages, which gate
        // hundreds of per-request badge renders) are the last to flip.
        $order = [];
        $pool  = new class($order) extends ArrayAdapter {
            /** @param list<string> $order */
            public function __construct(private array &$order) { parent::__construct(); }
            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                $this->order[] = $item->getKey();

                return parent::save($item);
            }
        };

        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([
            ['radarrId' => 7, 'title' => 'A', 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => []],
        ]);
        $client->method('getBadgeCounts')->willReturn(['movies' => 1, 'episodes' => 2, 'providers' => 1]);
        $client->method('getLastError')->willReturn(null);

        $this->refresher($pool, $client)->refresh(BazarrSubtitleIndex::KEY_MOVIES);

        $this->assertSame([
            BazarrSubtitleIndex::KEY_MOVIE_CARDS,
            BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES,
            BazarrSubtitleIndex::KEY_BADGES,
            BazarrSubtitleIndex::KEY_MOVIE_LANGS,
            BazarrSubtitleIndex::KEY_MOVIES,
        ], $order);
    }
}
