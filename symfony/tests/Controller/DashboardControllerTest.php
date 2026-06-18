<?php

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Entity\ServiceInstance;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TautulliClient;
use App\Service\Media\TmdbClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pins the dashboard "Services health" widget data: radarr/sonarr expand to
 * one chip per enabled instance (named after the instance), unconfigured
 * services drop out, single services keep one chip.
 */
#[AllowMockObjectsWithoutExpectations]
class DashboardControllerTest extends TestCase
{
    private function instance(string $slug, string $name): ServiceInstance
    {
        $i = $this->createMock(ServiceInstance::class);
        $i->method('getSlug')->willReturn($slug);
        $i->method('getName')->willReturn($name);
        return $i;
    }

    private function cacheItem(): \Symfony\Contracts\Cache\ItemInterface
    {
        $item = $this->createMock(\Symfony\Contracts\Cache\ItemInterface::class);
        $item->method('expiresAfter')->willReturnSelf();
        return $item;
    }

    private function attachRouter(DashboardController $c): void
    {
        $router = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            fn(string $name, array $params = []) => '/' . $name . (isset($params['slug']) ? '/' . $params['slug'] : '')
        );
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn(string $id) => $id === 'router' ? $router : null);
        $c->setContainer($container);
    }

    public function testQuickLookLibraryBuildsMovieViewModel(): void
    {
        $movieRow = [
            'id' => 42, 'title' => 'Dune', 'year' => 2021,
            'overview' => 'Paul Atreides arrives on Arrakis.',
            'genres' => ['Science Fiction', 'Adventure', 'Drama', 'Action', 'Extra'],
            'ratings' => 7.8, 'runtime' => 155,
            'poster' => 'https://img/poster.jpg', 'fanart' => 'https://img/fan.jpg',
            'quality' => 'Bluray-1080p', 'hasFile' => true, 'monitored' => true,
            'status' => 'released', '_instanceSlug' => 'radarr-1', '_instanceName' => 'Radarr',
        ];

        // cache->get(key, cb) returns cb() result; our movies() closure returns [$movieRow]
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn(string $k, callable $cb) => $cb($this->cacheItem()));

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type): array => $type === ServiceInstance::TYPE_RADARR
                ? [$this->instance('radarr-1', 'Radarr')] : []
        );

        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->method('getMovies')->willReturn([$movieRow]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn(string $k, array $p = []) => $k === 'dashboard.quicklook.runtime' ? $p['min'] . ' min' : $k
        );

        $controller = new DashboardController(
            $this->createMock(HealthService::class), $radarr,
            $this->createMock(SonarrClient::class), $this->createMock(JellyseerrClient::class),
            $this->createMock(TmdbClient::class), $this->createMock(WatchlistItemRepository::class),
            $instances, new NullLogger(), $translator, $cache, $this->createMock(TautulliClient::class),
        );
        $this->attachRouter($controller);

        $m = new ReflectionMethod(DashboardController::class, 'quickLookLibrary');
        $m->setAccessible(true);
        $vm = $m->invoke($controller, 'movie', 'radarr-1', 42);

        self::assertSame('Dune', $vm['title']);
        self::assertSame(2021, $vm['year']);
        self::assertSame('https://img/poster.jpg', $vm['poster']);
        self::assertSame('https://img/fan.jpg', $vm['backdrop']);
        self::assertCount(4, $vm['genres']); // sliced to 4
        self::assertSame(7.8, $vm['rating']);
        self::assertSame('155 min', $vm['metaLine']);
        self::assertSame('downloaded', $vm['statusBadge']['kind']);
        self::assertStringContainsString('open=42', $vm['actionUrl']);
    }

    public function testQuickLookLibrarySeriesAndUnknownId(): void
    {
        $seriesRow = [
            'id' => 7, 'title' => 'Severance', 'year' => 2022,
            'overview' => 'Work-life balance, perfected.', 'genres' => ['Drama', 'Sci-Fi'],
            'ratings' => 8.4, 'network' => 'Apple TV+', 'poster' => 'https://img/s.jpg',
            'fanart' => null, 'monitored' => true, 'hasFile' => false,
            'status' => 'continuing', '_instanceSlug' => 'sonarr-1', '_instanceName' => 'Sonarr',
        ];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn(string $k, callable $cb) => $cb($this->cacheItem()));
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type): array => $type === ServiceInstance::TYPE_SONARR
                ? [$this->instance('sonarr-1', 'Sonarr')] : []
        );
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->method('withInstance')->willReturnSelf();
        $sonarr->method('getSeries')->willReturn([$seriesRow]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn(string $k) => $k);

        $controller = new DashboardController(
            $this->createMock(HealthService::class), $this->createMock(RadarrClient::class),
            $sonarr, $this->createMock(JellyseerrClient::class), $this->createMock(TmdbClient::class),
            $this->createMock(WatchlistItemRepository::class), $instances, new NullLogger(),
            $translator, $cache, $this->createMock(TautulliClient::class),
        );
        $this->attachRouter($controller);
        $m = new ReflectionMethod(DashboardController::class, 'quickLookLibrary');
        $m->setAccessible(true);

        $vm = $m->invoke($controller, 'series', 'sonarr-1', 7);
        self::assertSame('Severance', $vm['title']);
        self::assertSame('Apple TV+', $vm['metaLine']);
        self::assertSame('monitored', $vm['statusBadge']['kind']);
        self::assertStringContainsString('open=7', $vm['actionUrl']);

        // Unknown id, instance lookup returns null → null view-model.
        $instances->method('getBySlug')->willReturn(null);
        self::assertNull($m->invoke($controller, 'series', 'sonarr-1', 999));
    }

    public function testServicesHealthExpandsOneChipPerInstance(): void
    {
        $health = $this->createMock(HealthService::class);
        $health->method('statusFor')->willReturnCallback(
            fn(string $service, ?string $slug = null): array => match (true) {
                $service === 'radarr' && $slug === 'radarr-1'  => ['status' => 'up',   'latencyMs' => 120],
                $service === 'radarr' && $slug === 'radarr-4k' => ['status' => 'down', 'latencyMs' => null],
                $service === 'sonarr' && $slug === 'sonarr-1'  => ['status' => 'slow', 'latencyMs' => 1500],
                $service === 'qbittorrent'                     => ['status' => 'up',   'latencyMs' => 40],
                default                                        => ['status' => null,   'latencyMs' => null], // prowlarr/jellyseerr/tmdb not configured
            }
        );

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $type): array => match ($type) {
                ServiceInstance::TYPE_RADARR => [$this->instance('radarr-1', 'Radarr 1080p'), $this->instance('radarr-4k', 'Radarr 4K')],
                ServiceInstance::TYPE_SONARR => [$this->instance('sonarr-1', 'Sonarr')],
                default                      => [],
            }
        );

        $controller = new DashboardController(
            $health,
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(TmdbClient::class),
            $this->createMock(WatchlistItemRepository::class),
            $instances,
            new NullLogger(),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(TautulliClient::class),
        );

        $m = new ReflectionMethod(DashboardController::class, 'servicesHealth');
        $m->setAccessible(true);
        /** @var list<array{id: string, name: string, status: string, latencyMs: ?int}> $chips */
        $chips = $m->invoke($controller);

        // Two Radarr instances + one Sonarr + qBittorrent = 4 chips; the
        // unconfigured single services (prowlarr/jellyseerr/tmdb) drop out.
        self::assertCount(4, $chips);
        self::assertSame(['id' => 'radarr', 'name' => 'Radarr 1080p', 'status' => 'up',   'latencyMs' => 120],  $chips[0]);
        self::assertSame(['id' => 'radarr', 'name' => 'Radarr 4K',    'status' => 'down', 'latencyMs' => null], $chips[1]);
        self::assertSame(['id' => 'sonarr', 'name' => 'Sonarr',       'status' => 'slow', 'latencyMs' => 1500], $chips[2]);
        self::assertSame(['id' => 'qbittorrent', 'name' => 'qBittorrent', 'status' => 'up', 'latencyMs' => 40], $chips[3]);
    }
}
