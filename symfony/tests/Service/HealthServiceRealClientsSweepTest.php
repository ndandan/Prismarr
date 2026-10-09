<?php

namespace App\Tests\Service;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Http\ConcurrentCurl;
use App\Service\Media\BazarrClient;
use App\Service\Media\DelugeClient;
use App\Service\Media\HoundarrClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\Media\TautulliClient;
use App\Service\Media\TmdbClient;
use App\Service\Media\TransmissionClient;
use App\Service\Media\UnifiClient;
use App\Service\Media\UnraidClient;
use App\Service\Media\Usenet\NzbgetClient;
use App\Service\Media\Usenet\SabnzbdClient;
use App\Service\ServiceInstanceProvider;
use App\Tests\Support\LocalHttp;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * End-to-end proof with the REAL media clients (their own auth headers,
 * breaker reads/writes, response parsing) pointed at a local server that
 * answers every call after ~250 ms: the concurrent cold sweep must produce
 * exactly the chips the one-at-a-time sweep produces, in a fraction of the
 * time. If a client's ping path stops going through ConcurrentCurl::exec(),
 * its transfer serializes and the timing bound catches it.
 *
 * (TMDb is left unconfigured: its base URL is fixed to the public API.)
 */
#[AllowMockObjectsWithoutExpectations]
class HealthServiceRealClientsSweepTest extends TestCase
{
    private const DELAY_MS = 250;

    private function build(string $base, ?ConcurrentCurl $runner): HealthService
    {
        $url = $base . 'd' . self::DELAY_MS;
        $settings = [];
        foreach (['prowlarr', 'jellyseerr', 'sabnzbd', 'tautulli', 'houndarr', 'bazarr', 'unraid', 'unifi'] as $svc) {
            $settings[$svc . '_url']     = $url;
            $settings[$svc . '_api_key'] = 'k';
        }
        foreach (['qbittorrent', 'deluge', 'transmission', 'nzbget'] as $svc) {
            $settings[$svc . '_url'] = $url; // URL-only (reverse-proxy style) setups
        }

        $config = $this->getMockBuilder(ConfigService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $config->method('get')->willReturnCallback(fn (string $k) => $settings[$k] ?? null);

        $instances = [
            ServiceInstance::TYPE_RADARR => new ServiceInstance(ServiceInstance::TYPE_RADARR, 'r1', 'Radarr 1', $url, 'k'),
            ServiceInstance::TYPE_SONARR => new ServiceInstance(ServiceInstance::TYPE_SONARR, 's1', 'Sonarr 1', $url, 'k'),
        ];
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getEnabled')->willReturnCallback(fn (string $t) => isset($instances[$t]) ? [$instances[$t]] : []);
        $provider->method('getDefault')->willReturnCallback(fn (string $t) => $instances[$t] ?? null);
        $provider->method('getBySlug')->willReturnCallback(fn (string $t, string $s) => ($instances[$t] ?? null)?->getSlug() === $s ? $instances[$t] : null);
        $provider->method('hasAnyEnabled')->willReturnCallback(fn (string $t) => isset($instances[$t]));

        $log     = new NullLogger();
        $breaker = new ServiceHealthCache(new ArrayAdapter());

        return new HealthService(
            new RadarrClient($provider, $log, $breaker),
            new SonarrClient($provider, $log, $breaker),
            new ProwlarrClient($config, $log, $breaker),
            new JellyseerrClient($config, $log, $breaker),
            new QBittorrentClient($config, $log, $breaker),
            $this->createMock(TmdbClient::class),
            config: $config,
            serviceHealthCache: $breaker,
            instances: $provider,
            sabnzbd: new SabnzbdClient($config, $log, $breaker),
            nzbget: new NzbgetClient($config, $log, $breaker),
            tautulli: new TautulliClient($config, $log, $breaker),
            unraid: new UnraidClient($config, $log),
            statusPool: new ArrayAdapter(),
            houndarr: new HoundarrClient($config, $log),
            deluge: new DelugeClient($config, $log, $breaker),
            unifi: new UnifiClient($config, $log),
            transmission: new TransmissionClient($config, $log, $breaker),
            bazarr: new BazarrClient($config, $log, $breaker),
            concurrent: $runner,
        );
    }

    /**
     * @param list<array{id: string, name: string, status: string, latencyMs: ?int, color: string}> $chips
     * @return list<string>
     */
    private static function summary(array $chips): array
    {
        return array_map(static fn (array $c): string => $c['id'] . '|' . $c['name'] . '|' . $c['status'], $chips);
    }

    public function testConcurrentSweepMatchesSerialSweepWithRealClients(): void
    {
        $base = LocalHttp::serverUrl();
        if ($base === null) {
            self::markTestSkipped('PHP built-in server unavailable here');
        }

        $t        = hrtime(true);
        $serial   = $this->build($base, null)->chips(true);
        $serialMs = (hrtime(true) - $t) / 1e6;

        $t       = hrtime(true);
        $overlap = $this->build($base, new ConcurrentCurl())->chips(true);
        $fastMs  = (hrtime(true) - $t) / 1e6;

        self::assertCount(14, $serial, implode(', ', self::summary($serial)));
        self::assertSame(self::summary($serial), self::summary($overlap));
        foreach ($overlap as $chip) {
            if ($chip['latencyMs'] !== null) {
                self::assertGreaterThanOrEqual(self::DELAY_MS - 20, $chip['latencyMs'], $chip['name']);
            }
        }
        // 14 services x >=250 ms serially; overlapped ≈ the slowest one.
        self::assertGreaterThan(14 * self::DELAY_MS, $serialMs);
        self::assertLessThan(4 * self::DELAY_MS + 300, $fastMs, sprintf('serial %.0f ms, concurrent %.0f ms', $serialMs, $fastMs));
    }
}
