<?php

namespace App\Tests\Controller;

use App\Controller\MediaController;
use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Ctrl+K search index is cached for 60 s. When an instance's library
 * fetch fails (or comes back empty) the index built from the survivors is
 * PARTIAL, and caching it for a full minute hides that instance's titles
 * long after the blip is over. A partial index must only be cached for a
 * few seconds; a complete one keeps the 60 s window.
 */
#[AllowMockObjectsWithoutExpectations]
class GlobalSearchIndexTtlTest extends TestCase
{
    private const ROW = ['id' => 1, 'title' => 'Matrix', 'year' => 1999, 'sortTitle' => 'matrix'];

    /**
     * @param array<string, list<array<string, mixed>>|\Throwable> $perSlug slug => rows | exception
     */
    private function ttlFor(string $kind, array $perSlug): int
    {
        $type      = $kind === 'movies' ? ServiceInstance::TYPE_RADARR : ServiceInstance::TYPE_SONARR;
        $instances = [];
        foreach (array_keys($perSlug) as $slug) {
            $instances[] = new ServiceInstance($type, $slug, strtoupper($slug), 'http://localhost');
        }

        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getEnabled')->willReturn($instances);

        $libraryCache = $this->createMock(MediaLibraryCache::class);
        $resolve = static function (string $slug) use ($perSlug) {
            $v = $perSlug[$slug];
            if ($v instanceof \Throwable) {
                throw $v;
            }

            return $v;
        };
        $libraryCache->method('movies')->willReturnCallback(static fn (string $slug) => $resolve($slug));
        $libraryCache->method('series')->willReturnCallback(static fn (string $slug) => $resolve($slug));

        $controller = new MediaController(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(\Symfony\Contracts\Cache\CacheInterface::class),
            $this->createMock(ConfigService::class),
            $provider,
            $this->createMock(LoggerInterface::class),
            $this->createMock(TranslatorInterface::class),
            $libraryCache,
            $this->createMock(BazarrSubtitleIndex::class),
        );

        $ttl  = null;
        $item = $this->createMock(ItemInterface::class);
        $item->method('expiresAfter')->willReturnCallback(function ($t) use (&$ttl, $item) {
            $ttl = $t;

            return $item;
        });

        $method = new \ReflectionMethod(MediaController::class, $kind === 'movies' ? 'buildMovieSearchIndex' : 'buildSeriesSearchIndex');
        $method->invoke($controller, $item);

        self::assertNotNull($ttl, 'the index builder must set a TTL');

        return (int) $ttl;
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        yield 'movies' => ['movies'];
        yield 'series' => ['series'];
    }

    #[DataProvider('kinds')]
    public function testCompleteIndexKeepsTheSixtySecondWindow(string $kind): void
    {
        self::assertSame(60, $this->ttlFor($kind, ['a' => [self::ROW], 'b' => [self::ROW]]));
    }

    #[DataProvider('kinds')]
    public function testAFailedInstanceMakesTheIndexShortLived(string $kind): void
    {
        $ttl = $this->ttlFor($kind, ['a' => [self::ROW], 'b' => new \RuntimeException('boom')]);

        self::assertLessThanOrEqual(10, $ttl, 'a partial index must not be cached for the full minute');
    }

    #[DataProvider('kinds')]
    public function testAnEmptyInstanceMakesTheIndexShortLived(string $kind): void
    {
        $ttl = $this->ttlFor($kind, ['a' => [self::ROW], 'b' => []]);

        self::assertLessThanOrEqual(10, $ttl, 'an empty library may be a transient blip, not a 60 s fact');
    }

    #[DataProvider('kinds')]
    public function testAllInstancesFailingIsShortLived(string $kind): void
    {
        $ttl = $this->ttlFor($kind, ['a' => new \RuntimeException('boom')]);

        self::assertLessThanOrEqual(10, $ttl);
    }
}
