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
use ReflectionMethod;

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

    public function testSabnzbdPillIsDownWhenApiKeyRejected(): void
    {
        // Regression (#20): the green pill used a version-based ping that
        // returns 200 for ANY key, so a broken key lit it green. isHealthy()
        // now derives SABnzbd from diagnose() (mode=queue), so a 403 auth
        // failure must surface as down (false), not up.
        $health = $this->makeServiceWithDiagnosis(['ok' => false, 'category' => 'auth', 'http' => 403]);
        self::assertFalse($health->isHealthy('sabnzbd'));
    }

    public function testSabnzbdPillIsUpWhenDiagnosisOk(): void
    {
        $health = $this->makeServiceWithDiagnosis(['ok' => true, 'category' => 'ok', 'http' => 200]);
        self::assertTrue($health->isHealthy('sabnzbd'));
    }

    /**
     * A HealthService with a configured SABnzbd and diagnose() stubbed, so the
     * real isHealthy() → pingFor() wiring is exercised without any network.
     *
     * @param array{ok: bool, category: string, http: ?int} $diagnosis
     */
    private function makeServiceWithDiagnosis(array $diagnosis): HealthService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);           // sabnzbd_enabled unset → not disabled
        $config->method('has')->willReturn(true);            // sabnzbd_url + sabnzbd_api_key present

        $health = $this->getMockBuilder(HealthService::class)
            ->setConstructorArgs([
                $this->createMock(RadarrClient::class),
                $this->createMock(SonarrClient::class),
                $this->createMock(ProwlarrClient::class),
                $this->createMock(JellyseerrClient::class),
                $this->createMock(QBittorrentClient::class),
                $this->createMock(TmdbClient::class),
                $config,
            ])
            ->onlyMethods(['diagnose'])
            ->getMock();
        $health->expects($this->once())->method('diagnose')->with('sabnzbd')->willReturn($diagnosis);

        return $health;
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

    // The SABnzbd probe MUST hit an authenticated mode. mode=version answers
    // 200 for ANY key, so a broken key would test green; mode=queue actually
    // rejects a bad key with 403. Pin the endpoint so we can't regress to it.
    public function testSabnzbdProbeUsesAuthenticatedMode(): void
    {
        $probe = $this->probeFor('sabnzbd', ['sabnzbd_url' => 'http://sab:8080', 'sabnzbd_api_key' => 'k']);
        self::assertNotNull($probe);
        self::assertStringContainsString('mode=queue', $probe['url']);
        self::assertStringNotContainsString('mode=version', $probe['url']);
    }

    /**
     * @param array<string, ?string> $overrides
     * @return array{url: string, headers?: array<int,string>, method?: string, body?: string}|null
     */
    private function probeFor(string $service, array $overrides): ?array
    {
        $m = new ReflectionMethod(HealthService::class, 'probeFor');
        $m->setAccessible(true);
        return $m->invoke($this->makeService(), $service, $overrides);
    }
}
