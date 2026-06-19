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
