<?php

namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage of HealthService for the Usenet wiring (#20):
 *  - diagnoseFromResponse() maps an HTTP probe result to a category. SABnzbd
 *    answers 403 for BOTH a bad API key and a non-whitelisted host, so the
 *    body must disambiguate into `auth` vs `host_whitelist`.
 *  - isConfigured() requirements: SABnzbd needs URL + key, NZBGet only URL.
 * No network here — everything keys off injected ConfigService / response tuples.
 */
#[AllowMockObjectsWithoutExpectations]
class HealthServiceDiagnoseTest extends TestCase
{
    private function makeService(?ConfigService $config = null): HealthService
    {
        return new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $config,
        );
    }

    public function testSabnzbdHostWhitelistRefusal(): void
    {
        $resp = ['http' => 403, 'body' => 'Access denied - Hostname verification failed', 'err' => ''];
        self::assertSame('host_whitelist', $this->makeService()->diagnoseFromResponse($resp, 'sabnzbd')['category']);
    }

    public function testSabnzbdBadApiKeyIsAuth(): void
    {
        $resp = ['http' => 403, 'body' => 'API Key Incorrect', 'err' => ''];
        self::assertSame('auth', $this->makeService()->diagnoseFromResponse($resp, 'sabnzbd')['category']);
    }

    public function testSabnzbdHealthyVersion(): void
    {
        $resp = ['http' => 200, 'body' => '{"version":"5.0.3"}', 'err' => ''];
        self::assertSame('ok', $this->makeService()->diagnoseFromResponse($resp, 'sabnzbd')['category']);
    }

    public function testNzbgetNetworkError(): void
    {
        $resp = ['http' => null, 'body' => null, 'err' => 'Connection refused'];
        self::assertSame('network', $this->makeService()->diagnoseFromResponse($resp, 'nzbget')['category']);
    }

    public function testSabnzbdNeedsUrlAndKey(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        $config->method('has')->willReturnCallback(fn(string $k) => $k === 'sabnzbd_url');
        // URL only → not configured (key missing).
        self::assertFalse($this->makeService($config)->isConfigured('sabnzbd'));
    }

    public function testNzbgetNeedsOnlyUrl(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        $config->method('has')->willReturnCallback(fn(string $k) => $k === 'nzbget_url');
        self::assertTrue($this->makeService($config)->isConfigured('nzbget'));
    }
}
