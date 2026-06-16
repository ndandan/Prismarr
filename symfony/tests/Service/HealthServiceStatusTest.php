<?php

namespace App\Tests\Service;

use App\Service\HealthService;
use App\Service\ConfigService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[AllowMockObjectsWithoutExpectations]
class HealthServiceStatusTest extends TestCase
{
    public function testClassifyLatencyBoundaries(): void
    {
        self::assertSame('up',        HealthService::classifyLatency(0));
        self::assertSame('up',        HealthService::classifyLatency(750));
        self::assertSame('slow',      HealthService::classifyLatency(751));
        self::assertSame('slow',      HealthService::classifyLatency(2000));
        self::assertSame('very_slow', HealthService::classifyLatency(2001));
    }

    /**
     * Build a HealthService with a real ProwlarrClient mock so we can drive
     * ping(), a real ServiceHealthCache (ArrayAdapter-backed) for the breaker,
     * and config=null so isConfigured() is skipped — we exercise the probe and
     * breaker paths directly. Prowlarr is single-instance (no slug, no
     * ServiceInstanceProvider needed), which keeps the wiring minimal.
     */
    private function make(?ProwlarrClient $prowlarr, ?ServiceHealthCache $cache, ?ConfigService $config = null): HealthService
    {
        return new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $prowlarr ?? $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $config,
            $cache,
        );
    }

    public function testStatusUpWhenPingSucceedsQuickly(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->method('ping')->willReturn(true);

        $result = $this->make($prowlarr, null)->statusFor('prowlarr');

        self::assertSame('up', $result['status']); // mock ping returns instantly
        self::assertIsInt($result['latencyMs']);
        self::assertGreaterThanOrEqual(0, $result['latencyMs']);
    }

    public function testStatusDownWhenPingFails(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->method('ping')->willReturn(false);

        $result = $this->make($prowlarr, null)->statusFor('prowlarr');

        self::assertSame('down', $result['status']);
        self::assertNull($result['latencyMs']);
    }

    public function testStatusDegradedWhenBreakerOpen(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());
        $cache->markDown('prowlarr');

        // A degraded verdict must come from the breaker WITHOUT a live ping.
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->expects(self::never())->method('ping');

        $result = $this->make($prowlarr, $cache)->statusFor('prowlarr');

        self::assertSame('degraded', $result['status']);
        self::assertNull($result['latencyMs']);
    }

    public function testStatusNullWhenNotConfigured(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturn(false);   // no prowlarr_url / api_key
        $config->method('get')->willReturn(null);

        $result = $this->make(null, null, $config)->statusFor('prowlarr');

        self::assertNull($result['status']);
        self::assertNull($result['latencyMs']);
    }

    public function testIsHealthyStillProjectsToBool(): void
    {
        $up = $this->createMock(ProwlarrClient::class);
        $up->method('ping')->willReturn(true);
        self::assertTrue($this->make($up, null)->isHealthy('prowlarr'));

        $down = $this->createMock(ProwlarrClient::class);
        $down->method('ping')->willReturn(false);
        self::assertFalse($this->make($down, null)->isHealthy('prowlarr'));

        $cache = new ServiceHealthCache(new ArrayAdapter());
        $cache->markDown('prowlarr');
        self::assertFalse($this->make(null, $cache)->isHealthy('prowlarr')); // degraded -> false
    }
}
