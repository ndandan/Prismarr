<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Task 7: the per-id mutation patch, the ordering rule vs. an in-flight bulk
 * refresh (spec D3 as amended / defect C2), and the journal itself.
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexPatchTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class($this->dispatched) implements MessageBusInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;
                return new Envelope($message);
            }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function instances(int $radarr = 1, int $sonarr = 1): ServiceInstanceProvider
    {
        $p = $this->createMock(ServiceInstanceProvider::class);
        $p->method('hasExactlyOneEnabled')->willReturnCallback(
            static fn (string $type) => ($type === ServiceInstance::TYPE_RADARR ? $radarr : $sonarr) === 1,
        );

        return $p;
    }

    private function index(BazarrClient $client, ArrayAdapter $pool, int $radarr = 1): BazarrSubtitleIndex
    {
        return new BazarrSubtitleIndex($client, $pool, $this->instances($radarr), $this->swr($pool), new NullLogger());
    }

    public function testRefreshItemFetchesOnlyThatIdAndPatchesTheMap(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [7 => ['state' => 'missing', 'count' => 2]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [7 => ['present' => [], 'missing' => [], 'tracked' => true]], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([7])->willReturn([
            ['radarrId' => 7, 'title' => 'A', 'profileId' => 1, 'subtitles' => [['code2' => 'fr']], 'missing_subtitles' => []],
        ]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, $pool);
        $index->refreshItem('movie', 7);

        $this->assertSame('complete', $index->movieStatus(7)['state'], 'the acted-on item is correct immediately');
        $this->assertSame('complete', $this->swr($pool)->read(BazarrSubtitleIndex::KEY_MOVIES, 60)['value'][7]['state']);
    }

    public function testRefreshItemPreservesTheMapFetchedAtSoItDoesNotLookFresher(): void
    {
        $pool = new ArrayAdapter();
        $old  = time() - 300;
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [7 => ['state' => 'missing', 'count' => 2]], BazarrSubtitleIndex::HARD_TTL, $old);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL, $old);

        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 7, 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => []]]);
        $client->method('getLastError')->willReturn(null);

        $this->index($client, $pool)->refreshItem('movie', 7);

        $this->assertSame('stale', $this->swr($pool)->read(BazarrSubtitleIndex::KEY_MOVIES, 60)['state'],
            'a one-row patch must not reset the whole map to fresh');
    }

    public function testRefreshItemJournalsThePatchAndRequestsABulkRebuild(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 7, 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => []]]);
        $client->method('getLastError')->willReturn(null);

        $this->index($client, $pool)->refreshItem('movie', 7);

        $journal = $pool->getItem(BazarrSubtitleIndex::KEY_PATCHES)->get();
        $this->assertArrayHasKey('movie:7', $journal);
        $this->assertNotEmpty($this->dispatched, 'everyone else catches up through a bulk rebuild');
    }

    public function testAPatchNewerThanTheFetchStartSurvivesTheBulkRefresh(): void
    {
        $pool  = new ArrayAdapter();
        $start = time() - 5;

        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 7, 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => []]]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, $pool);
        $index->refreshItem('movie', 7); // recorded now, i.e. AFTER $start

        // Simulate a bulk fetch that started before the patch and returns the
        // pre-mutation row.
        [$status, $langs] = $index->applyPatchesNewerThan(
            'movie',
            $start,
            [7 => ['state' => 'missing', 'count' => 2]],
            [7 => ['present' => [], 'missing' => [], 'tracked' => true]],
        );

        $this->assertSame('complete', $status[7]['state'], 'the in-flight bulk result must not clobber a newer patch');
    }

    public function testAPatchOlderThanTheFetchStartIsIgnored(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 7, 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => []]]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, $pool);
        $index->refreshItem('movie', 7);

        [$status] = $index->applyPatchesNewerThan('movie', time() + 10, [7 => ['state' => 'missing', 'count' => 2]], []);

        $this->assertSame('missing', $status[7]['state'], 'a fetch that started after the patch already saw it');
    }

    public function testAFailedPerIdFetchLeavesTheMapUntouched(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [7 => ['state' => 'missing', 'count' => 2]], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([]);
        $client->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/movies', 'message' => 'connection failed']);

        $this->index($client, $pool)->refreshItem('movie', 7);

        $this->assertSame('missing', $this->swr($pool)->read(BazarrSubtitleIndex::KEY_MOVIES, 60)['value'][7]['state']);
    }
}
