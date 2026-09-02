<?php

namespace App\Tests\Service\Cache;

use App\Entity\ServiceInstance;
use App\Service\Cache\MediaLibraryRefresher;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class MediaLibraryRefresherTest extends TestCase
{
    private function bus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope { return new Envelope($message); }
        };
    }

    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        return new StaleWhileRevalidateCache($pool, $pool, $this->bus(), new NullLogger());
    }

    private function instances(): ServiceInstanceProvider
    {
        $inst = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://x', 'k');
        $p = $this->createMock(ServiceInstanceProvider::class);
        $p->method('getBySlug')->willReturnCallback(
            static fn (string $type, string $slug) => ($type === ServiceInstance::TYPE_RADARR && $slug === 'radarr-1') ? $inst : null,
        );

        return $p;
    }

    public function testSupportsOnlyLibraryKeys(): void
    {
        $r = $this->refresher(new ArrayAdapter(), $this->createMock(RadarrClient::class));

        $this->assertTrue($r->supports('media.movies.radarr-1'));
        $this->assertTrue($r->supports('media.series.sonarr-1'));
        $this->assertFalse($r->supports('queue.counts.qbittorrent'));
    }

    public function testRefreshWritesTheFetchedList(): void
    {
        $pool   = new ArrayAdapter();
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->expects($this->once())->method('getMovies')->willReturn([['id' => 5]]);

        $this->refresher($pool, $radarr)->refresh('media.movies.radarr-1');

        $this->assertSame(
            ['value' => [['id' => 5]], 'state' => 'fresh'],
            $this->swr($pool)->read('media.movies.radarr-1', MediaLibraryCache::TTL),
        );
    }

    public function testRefreshIsSkippedWhenTheEntryIsAlreadyFresh(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write('media.movies.radarr-1', [['id' => 1]], MediaLibraryCache::HARD_TTL);

        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getMovies');

        $this->refresher($pool, $radarr)->refresh('media.movies.radarr-1');
    }

    public function testRefreshIsSkippedWhenTheCircuitBreakerIsOpen(): void
    {
        $pool = new ArrayAdapter();
        (new ServiceHealthCache($pool))->markDown('radarr', 'radarr-1');

        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getMovies');

        $this->refresher($pool, $radarr)->refresh('media.movies.radarr-1');
    }

    public function testAnEmptyFetchNeverOverwritesAGoodValue(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write('media.movies.radarr-1', [['id' => 1]], MediaLibraryCache::HARD_TTL, time() - 120);

        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->method('getMovies')->willReturn([]);

        $this->refresher($pool, $radarr)->refresh('media.movies.radarr-1');

        $hit = $this->swr($pool)->read('media.movies.radarr-1', MediaLibraryCache::TTL);
        $this->assertSame([['id' => 1]], $hit['value'], 'a failed/empty fetch must leave the previous good value alone');
    }

    public function testAnUnknownSlugIsANoOp(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getMovies');

        $this->refresher(new ArrayAdapter(), $radarr)->refresh('media.movies.does-not-exist');
    }

    private function refresher(ArrayAdapter $pool, RadarrClient $radarr): MediaLibraryRefresher
    {
        return new MediaLibraryRefresher(
            $this->instances(),
            $radarr,
            $this->createMock(SonarrClient::class),
            $this->swr($pool),
            new ServiceHealthCache($pool),
            new NullLogger(),
        );
    }
}
