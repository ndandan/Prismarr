<?php

namespace App\Tests\Service;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\HealthService;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Characterization of chips() — the one health list the dashboard section and
 * the topbar popover both render. Pins the observable contract (order, which
 * services appear, status words, latency presence, breaker short-circuit,
 * per-instance expansion, exception mapping, shared-pool reuse) so the cold
 * sweep can be made concurrent without changing any of it.
 */
#[AllowMockObjectsWithoutExpectations]
class HealthServiceChipsTest extends TestCase
{
    /** Every flat (single-instance) service fully configured. */
    private const SETTINGS = [
        'prowlarr_url' => 'http://prowlarr', 'prowlarr_api_key' => 'k',
        'jellyseerr_url' => 'http://seerr', 'jellyseerr_api_key' => 'k',
        'qbittorrent_url' => 'http://qbit',
        'deluge_url' => 'http://deluge',
        'transmission_url' => 'http://transmission',
        'sabnzbd_url' => 'http://sab', 'sabnzbd_api_key' => 'k',
        'nzbget_url' => 'http://nzbget',
        'tmdb_api_key' => 'k',
        'tautulli_url' => 'http://tautulli', 'tautulli_api_key' => 'k',
        'houndarr_url' => 'http://houndarr', 'houndarr_api_key' => 'k',
        'bazarr_url' => 'http://bazarr', 'bazarr_api_key' => 'k',
        'unraid_url' => 'http://unraid', 'unraid_api_key' => 'k',
        'unifi_url' => 'http://unifi', 'unifi_api_key' => 'k',
    ];

    /** @var array<string, \PHPUnit\Framework\MockObject\MockObject> */
    private array $clients = [];

    /**
     * @param array<string, ?string>               $settings
     * @param array<string, bool|\Closure|null>     $pings   service or "radarr:slug" => ping result / callback (null = never pinged)
     * @param array<string, list<string>>           $instances type => enabled slugs
     */
    private function make(
        array $settings,
        array $pings,
        array $instances = [],
        ?ServiceHealthCache $breaker = null,
        ?ArrayAdapter $pool = null,
        ?\Closure $instancesHook = null,
    ): HealthService {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn (string $k) => $settings[$k] ?? null);
        $config->method('has')->willReturnCallback(fn (string $k) => ($settings[$k] ?? '') !== '');

        $byType = [];
        foreach ($instances as $type => $slugs) {
            foreach ($slugs as $slug) {
                $byType[$type][$slug] = new ServiceInstance($type, $slug, ucfirst($type) . ' ' . strtoupper($slug), 'http://' . $slug, 'k');
            }
        }
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getEnabled')->willReturnCallback(fn (string $t) => array_values($byType[$t] ?? []));
        $provider->method('getBySlug')->willReturnCallback(fn (string $t, string $s) => $byType[$t][$s] ?? null);
        $provider->method('hasAnyEnabled')->willReturnCallback(function (string $t) use ($byType, $instancesHook) {
            if ($instancesHook !== null) {
                $instancesHook($t);
            }
            return ($byType[$t] ?? []) !== [];
        });

        $classes = [
            'radarr' => RadarrClient::class, 'sonarr' => SonarrClient::class, 'prowlarr' => ProwlarrClient::class,
            'jellyseerr' => JellyseerrClient::class, 'qbittorrent' => QBittorrentClient::class, 'tmdb' => TmdbClient::class,
            'sabnzbd' => SabnzbdClient::class, 'nzbget' => NzbgetClient::class, 'tautulli' => TautulliClient::class,
            'unraid' => UnraidClient::class, 'houndarr' => HoundarrClient::class, 'deluge' => DelugeClient::class,
            'unifi' => UnifiClient::class, 'transmission' => TransmissionClient::class, 'bazarr' => BazarrClient::class,
        ];
        $this->clients = [];
        foreach ($classes as $svc => $class) {
            $this->clients[$svc] = $this->pingMock($class, $pings[$svc] ?? null);
        }
        foreach (['radarr' => RadarrClient::class, 'sonarr' => SonarrClient::class] as $type => $class) {
            $perInstance = [];
            foreach (array_keys($byType[$type] ?? []) as $slug) {
                $perInstance[$slug] = $this->pingMock($class, $pings[$type . ':' . $slug] ?? null);
            }
            $this->clients[$type]->method('withInstance')->willReturnCallback(
                fn (ServiceInstance $i) => $perInstance[$i->getSlug()],
            );
        }

        return new HealthService(
            $this->clients['radarr'], $this->clients['sonarr'], $this->clients['prowlarr'],
            $this->clients['jellyseerr'], $this->clients['qbittorrent'], $this->clients['tmdb'],
            config: $config,
            serviceHealthCache: $breaker,
            instances: $provider,
            sabnzbd: $this->clients['sabnzbd'],
            nzbget: $this->clients['nzbget'],
            tautulli: $this->clients['tautulli'],
            unraid: $this->clients['unraid'],
            statusPool: $pool,
            houndarr: $this->clients['houndarr'],
            deluge: $this->clients['deluge'],
            unifi: $this->clients['unifi'],
            transmission: $this->clients['transmission'],
            bazarr: $this->clients['bazarr'],
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     */
    private function pingMock(string $class, bool|\Closure|null $ping): \PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this->createMock($class);
        if ($ping === null) {
            $mock->expects(self::never())->method('ping');
        } elseif ($ping instanceof \Closure) {
            $mock->expects(self::once())->method('ping')->willReturnCallback($ping);
        } else {
            $mock->expects(self::once())->method('ping')->willReturn($ping);
        }
        return $mock;
    }

    /**
     * @param list<array{id: string, name: string, status: string, latencyMs: ?int, color: string}> $chips
     * @return list<string>
     */
    private static function summary(array $chips): array
    {
        return array_map(static fn (array $c): string => $c['id'] . '|' . $c['name'] . '|' . $c['status'], $chips);
    }

    public function testFullSweepOrderStatusesAndExclusions(): void
    {
        $settings = self::SETTINGS;
        unset($settings['nzbget_url']);           // not configured → dropped, never pinged
        $settings['qbittorrent_enabled'] = '0';   // kill switch → dropped, never pinged

        $breaker = new ServiceHealthCache(new ArrayAdapter());
        $breaker->markDown('radarr', 'r3');       // per-instance breaker → degraded, never pinged
        $breaker->markDown('tautulli');           // flat breaker → degraded, never pinged

        $svc = $this->make($settings, [
            'radarr:r1'    => true,
            'radarr:r2'    => false,
            'sonarr:s1'    => static fn () => throw new \RuntimeException('boom'), // ping throws → down
            'prowlarr'     => true,
            'jellyseerr'   => true,
            'deluge'       => false,
            'transmission' => true,
            'sabnzbd'      => true,
            'tmdb'         => true,
            'houndarr'     => true,
            'bazarr'       => false,
        ], ['radarr' => ['r1', 'r2', 'r3'], 'sonarr' => ['s1']], $breaker);

        $chips = $svc->chips();

        self::assertSame([
            'radarr|Radarr R1|up',
            'radarr|Radarr R2|down',
            'radarr|Radarr R3|degraded',
            'sonarr|Sonarr S1|down',
            'prowlarr|Prowlarr|up',
            'jellyseerr|Seerr|up',
            'deluge|Deluge|down',
            'transmission|Transmission|up',
            'sabnzbd|SABnzbd|up',
            'tmdb|TMDb|up',
            'tautulli|Tautulli|degraded',
            'houndarr|Houndarr|up',
            'bazarr|Bazarr|down',
        ], self::summary($chips));

        foreach ($chips as $chip) {
            if ($chip['status'] === 'up') {
                self::assertIsInt($chip['latencyMs'], $chip['name']);
            } else {
                self::assertNull($chip['latencyMs'], $chip['name']);
            }
        }
        self::assertSame('#FFC230', $chips[0]['color']);
        self::assertSame('#be4bdb', $chips[12]['color']);
    }

    public function testUnraidAndUnifiOnlyWhenIncluded(): void
    {
        $pings = ['prowlarr' => true, 'jellyseerr' => true, 'qbittorrent' => true, 'deluge' => true,
            'transmission' => true, 'sabnzbd' => true, 'nzbget' => true, 'tmdb' => true, 'tautulli' => true,
            'houndarr' => true, 'bazarr' => true];

        // Non-admin: Unraid/UniFi are never even pinged.
        $ids = array_column($this->make(self::SETTINGS, $pings)->chips(false), 'id');
        self::assertNotContains('unraid', $ids);
        self::assertNotContains('unifi', $ids);

        $chips = $this->make(self::SETTINGS, $pings + ['unraid' => true, 'unifi' => false])->chips(true);
        self::assertSame(['unraid|Unraid|up', 'unifi|UniFi|down'], array_slice(self::summary($chips), -2));
    }

    public function testInstanceStatusExceptionBecomesDownChip(): void
    {
        // statusFor() itself throwing (here: the "configured" lookup blows up)
        // maps to a 'down' chip for an instance, and to no chip for a flat
        // service.
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k'];
        $svc = $this->make(
            $settings,
            ['prowlarr' => true],
            ['radarr' => ['r1']],
            instancesHook: static fn (string $t) => throw new \RuntimeException('db gone'),
        );

        self::assertSame(['radarr|Radarr R1|down', 'prowlarr|Prowlarr|up'], self::summary($svc->chips()));
    }

    public function testSweepResultsAreSharedThroughThePool(): void
    {
        $pool = new ArrayAdapter();
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k'];

        $first = $this->make($settings, ['radarr:r1' => true, 'prowlarr' => false, 'tmdb' => true], ['radarr' => ['r1']], pool: $pool)->chips();

        // Second "request": same pool, every ping forbidden.
        $second = $this->make($settings, [], ['radarr' => ['r1']], pool: $pool)->chips();

        self::assertSame(self::summary($first), self::summary($second));
        self::assertSame(['radarr|Radarr R1|up', 'prowlarr|Prowlarr|down', 'tmdb|TMDb|up'], self::summary($second));
        self::assertSame($first[0]['latencyMs'], $second[0]['latencyMs']);
    }

    public function testChipsThenLegacyCallsPingEachServiceOnce(): void
    {
        // The topbar endpoint reads chips() and the legacy isHealthy() maps in
        // one request — the second pass must be served from the memo.
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k'];
        $svc = $this->make($settings, ['radarr:r1' => true, 'prowlarr' => true], ['radarr' => ['r1']]);

        $svc->chips();
        self::assertTrue($svc->isHealthy('prowlarr'));
        self::assertTrue($svc->isHealthy('radarr', 'r1'));
    }
}
