<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\UnraidClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * UnraidClient — GraphQL client for the Unraid 7 API.
 * Tests override gql() so no HTTP happens; each canned payload mirrors the
 * `data` object of the corresponding group query.
 */
#[AllowMockObjectsWithoutExpectations]
class UnraidClientTest extends TestCase
{
    /** @param array<string, ?array> $responses  keyed by a substring of the query */
    private function makeClient(array $responses, array $settings = []): UnraidClient
    {
        $settings += [
            'unraid_url'             => 'https://tower.local',
            'unraid_api_key'         => 'k3y',
            'unraid_enabled'         => null,
            'unraid_skip_tls_verify' => null,
        ];
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $settings[$k] ?? null);

        return new class($config, $this->createMock(LoggerInterface::class), $responses) extends UnraidClient {
            public array $queriesSent = [];
            public function __construct($config, $logger, private array $responses)
            {
                parent::__construct($config, $logger);
            }
            protected function gql(string $query): ?array
            {
                $this->queriesSent[] = $query;
                foreach ($this->responses as $needle => $payload) {
                    if (str_contains($query, $needle)) {
                        return $payload;
                    }
                }
                return null;
            }
        };
    }

    private const ARRAY_DATA = ['array' => [
        'state'    => 'STARTED',
        'capacity' => ['kilobytes' => ['free' => '1000', 'used' => '3000', 'total' => '4000']],
        'disks'    => [
            ['name' => 'disk1', 'temp' => 38, 'status' => 'DISK_OK', 'fsSize' => '4000', 'fsFree' => '1000', 'fsUsed' => '3000'],
        ],
        'parities' => [['name' => 'parity', 'temp' => 41, 'status' => 'DISK_OK']],
        'caches'   => [['name' => 'cache', 'temp' => 35, 'fsSize' => '500', 'fsFree' => '200', 'fsUsed' => '300']],
    ]];
    private const INFO_DATA = ['info' => [
        'os'  => ['uptime' => '2026-06-20T04:05:06Z'],
        'cpu' => ['brand' => 'AMD Ryzen 7', 'cores' => 8, 'threads' => 16],
    ]];
    private const METRICS_DATA = ['metrics' => [
        'cpu'    => ['percentTotal' => 12.5],
        'memory' => ['percentTotal' => 40.0, 'total' => '32000000000', 'used' => '12800000000'],
    ]];
    private const DOCKER_DATA = ['docker' => ['containers' => [
        ['names' => ['/plex'],     'state' => 'RUNNING'],
        ['names' => ['/radarr'],   'state' => 'RUNNING'],
        ['names' => ['/old-app'],  'state' => 'EXITED'],
    ]]];
    private const UPS_DATA = ['upsDevices' => [[
        'name'    => 'APC',
        'battery' => ['chargeLevel' => 100, 'estimatedRuntime' => 45],
        'power'   => ['loadPercentage' => 18.0],
    ]]];

    private function allGroups(): array
    {
        return [
            'array {'     => self::ARRAY_DATA,
            'info {'      => self::INFO_DATA,
            'metrics {'   => self::METRICS_DATA,
            'docker {'    => self::DOCKER_DATA,
            'upsDevices'  => self::UPS_DATA,
        ];
    }

    public function testOverviewHappyPathMapsAllGroups(): void
    {
        $o = $this->makeClient($this->allGroups())->overview();

        $this->assertNotNull($o);
        $this->assertSame('STARTED', $o['array']['state']);
        $this->assertSame(3000.0, $o['array']['capacity']['used']);
        $this->assertCount(1, $o['array']['disks']);
        $this->assertSame('disk1', $o['array']['disks'][0]['name']);
        $this->assertSame(38, $o['array']['disks'][0]['temp']);
        $this->assertCount(1, $o['array']['parities']);
        $this->assertCount(1, $o['array']['caches']);

        $this->assertSame('AMD Ryzen 7', $o['system']['cpuBrand']);
        $this->assertSame(12.5, $o['system']['cpuPercent']);
        $this->assertSame(40.0, $o['system']['memPercent']);
        $this->assertSame('2026-06-20T04:05:06Z', $o['system']['uptime']);

        $this->assertSame(2, $o['docker']['running']);
        $this->assertSame(3, $o['docker']['total']);
        $this->assertSame(['old-app'], $o['docker']['stopped']);

        $this->assertSame(100, $o['ups']['battery']);
        $this->assertSame(45, $o['ups']['runtime']);
        $this->assertSame(18.0, $o['ups']['load']);
    }

    public function testMissingGroupsDegradeToNullWithoutKillingOthers(): void
    {
        // UPS query fails (no UPS / scope missing) — the other groups survive.
        $responses = $this->allGroups();
        unset($responses['upsDevices']);
        $o = $this->makeClient($responses)->overview();

        $this->assertNotNull($o);
        $this->assertNull($o['ups']);
        $this->assertNotNull($o['array']);
        $this->assertNotNull($o['docker']);
    }

    public function testOverviewIsNullWhenEveryGroupFails(): void
    {
        $this->assertNull($this->makeClient([])->overview());
    }

    public function testOverviewIsNullAndSendsNothingWhenUnconfigured(): void
    {
        $client = $this->makeClient($this->allGroups(), ['unraid_url' => null]);
        $this->assertNull($client->overview());
        $this->assertSame([], $client->queriesSent);
    }

    public function testOverviewIsNullAndSendsNothingWhenKillSwitchedOff(): void
    {
        $client = $this->makeClient($this->allGroups(), ['unraid_enabled' => '0']);
        $this->assertNull($client->overview());
        $this->assertSame([], $client->queriesSent);
    }

    public function testOverviewIsCachedWithinTtl(): void
    {
        $client = $this->makeClient($this->allGroups());
        $client->overview();
        $sent = count($client->queriesSent);
        $client->overview();
        $this->assertSame($sent, count($client->queriesSent), 'second overview() within TTL must not re-query');
    }

    public function testSystemSurvivesMetricsBeingUnavailable(): void
    {
        // Older API without `metrics` — uptime/brand still come from `info`.
        $responses = $this->allGroups();
        unset($responses['metrics {']);
        $o = $this->makeClient($responses)->overview();

        $this->assertNotNull($o['system']);
        $this->assertNull($o['system']['cpuPercent']);
        $this->assertSame('2026-06-20T04:05:06Z', $o['system']['uptime']);
    }

    public function testAuthHeaderUsesXApiKey(): void
    {
        $client  = $this->makeClient([]);
        $headers = (new \ReflectionMethod($client, 'authHeaders'))->invoke($client);
        $this->assertContains('x-api-key: k3y', $headers);
    }

    public function testTlsVerificationTogglesWithSetting(): void
    {
        $on  = $this->makeClient([]);
        $opts = (new \ReflectionMethod($on, 'curlOptions'))->invoke($on);
        $this->assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);

        $off = $this->makeClient([], ['unraid_skip_tls_verify' => '1']);
        $opts = (new \ReflectionMethod($off, 'curlOptions'))->invoke($off);
        $this->assertFalse($opts[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(0, $opts[CURLOPT_SSL_VERIFYHOST]);
    }
}
