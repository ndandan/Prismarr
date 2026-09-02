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
 * Task 8: the per-id single-item fallback (movieStatusSingle() /
 * movieLanguagesSingle()) for surfaces that render exactly ONE item — the
 * quick-look badge and the modal-chips endpoint. The map is read first
 * (fresh OR stale); only a genuine hard miss makes ONE `radarrid[]`-filtered
 * Bazarr call. Kept strictly off the grid path (spec defect C1); see
 * TemplateStructureGuardTest for the template-side enforcement.
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexSingleTest extends TestCase
{
    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
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

    public function testAHardMissFallsBackToExactlyOnePerIdCall(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([7])->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => [['code2' => 'fr']]],
        ]);
        $client->method('getLastError')->willReturn(null);

        $status = $this->index($client, new ArrayAdapter())->movieStatusSingle(7);

        $this->assertSame('missing', $status['state']);
        $this->assertSame(1, $status['count']);
    }

    public function testAWarmMapCostsNoBazarrCallAtAll(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [7 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [7 => ['present' => [], 'missing' => [], 'tracked' => true]], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $this->assertSame('complete', $this->index($client, $pool)->movieStatusSingle(7)['state']);
    }

    public function testTheFallbackResultIsMemoizedForTheRestOfTheRequest(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([7])->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => []],
        ]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, new ArrayAdapter());
        $index->movieStatusSingle(7);
        $langs = $index->movieLanguagesSingle(7); // badge + chips = one call

        $this->assertTrue($langs['tracked']);
    }

    public function testAFailedFallbackAnswersHiddenNotPending(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([]);
        $client->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/movies', 'message' => 'connection failed']);

        $this->assertSame('hidden', $this->index($client, new ArrayAdapter())->movieStatusSingle(7)['state']);
    }

    public function testTheMultiInstanceGateBlocksTheFallbackToo(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $this->assertSame('hidden', $this->index($client, new ArrayAdapter(), radarr: 2)->movieStatusSingle(7)['state']);
    }
}
