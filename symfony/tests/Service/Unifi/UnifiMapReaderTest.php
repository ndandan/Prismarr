<?php

namespace App\Tests\Service\Unifi;

use App\Service\ConfigService;
use App\Service\Unifi\UnifiMapReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The reader is glue — fetch three payloads, hand them to the (separately
 * tested) builder and layout, cache for 10 s — so the fixtures are the smallest
 * network that exercises it: a gateway, one switch behind it, a wired client
 * on the switch and a Wi-Fi client on the gateway's own radio.
 */
class UnifiMapReaderTest extends TestCase
{
    private const GW = 'aabbcc000001';

    private const HEALTH = [
        ['subsystem' => 'wan', 'status' => 'ok', 'isp_name' => 'Example Fiber'],
        ['subsystem' => 'www', 'status' => 'ok', 'rx_bytes-r' => 12_500_000, 'tx_bytes-r' => 1_000_000,
         'latency' => 9, 'drops' => 0],
    ];
    private const DEVICES = [
        ['mac' => 'aa:bb:cc:00:00:01', 'type' => 'udm', 'model' => 'UDMA67A', 'name' => 'Dream Machine',
         'state' => 1,
         'uplink' => ['type' => 'wire', 'port_idx' => 4, 'speed' => 2500, 'max_speed' => 10000,
                      'media' => '2.5GE', 'rx_bytes-r' => 1_296_116, 'tx_bytes-r' => 2_239_404],
         'port_table' => [
             ['port_idx' => 2, 'up' => true, 'speed' => 2500, 'media' => '2.5GE',
              'tx_bytes-r' => 1_500_000, 'rx_bytes-r' => 70_000],
         ]],
        ['mac' => 'aa:bb:cc:00:00:02', 'type' => 'usw', 'model' => 'USWED37', 'name' => 'Office Flex',
         'state' => 1,
         'uplink' => ['type' => 'wire', 'uplink_mac' => 'aa:bb:cc:00:00:01', 'uplink_remote_port' => 2,
                      'port_idx' => 9, 'speed' => 2500, 'max_speed' => 10000, 'media' => '10GE'],
         'port_table' => [
             ['port_idx' => 1, 'up' => true, 'speed' => 2500, 'media' => '2P5GE',
              'tx_bytes-r' => 1_000_000, 'rx_bytes-r' => 25_000],
         ]],
    ];
    private const STA = [
        ['mac' => '11:22:33:44:55:01', 'name' => 'Unraid', 'is_wired' => true,
         'sw_mac' => 'aa:bb:cc:00:00:02', 'sw_port' => 1, 'wired_rate_mbps' => 2500],
        ['mac' => '11:22:33:44:55:02', 'name' => 'Phone', 'is_wired' => false,
         'ap_mac' => 'aa:bb:cc:00:00:01', 'essid' => 'Home', 'radio' => 'na',
         'tx_bytes-r' => 50_000, 'rx_bytes-r' => 2_000],
    ];

    /** @return array{0: UnifiMapReader, 1: StubUnifiFetcher} */
    private function reader(array $responses, bool $fail = false, ?string $down = null, ?string $up = null): array
    {
        $config = $this->createStub(ConfigService::class);
        $config->method('get')->willReturnCallback(static fn(string $key): ?string => match ($key) {
            'unifi_plan_down_mbps' => $down,
            'unifi_plan_up_mbps'   => $up,
            default                => null,
        });
        $stub = new StubUnifiFetcher($responses, $fail);
        return [new UnifiMapReader($stub, $config, new NullLogger()), $stub];
    }

    private function all(): array
    {
        return ['stat/health' => self::HEALTH, 'stat/device' => self::DEVICES, 'stat/sta' => self::STA];
    }

    /** How many times a path was fetched; the needle is the whole path, not a substring. */
    private static function fetched(StubUnifiFetcher $stub, string $path): int
    {
        return count(array_filter($stub->paths, static fn(string $p): bool => $p === $path));
    }

    /** @return ?array the edge whose child is $to */
    private static function edgeTo(array $result, string $to): ?array
    {
        foreach ($result['edges'] as $e) {
            if ($e['to'] === $to) return $e;
        }
        return null;
    }

    public function testReadsAndBuilds(): void
    {
        [$reader] = $this->reader($this->all());
        $r = $reader->read();

        $this->assertNotNull($r);
        $this->assertNotEmpty($r['nodes']);
        $this->assertNotEmpty($r['edges']);
        $this->assertArrayHasKey('busiest', $r);
        // The layout carries a box for every on-map node: internet, gateway, switch, wired client.
        $this->assertNotNull($r['layout']);
        $this->assertCount(4, $r['layout']['boxes']);
        $this->assertSame(self::GW, self::edgeTo($r, self::GW)['to']);
    }

    public function testFetchesHealthThenDevicesThenClients(): void
    {
        [$reader, $stub] = $this->reader($this->all());
        $reader->read();

        $this->assertSame(['/stat/health', '/stat/device', '/stat/sta'], $stub->paths);
    }

    public function testDeadConsoleReturnsNullAndRetries(): void
    {
        [$reader, $stub] = $this->reader([], fail: true);

        $this->assertNull($reader->read());
        $this->assertSame(['/stat/health'], $stub->paths); // fail fast: nothing after health

        $this->assertNull($reader->read());
        $this->assertSame(2, self::fetched($stub, '/stat/health')); // not cached — fetched again
    }

    public function testCachesForTtl(): void
    {
        [$reader, $stub] = $this->reader($this->all());

        $first = $reader->read();
        $this->assertSame($first, $reader->read());
        foreach (['/stat/health', '/stat/device', '/stat/sta'] as $path) {
            $this->assertSame(1, self::fetched($stub, $path), $path);
        }

        $reader->nowOffset += 11;
        $reader->read();
        foreach (['/stat/health', '/stat/device', '/stat/sta'] as $path) {
            $this->assertSame(2, self::fetched($stub, $path), $path);
        }
    }

    public function testStillCachedJustInsideTtl(): void
    {
        [$reader, $stub] = $this->reader($this->all());

        $reader->read();
        $reader->nowOffset += 9;
        $reader->read();

        $this->assertSame(1, self::fetched($stub, '/stat/device'));
    }

    public function testResetDropsTheCache(): void
    {
        [$reader, $stub] = $this->reader($this->all());

        $reader->read();
        $reader->reset();
        $reader->read();

        $this->assertSame(2, self::fetched($stub, '/stat/device'));
    }

    public function testMissingStaStillDrawsInfrastructure(): void
    {
        [$reader] = $this->reader(['stat/health' => self::HEALTH, 'stat/device' => self::DEVICES, 'stat/sta' => null]);
        $r = $reader->read();

        $this->assertNotNull($r);
        $kinds = array_column($r['nodes'], 'kind');
        $this->assertContains('gateway', $kinds);
        $this->assertContains('switch', $kinds);
        $this->assertNotContains('wired', $kinds);
        $this->assertNotContains('wifi', $kinds);
        $this->assertNotNull($r['layout']);
    }

    public function testMissingDevicesReturnsNullAndDoesNotCache(): void
    {
        [$reader, $stub] = $this->reader(['stat/health' => self::HEALTH, 'stat/device' => null, 'stat/sta' => self::STA]);

        $this->assertNull($reader->read());
        $this->assertNull($reader->read());
        $this->assertSame(2, self::fetched($stub, '/stat/device')); // retried, not a cached null
    }

    public function testNoGatewayReturnsNullAndDoesNotCache(): void
    {
        $switchOnly = [self::DEVICES[1]];
        [$reader, $stub] = $this->reader(['stat/health' => self::HEALTH, 'stat/device' => $switchOnly, 'stat/sta' => self::STA]);

        $this->assertNull($reader->read());
        $this->assertNull($reader->read());
        $this->assertSame(2, self::fetched($stub, '/stat/device'));
    }

    public function testHealthErrorStillDrawsTheMap(): void
    {
        // An application-level error on stat/health (not a transport failure)
        // only costs the internet edge its live rates.
        [$reader] = $this->reader(['stat/health' => null, 'stat/device' => self::DEVICES, 'stat/sta' => self::STA]);
        $r = $reader->read();

        $this->assertNotNull($r);
        $this->assertNotNull(self::edgeTo($r, self::GW));
    }

    public function testPlanSpeedFromConfigFeedsBasis(): void
    {
        [$reader] = $this->reader($this->all(), down: '1000', up: '40');
        $e = self::edgeTo($reader->read(), self::GW);

        $this->assertSame('plan', $e['basis']);
        $this->assertSame(1000, $e['linkMbps']);
    }

    public function testUnsetPlanSpeedFallsBackToThePort(): void
    {
        [$reader] = $this->reader($this->all());
        $e = self::edgeTo($reader->read(), self::GW);

        $this->assertSame('port', $e['basis']);
    }

    public function testUnusablePlanSpeedIsIgnoredNotFatal(): void
    {
        [$reader] = $this->reader($this->all(), down: '1,000', up: 'fast');
        $e = self::edgeTo($reader->read(), self::GW);

        $this->assertNotNull($e);
        $this->assertNotSame('plan', $e['basis']);
    }

    #[DataProvider('mbpsProvider')]
    public function testParseMbps(?string $raw, ?float $expected): void
    {
        $this->assertSame($expected, UnifiMapReader::parseMbps($raw));
    }

    /** @return iterable<string, array{0: ?string, 1: ?float}> */
    public static function mbpsProvider(): iterable
    {
        yield 'plain integer'           => ['1000', 1000.0];
        yield 'surrounding whitespace'  => [' 40 ', 40.0];
        yield 'decimal'                 => ['940.5', 940.5];
        yield 'exactly one'             => ['1', 1.0];
        yield 'null'                    => [null, null];
        yield 'blank'                   => ['', null];
        yield 'whitespace only'         => ['   ', null];
        yield 'not a number'            => ['abc', null];
        yield 'zero'                    => ['0', null];
        yield 'negative'                => ['-5', null];
        // A comma-grouped value is ambiguous ("1,000" is 1000 in en, 1.0 in fr):
        // refuse it rather than guess and draw the wrong denominator.
        yield 'comma grouped'           => ['1,000', null];
        // A sub-1 Mb/s plan is not real, and the internet edge's integer
        // linkMbps would round it to 0.
        yield 'below one'               => ['0.4', null];
        yield 'overflows to infinity'   => ['1e999', null];
    }
}
