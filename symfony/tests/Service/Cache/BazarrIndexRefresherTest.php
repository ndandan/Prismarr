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
}
