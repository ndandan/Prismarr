<?php

namespace App\Tests\Service\Unifi;

use App\Service\Unifi\UnifiTopologyBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are shaped like the 2026-10-23 probe of the owner's console
 * (docs/superpowers/probes/unifi-topology-probe-output.json): a UDM, a 10-port
 * Flex 2.5G on UDM port 2, a 5-port Flex behind it on Flex port 7, a U7 Pro on
 * Flex port 5, a wired NAS on Flex port 1 and two Wi-Fi clients. MACs come in
 * mixed case and separators on purpose — the builder must normalise them.
 */
class UnifiTopologyBuilderTest extends TestCase
{
    private const GW     = 'aabbcc000001';
    private const FLEX10 = 'aabbcc000002';
    private const FLEX5  = 'aabbcc000003';
    private const U7     = '784558000004'; // all digits: a real Ubiquiti OUI, and an int array key in PHP
    private const NAS    = '112233445501';
    private const PHONE  = '112233445502';
    private const LAPTOP = '112233445503';

    private static function port(int $idx, int $speed, string $media, float $tx = 0, float $rx = 0,
                                 bool $up = true, array $extra = []): array
    {
        return ['port_idx' => $idx, 'up' => $up, 'speed' => $speed, 'media' => $media,
                'tx_bytes-r' => $tx, 'rx_bytes-r' => $rx] + $extra;
    }

    /** Listed in the probe's order: the gateway comes LAST, so node order can't be input order. */
    private static function devices(): array
    {
        return [
            [
                'mac' => 'aa:bb:cc:00:00:02', 'type' => 'usw', 'model' => 'USWED37', 'name' => 'Office Flex',
                'state' => 1,
                // Switch uplink rates are mostly 0 in the field (the probe's known
                // trap) — the parent's port row carries the real numbers.
                'uplink' => ['type' => 'wire', 'uplink_mac' => 'AA:BB:CC:00:00:01', 'uplink_remote_port' => 2,
                             'port_idx' => 9, 'speed' => 2500, 'max_speed' => 10000, 'media' => '10GE',
                             'rx_bytes-r' => 0, 'tx_bytes-r' => 0],
                'port_table' => [
                    self::port(1, 2500, '2P5GE', 1_000_000, 25_000, extra: [   // NAS
                        'rx_errors' => 2, 'tx_errors' => 1, 'rx_dropped' => 3, 'tx_dropped' => 0]),
                    self::port(2, 0, '2P5GE', up: false),
                    self::port(5, 2500, '2P5GE', 400_000, 60_000, extra: [     // U7 Pro
                        'poe_enable' => true, 'poe_power' => '14.20']),          // a string on the wire
                    self::port(7, 2500, '2P5GE', 30_000, 5_000),                // Flex 5
                    self::port(9, 2500, '10GE', 70_000, 1_500_000, extra: ['is_uplink' => true]),
                ],
            ],
            [
                'mac' => '78:45:58:00:00:04', 'type' => 'uap', 'model' => 'U7PRO', 'name' => 'Hall U7 Pro',
                'state' => 1,
                'uplink' => ['type' => 'wire', 'uplink_mac' => 'aa:bb:cc:00:00:02', 'uplink_remote_port' => 5,
                             'port_idx' => 1, 'speed' => 2500, 'max_speed' => 2500,
                             'rx_bytes-r' => 2.33, 'tx_bytes-r' => 2.33],
            ],
            [
                // No name or hostname: the model is the label.
                'mac' => 'aa-bb-cc-00-00-03', 'type' => 'usw', 'model' => 'USWED35', 'state' => 1,
                'uplink' => ['type' => 'wire', 'uplink_mac' => 'aa:bb:cc:00:00:02', 'uplink_remote_port' => 7,
                             'port_idx' => 5, 'speed' => 2500, 'max_speed' => 2500, 'media' => '2P5GE',
                             'rx_bytes-r' => 0, 'tx_bytes-r' => 0],
                'port_table' => [
                    self::port(1, 0, '2P5GE', up: false),
                    self::port(5, 2500, '2P5GE', 5_000, 30_000, extra: ['is_uplink' => true]),
                ],
            ],
            [
                'mac' => 'AA:BB:CC:00:00:01', 'type' => 'udm', 'model' => 'UDMA67A', 'name' => 'Dream Machine',
                'state' => 1,
                'system-stats' => ['cpu' => '12.5', 'mem' => '61.0'],
                // The gateway's uplink is its WAN port: no uplink_mac.
                'uplink' => ['type' => 'wire', 'port_idx' => 4, 'speed' => 2500, 'max_speed' => 10000,
                             'media' => '2.5GE', 'rx_bytes-r' => 1_296_116, 'tx_bytes-r' => 2_239_404],
                'port_table' => [
                    self::port(1, 2500, '2.5GE', 972_904, 33_907),
                    self::port(2, 2500, '2.5GE', 1_500_000, 70_000),            // Flex 10
                    self::port(4, 2500, '2.5GE', 2_239_404, 1_296_116, extra: ['is_uplink' => true]),
                ],
            ],
        ];
    }

    private static function clients(): array
    {
        return [
            ['mac' => '11:22:33:44:55:02', 'name' => 'Phone', 'is_wired' => false,
             'ap_mac' => '78:45:58:00:00:04', 'essid' => 'Home', 'radio' => 'na', 'radio_proto' => 'ax',
             'channel' => 36, 'signal' => -55, 'wifi_tx_retries_percentage' => 4.2, 'roam_count' => 3,
             'uptime' => 1200, 'tx_bytes-r' => 50_000, 'rx_bytes-r' => 2_000],
            ['mac' => '11:22:33:44:55:01', 'name' => 'Unraid', 'hostname' => 'nas', 'is_wired' => true,
             'sw_mac' => 'aa:bb:cc:00:00:02', 'sw_port' => 1, 'wired_rate_mbps' => 2500, 'uptime' => 86400,
             // The port row wins over the client's own counters when both exist.
             'wired-tx_bytes-r' => 999, 'wired-rx_bytes-r' => 999],
            ['mac' => '11:22:33:44:55:03', 'hostname' => 'laptop', 'is_wired' => false,
             'ap_mac' => '78-45-58-00-00-04', 'essid' => 'Home', 'radio' => '6e', 'radio_proto' => 'be',
             'channel' => 37, 'signal' => -48, 'wifi_tx_retries_percentage' => 1.5, 'roam_count' => 0,
             'uptime' => 600, 'tx_bytes-r' => 400_000, 'rx_bytes-r' => 30_000],
        ];
    }

    /** stat/health is a LIST of subsystem rows. `$www` overrides the www row. */
    private static function health(array $www = []): array
    {
        return [
            ['subsystem' => 'wlan', 'status' => 'ok', 'num_user' => 2],
            ['subsystem' => 'wan', 'status' => 'ok', 'isp_name' => 'Example Fiber',
             // Deliberately different from the device row's system-stats: the
             // device row is preferred.
             'gw_system-stats' => ['cpu' => '99', 'mem' => '99']],
            $www + ['subsystem' => 'www', 'status' => 'ok', 'rx_bytes-r' => 12_500_000,
                    'tx_bytes-r' => 1_000_000, 'latency' => 9, 'drops' => 2, 'xput_down' => 0, 'xput_up' => 0],
            ['subsystem' => 'lan', 'status' => 'ok'],
            ['subsystem' => 'vpn', 'status' => 'unknown'],
        ];
    }

    private static function idOf(string $mac): string
    {
        return strtolower(str_replace([':', '-'], '', $mac));
    }

    /** @param callable(array): array $patch */
    private static function withDevice(array $devices, string $id, callable $patch): array
    {
        foreach ($devices as $i => $d) {
            if (self::idOf($d['mac']) === $id) $devices[$i] = $patch($d);
        }
        return $devices;
    }

    private static function withPort(array $devices, string $id, int $idx, array $changes): array
    {
        return self::withDevice($devices, $id, static function (array $d) use ($idx, $changes): array {
            foreach ($d['port_table'] as $j => $p) {
                if ($p['port_idx'] === $idx) $d['port_table'][$j] = $changes + $p;
            }
            return $d;
        });
    }

    private static function build(?array $devices = null, ?array $clients = null, ?array $health = null,
                                  ?float $planDown = null, ?float $planUp = null): array
    {
        $t = UnifiTopologyBuilder::build($devices ?? self::devices(), $clients ?? self::clients(),
            $health ?? self::health(), $planDown, $planUp);
        self::assertNotNull($t);
        return $t;
    }

    private static function node(array $t, string $id): array
    {
        foreach ($t['nodes'] as $n) if ($n['id'] === $id) return $n;
        self::fail("no node $id");
    }

    private static function edgeTo(array $t, string $id): array
    {
        foreach ($t['edges'] as $e) if ($e['to'] === $id) return $e;
        self::fail("no edge to $id");
    }

    /** Every laid-out node must reach the internet by parentId, or Task 4 orphans it. */
    private function assertEveryNodeReachesTheInternet(array $t): void
    {
        $parents = array_column($t['nodes'], 'parentId', 'id');
        foreach ($t['nodes'] as $n) {
            $cur = $n['id'];
            for ($hops = 0; $cur !== 'internet'; $hops++) {
                $this->assertLessThan(count($parents), $hops, "{$n['id']} never reaches the internet");
                $this->assertArrayHasKey($cur, $parents);
                $cur = $parents[$cur];
                $this->assertNotNull($cur, "{$n['id']} has a broken parent chain");
            }
        }
    }

    public function testTreeParentsFollowUplinks(): void
    {
        $devices   = self::devices();
        $devices[] = ['mac' => 'aa:bb:cc:00:00:99', 'type' => 'uph', 'name' => 'Desk phone', 'state' => 1];
        $t = self::build($devices);

        $ids = array_column($t['nodes'], 'id');
        $this->assertSame(['internet', self::GW], array_slice($ids, 0, 2));
        $this->assertNotContains('aabbcc000099', $ids, 'non-gateway/switch/AP types are skipped');

        $parents = array_column($t['nodes'], 'parentId', 'id');
        ksort($parents);
        $expected = [
            'internet'   => null,
            self::GW     => 'internet',
            self::FLEX10 => self::GW,
            self::FLEX5  => self::FLEX10, // a switch behind a switch
            self::U7     => self::FLEX10,
            self::NAS    => self::FLEX10,
            self::PHONE  => self::U7,
            self::LAPTOP => self::U7,
        ];
        ksort($expected);
        $this->assertSame($expected, $parents);

        $kinds = array_column($t['nodes'], 'kind', 'id');
        $this->assertSame('internet', $kinds['internet']);
        $this->assertSame('gateway', $kinds[self::GW]);
        $this->assertSame('switch', $kinds[self::FLEX5]);
        $this->assertSame('ap', $kinds[self::U7]);
        $this->assertSame('wired', $kinds[self::NAS]);
        $this->assertSame('wifi', $kinds[self::PHONE]);

        // name ?? hostname ?? model ?? formatted MAC
        $this->assertSame('Unraid', self::node($t, self::NAS)['name']);
        $this->assertSame('laptop', self::node($t, self::LAPTOP)['name']);
        $this->assertSame('USWED35', self::node($t, self::FLEX5)['name']);
        $this->assertSame('USWED37', self::node($t, self::FLEX10)['model']);

        // One edge per laid-out non-root node, always parent → node.
        $this->assertCount(5, $t['edges']);
        foreach ($t['edges'] as $e) {
            $this->assertSame($parents[$e['to']], $e['from']);
        }
        $this->assertEveryNodeReachesTheInternet($t);
    }

    public function testNameFallsBackToTheFormattedMac(): void
    {
        $devices   = self::devices();
        $devices[] = ['mac' => 'AA-BB-CC-00-00-0A', 'type' => 'uap', 'state' => 1];
        $this->assertSame('aa:bb:cc:00:00:0a', self::node(self::build($devices), 'aabbcc00000a')['name']);
    }

    public function testWifiClientsAreOffMapAndCountedOnTheirAp(): void
    {
        $clients   = self::clients();
        $clients[] = ['mac' => '11:22:33:44:55:09', 'name' => 'Ghost', 'is_wired' => false,
                      'ap_mac' => 'de:ad:be:ef:00:00', 'tx_bytes-r' => 9_000_000];
        $t = self::build(null, $clients);

        foreach ($t['nodes'] as $n) {
            $this->assertSame($n['kind'] !== 'wifi', $n['onMap'], $n['id']);
        }
        $this->assertNotContains('112233445509', array_column($t['nodes'], 'id'), 'unknown AP → dropped');
        $this->assertNotContains(self::PHONE, array_column($t['edges'], 'to'));

        $ap = self::node($t, self::U7)['detail'];
        $this->assertSame(2, $ap['clientCount']);
        $this->assertSame([self::LAPTOP, self::PHONE], $ap['clientIds']); // busiest first

        $phone = self::node($t, self::PHONE);
        $this->assertSame(50_000.0, $phone['downBps']); // stat/sta tx = toward the client
        $this->assertSame(2_000.0, $phone['upBps']);
        $this->assertSame([
            'apName'   => 'Hall U7 Pro',
            'ssid'     => 'Home',
            'band'     => '5 GHz',
            'channel'  => 36,
            'proto'    => 'ax',
            'signal'   => -55,
            'retryPct' => 4.2,
            'roams'    => 3,
            'uptime'   => 1200,
        ], $phone['detail']);
        $this->assertSame('6 GHz', self::node($t, self::LAPTOP)['detail']['band']);
    }

    public function testEdgeRateComesFromParentPortRow(): void
    {
        $t = self::build();

        $nas = self::edgeTo($t, self::NAS);
        $this->assertSame(self::FLEX10, $nas['from']);
        $this->assertSame(1_000_000.0, $nas['downBps']); // parent port tx = down, not the sta's 999
        $this->assertSame(25_000.0, $nas['upBps']);
        $this->assertSame(2500, $nas['linkMbps']);
        $this->assertEqualsWithDelta(0.0032, $nas['util'], 1e-9);
        $this->assertSame('quiet', $nas['tier']);
        $this->assertNull($nas['basis']);
        $this->assertSame(1_000_000.0, self::node($t, self::NAS)['downBps']);

        // The Flex's own uplink counters are 0 — UDM port 2 is the truth.
        $this->assertSame(1_500_000.0, self::edgeTo($t, self::FLEX10)['downBps']);

        // AP node rates are its uplink edge's.
        $ap = self::node($t, self::U7);
        $this->assertSame(400_000.0, $ap['downBps']);
        $this->assertSame(60_000.0, $ap['upBps']);
    }

    public function testUtilisationTiers(): void
    {
        // 1000 Mb/s link: 125 000 000 B/s is 100 %.
        $cases = [
            'quiet at 0.49'     => [61_250_000, 0, 0.49, 'quiet'],
            'busy at 0.5'       => [62_500_000, 0, 0.5, 'busy'],
            'saturated at 0.9'  => [112_500_000, 0, 0.9, 'saturated'],
            'idle under 1 kB/s' => [500, 499, 0.000004, 'idle'],
            'quiet at 1 kB/s'   => [500, 500, 0.000004, 'quiet'],
            'up drives util'    => [0, 62_500_000, 0.5, 'busy'],
        ];
        foreach ($cases as $label => [$tx, $rx, $util, $tier]) {
            $devices = self::withPort(self::devices(), self::FLEX10, 1,
                ['speed' => 1000, 'tx_bytes-r' => $tx, 'rx_bytes-r' => $rx]);
            $e = self::edgeTo(self::build($devices), self::NAS);
            $this->assertEqualsWithDelta($util, $e['util'], 1e-9, $label);
            $this->assertSame($tier, $e['tier'], $label);
        }
    }

    /** Review Focus 1. */
    public function testUnknownUplinkMacFallsBackToGateway(): void
    {
        $devices   = self::devices();
        // Uplinks into something stat/device doesn't list (unmanaged hop). Its
        // uplink_remote_port 2 must NOT be looked up on the gateway — UDM port 2
        // is the Flex 10's.
        $devices[] = ['mac' => 'aa:bb:cc:00:00:05', 'type' => 'usw', 'name' => 'Garage Flex', 'state' => 1,
                      'uplink' => ['uplink_mac' => 'de:ad:be:ef:00:00', 'uplink_remote_port' => 2,
                                   'speed' => 1000, 'max_speed' => 2500, 'media' => '2P5GE',
                                   'rx_bytes-r' => 40_000, 'tx_bytes-r' => 3_000]];
        // Adopting: no uplink block at all.
        $devices[] = ['mac' => 'aa:bb:cc:00:00:06', 'type' => 'uap', 'name' => 'New AP', 'state' => 0];
        $t = self::build($devices);

        foreach (['aabbcc000005', 'aabbcc000006'] as $id) {
            $this->assertSame(self::GW, self::node($t, $id)['parentId']);
            $e = self::edgeTo($t, $id);
            $this->assertSame(self::GW, $e['from']);
            $this->assertNull($e['linkMbps']);
            $this->assertSame(0.0, $e['util']);
            $this->assertFalse($e['underNegotiated']);
            $this->assertNull($e['capableMbps']);
        }
        // Its own uplink counters (rx = arriving from upstream = down), not UDM port 2's.
        $garage = self::edgeTo($t, 'aabbcc000005');
        $this->assertSame(40_000.0, $garage['downBps']);
        $this->assertSame(3_000.0, $garage['upBps']);
        $this->assertSame('quiet', $garage['tier']);
        $this->assertSame(self::FLEX10, self::edgeTo($t, self::FLEX10)['to']); // untouched
        $this->assertEveryNodeReachesTheInternet($t);
    }

    public function testUplinkCycleIsBrokenAtTheGateway(): void
    {
        $devices   = self::devices();
        // Mid-reconvergence garbage: A ↔ B, and C (all-digit MAC) uplinking to itself.
        $devices[] = ['mac' => 'aa:bb:cc:00:00:0a', 'type' => 'usw', 'state' => 1,
                      'uplink' => ['uplink_mac' => 'aa:bb:cc:00:00:0b', 'uplink_remote_port' => 1]];
        $devices[] = ['mac' => 'aa:bb:cc:00:00:0b', 'type' => 'usw', 'state' => 1,
                      'uplink' => ['uplink_mac' => 'aa:bb:cc:00:00:0a', 'uplink_remote_port' => 1]];
        $devices[] = ['mac' => '78:45:58:00:00:12', 'type' => 'uap', 'state' => 1,
                      'uplink' => ['uplink_mac' => '78:45:58:00:00:12', 'uplink_remote_port' => 1]];
        $t = self::build($devices);

        $this->assertSame(self::GW, self::node($t, 'aabbcc00000a')['parentId']); // first of the loop
        $this->assertSame('aabbcc00000a', self::node($t, 'aabbcc00000b')['parentId']);
        $this->assertSame(self::GW, self::node($t, '784558000012')['parentId']);
        $this->assertEveryNodeReachesTheInternet($t);
    }

    /** Review Focus 2. */
    public function testZeroOrMissingLinkSpeedGivesZeroUtil(): void
    {
        // Offline AP on a port at speed 0 that still shows stale counters.
        $devices = self::withDevice(self::devices(), self::U7, static fn(array $d): array => ['state' => 0] + $d);
        $devices = self::withPort($devices, self::FLEX10, 5, ['up' => false, 'speed' => 0]);
        // Flex 5: no parent port row and no uplink speed at all, but traffic.
        $devices = self::withDevice($devices, self::FLEX10, static function (array $d): array {
            $d['port_table'] = array_values(array_filter($d['port_table'],
                static fn(array $p): bool => $p['port_idx'] !== 7));
            return $d;
        });
        $devices = self::withDevice($devices, self::FLEX5, static function (array $d): array {
            unset($d['uplink']['speed']);
            $d['uplink']['rx_bytes-r'] = 50_000;
            return $d;
        });
        $t = self::build($devices);

        $ap = self::node($t, self::U7);
        $this->assertFalse($ap['online']);
        $e = self::edgeTo($t, self::U7);
        $this->assertNull($e['linkMbps']);
        $this->assertSame(0.0, $e['util']);
        $this->assertSame(0.0, $e['downBps']);
        $this->assertSame('idle', $e['tier']);
        $this->assertFalse($e['underNegotiated']);
        $this->assertTrue(self::node($t, self::FLEX10)['online']);

        $flex5 = self::edgeTo($t, self::FLEX5);
        $this->assertNull($flex5['linkMbps']);
        $this->assertSame(0.0, $flex5['util']);
        $this->assertSame(50_000.0, $flex5['downBps']);
    }

    /** Review Focus 3. */
    public function testWiredClientWithoutPortRowUsesStaFields(): void
    {
        $clients = self::clients();
        $clients[1] = ['sw_port' => 3, 'wired_rate_mbps' => 1000,
                       'wired-tx_bytes-r' => 500000, 'wired-rx_bytes-r' => 20000] + $clients[1];
        // No sw_mac at all → hangs off the gateway, still on the map.
        $clients[] = ['mac' => '11:22:33:44:55:04', 'hostname' => 'printer', 'is_wired' => true,
                      'wired_rate_mbps' => 100, 'wired-tx_bytes-r' => 2_000, 'wired-rx_bytes-r' => 100];
        $t = self::build(null, $clients);

        $e = self::edgeTo($t, self::NAS);
        $this->assertSame(self::FLEX10, $e['from']);
        $this->assertSame(500_000.0, $e['downBps']);
        $this->assertSame(20_000.0, $e['upBps']);
        $this->assertSame(1000, $e['linkMbps']);
        $this->assertEqualsWithDelta(0.004, $e['util'], 1e-9);
        $this->assertSame('quiet', $e['tier']);

        $d = self::node($t, self::NAS)['detail'];
        $this->assertSame('Office Flex', $d['switchName']);
        $this->assertSame(3, $d['port']);
        $this->assertSame(1000, $d['linkMbps']);
        $this->assertNull($d['portCapableMbps']);
        $this->assertNull($d['errors']);

        $printer = self::node($t, '112233445504');
        $this->assertSame(self::GW, $printer['parentId']);
        $this->assertTrue($printer['onMap']);
        $this->assertSame(100, self::edgeTo($t, '112233445504')['linkMbps']);
        $this->assertSame(2_000.0, $printer['downBps']);
        $this->assertNull($printer['detail']['switchName']);
        $this->assertNull($printer['detail']['port']);
    }

    /** No parent port row for a DEVICE: its own uplink counters, mirrored (its rx is downstream). */
    public function testDeviceWithoutParentPortRowUsesItsOwnUplink(): void
    {
        $devices = self::withDevice(self::devices(), self::FLEX10, static function (array $d): array {
            $d['port_table'] = array_values(array_filter($d['port_table'],
                static fn(array $p): bool => $p['port_idx'] !== 7));
            return $d;
        });
        $devices = self::withDevice($devices, self::FLEX5, static function (array $d): array {
            $d['uplink'] = ['speed' => 1000, 'rx_bytes-r' => 300_000, 'tx_bytes-r' => 10_000] + $d['uplink'];
            return $d;
        });
        $e = self::edgeTo(self::build($devices), self::FLEX5);

        $this->assertSame(self::FLEX10, $e['from']);
        $this->assertSame(300_000.0, $e['downBps']);
        $this->assertSame(10_000.0, $e['upBps']);
        $this->assertSame(1000, $e['linkMbps']);
        $this->assertNull($e['capableMbps'], 'no parent port media → capability unknown');
        $this->assertFalse($e['underNegotiated']);
    }

    public function testUnderNegotiationOnlyBetweenUnifiDevices(): void
    {
        // Flex uplink can do 10G, UDM port is 2.5GE at 2500: 2.5G IS the
        // both-ends maximum — not a fault (the spec's correction).
        $t = self::build();
        $flex = self::edgeTo($t, self::FLEX10);
        $this->assertSame(2500, $flex['linkMbps']);
        $this->assertSame(2500, $flex['capableMbps']);
        $this->assertFalse($flex['underNegotiated']);
        $internet = self::edgeTo($t, self::GW);
        $this->assertFalse($internet['underNegotiated']);
        $this->assertNull($internet['capableMbps']);

        // Same link into a 10GE port: both ends can do 10G, it runs at 2.5G.
        $flex = self::edgeTo(self::build(self::withPort(self::devices(), self::GW, 2, ['media' => '10GE'])),
            self::FLEX10);
        $this->assertTrue($flex['underNegotiated']);
        $this->assertSame(10000, $flex['capableMbps']);

        // A NAS at 1G on a 2.5G port: never flagged (NIC capability unknown);
        // the port's capability is informational only.
        $t = self::build(self::withPort(self::devices(), self::FLEX10, 1, ['speed' => 1000]));
        $nas = self::edgeTo($t, self::NAS);
        $this->assertSame(1000, $nas['linkMbps']);
        $this->assertFalse($nas['underNegotiated']);
        $this->assertNull($nas['capableMbps']);
        $this->assertSame(2500, self::node($t, self::NAS)['detail']['portCapableMbps']);

        // Child capability falls back to cap(uplink.media) when max_speed is absent.
        $devices = self::withDevice(self::devices(), self::FLEX5, static function (array $d): array {
            unset($d['uplink']['max_speed']);
            return $d;
        });
        $flex5 = self::edgeTo(self::build($devices), self::FLEX5);
        $this->assertSame(2500, $flex5['capableMbps']);
        $this->assertFalse($flex5['underNegotiated']);
        $flex5 = self::edgeTo(self::build(self::withPort($devices, self::FLEX10, 7, ['speed' => 1000])), self::FLEX5);
        $this->assertTrue($flex5['underNegotiated']);
        $this->assertSame(2500, $flex5['capableMbps']);
    }

    public function testInternetBasisFallbackChain(): void
    {
        // www: 12.5 MB/s down = 100 Mb/s, 1 MB/s up = 8 Mb/s.
        $e = self::edgeTo(self::build(null, null, null, 1000.0, 40.0), self::GW);
        $this->assertSame('internet', $e['from']);
        $this->assertSame(12_500_000.0, $e['downBps']);
        $this->assertSame(1_000_000.0, $e['upBps']);
        $this->assertSame('plan', $e['basis']);
        $this->assertSame(1000, $e['linkMbps']);
        $this->assertEqualsWithDelta(0.2, $e['util'], 1e-9); // max(100/1000, 8/40)

        $e = self::edgeTo(self::build(null, null, self::health(['xput_down' => 900, 'xput_up' => 40])), self::GW);
        $this->assertSame('speedtest', $e['basis']);
        $this->assertSame(900, $e['linkMbps']);
        $this->assertEqualsWithDelta(0.2, $e['util'], 1e-9); // max(100/900, 8/40)

        $t = self::build();
        $e = self::edgeTo($t, self::GW);
        $this->assertSame('port', $e['basis']);
        $this->assertSame(2500, $e['linkMbps']); // the gateway's uplink.speed
        $this->assertEqualsWithDelta(0.04, $e['util'], 1e-9);
        $this->assertSame('port', self::node($t, 'internet')['detail']['basis']);

        $devices = self::withDevice(self::devices(), self::GW, static function (array $d): array {
            unset($d['uplink']['speed']);
            return $d;
        });
        $e = self::edgeTo(self::build($devices), self::GW);
        $this->assertNull($e['basis']);
        $this->assertNull($e['linkMbps']);
        $this->assertSame(0.0, $e['util']);

        // A speed that rounds to 0 is no speed — not a WAN denominator of 0.
        $devices = self::withDevice(self::devices(), self::GW, static function (array $d): array {
            $d['uplink']['speed'] = 0.4;
            return $d;
        });
        $e = self::edgeTo(self::build($devices), self::GW);
        $this->assertNull($e['basis']);
        $this->assertSame(0.0, $e['util']);
    }

    public function testInternetAndGatewayDetail(): void
    {
        $t = self::build(null, null, null, 1000.0, 40.0);

        $internet = self::node($t, 'internet');
        $this->assertNull($internet['parentId']);
        $this->assertTrue($internet['onMap']);
        $this->assertTrue($internet['online']);
        $this->assertSame(12_500_000.0, $internet['downBps']);
        $this->assertSame([
            'latencyMs'   => 9,
            'drops'       => 2,
            'isp'         => 'Example Fiber',
            'basis'       => 'plan',
            'planDownMbps' => 1000.0,
            'planUpMbps'  => 40.0,
        ], $internet['detail']);

        $gw = self::node($t, self::GW);
        $this->assertSame('Dream Machine', $gw['name']);
        $this->assertSame(1_000_000.0, $gw['upBps']);
        $this->assertSame(12.5, $gw['detail']['cpuPercent']); // device row, not gw_system-stats
        $this->assertSame(61.0, $gw['detail']['memPercent']);
        $this->assertSame('Example Fiber', $gw['detail']['isp']);
        $this->assertSame('plan', $gw['detail']['basis']);
    }

    public function testSwitchPortsListWhatIsPluggedIn(): void
    {
        $ports = self::node(self::build(), self::FLEX10)['detail']['ports'];

        $this->assertSame([1, 2, 5, 7, 9], array_column($ports, 'idx'));
        $this->assertSame(
            [self::NAS, null, self::U7, self::FLEX5, self::GW], // uplink port → the parent
            array_column($ports, 'nodeId'),
        );
        $this->assertSame(['idx' => 1, 'up' => true, 'linkMbps' => 2500, 'tier' => 'quiet',
                           'poeW' => null, 'nodeId' => self::NAS], $ports[0]);
        $this->assertSame(['idx' => 2, 'up' => false, 'linkMbps' => null, 'tier' => 'idle',
                           'poeW' => null, 'nodeId' => null], $ports[1]);
        $this->assertSame(14.2, $ports[2]['poeW']);
    }

    public function testWiredClientDetail(): void
    {
        $this->assertSame([
            'switchName'      => 'Office Flex',
            'port'            => 1,
            'linkMbps'        => 2500,
            'portCapableMbps' => 2500,
            'uptime'          => 86400,
            'errors'          => 6, // 2 + 1 + 3 + 0
        ], self::node(self::build(), self::NAS)['detail']);
    }

    public function testBusiestIsTopFiveByUtil(): void
    {
        // Seven wired clients on gateway ports at known utilisations of a 1G link,
        // plus one idle client. No health → the internet edge carries nothing.
        $utils   = [1 => 0.1, 2 => 0.7, 3 => 0.3, 4 => 0.95, 5 => 0.05, 6 => 0.5, 7 => 0.2, 8 => 0.0];
        $ports   = [];
        $clients = [];
        foreach ($utils as $idx => $u) {
            $ports[]   = self::port($idx, 1000, 'GE', $u * 125_000_000, 0);
            $clients[] = ['mac' => sprintf('11:22:33:44:56:%02d', $idx), 'is_wired' => true,
                          'sw_mac' => 'aa:bb:cc:00:00:01', 'sw_port' => $idx];
        }
        $devices = [['mac' => 'aa:bb:cc:00:00:01', 'type' => 'udm', 'state' => 1, 'port_table' => $ports]];
        $busiest = UnifiTopologyBuilder::build($devices, $clients, null, null, null)['busiest'];

        $this->assertCount(5, $busiest);
        $this->assertSame(['112233445604', '112233445602', '112233445606', '112233445603', '112233445607'],
            array_column($busiest, 'to'));
        $got = array_column($busiest, 'util');
        $sorted = $got;
        rsort($sorted);
        $this->assertSame($sorted, $got);
        $this->assertEqualsWithDelta(0.95, $got[0], 1e-9);
        // Busiest rows are full edges, ready for the strip.
        $this->assertSame('saturated', $busiest[0]['tier']);
        $this->assertSame(self::GW, $busiest[0]['from']);
    }

    public function testBusiestCanIncludeTheInternetEdge(): void
    {
        $busiest = self::build(null, null, null, 1000.0, 40.0)['busiest'];
        $this->assertSame(self::GW, $busiest[0]['to']); // 0.2 beats every LAN link here
        $this->assertSame('plan', $busiest[0]['basis']);
        foreach ($busiest as $e) $this->assertGreaterThan(0.0, $e['util']);
    }

    public function testNoGatewayReturnsNull(): void
    {
        $noGateway = array_values(array_filter(self::devices(), static fn(array $d): bool => $d['type'] !== 'udm'));
        $this->assertNull(UnifiTopologyBuilder::build($noGateway, self::clients(), self::health(), null, null));
        $this->assertNull(UnifiTopologyBuilder::build([], [], null, null, null));
        $this->assertNull(UnifiTopologyBuilder::build(['nope', 42, null], [], null, null, null));
        // A gateway with no MAC can't be a node, so it isn't a gateway.
        $this->assertNull(UnifiTopologyBuilder::build([['type' => 'udm', 'state' => 1]], [], null, null, null));
    }

    public function testMalformedRowsAreSkippedNotFatal(): void
    {
        $devices   = self::devices();
        $devices[] = 'garbage';
        $devices[] = ['mac' => ['not', 'a', 'string'], 'type' => 'usw'];
        $devices[] = ['mac' => 'aa:bb:cc:00:00:07', 'type' => ['usw'], 'state' => 1];
        $devices[] = ['mac' => 'aa:bb:cc:00:00:08', 'type' => 'usw', 'state' => 1,
                      'uplink' => 'eth0', 'port_table' => ['x', ['port_idx' => 'one']]];
        $devices[] = $devices[0]; // duplicate MAC: first wins
        $clients   = self::clients();
        $clients[] = null;
        $clients[] = ['mac' => '', 'is_wired' => true];
        $clients[] = ['mac' => '11:22:33:44:55:01', 'name' => 'Duplicate NAS', 'is_wired' => true];
        $health    = ['nope', ['subsystem' => ['www']], ...self::health()];

        $t = self::build($devices, $clients, $health);

        $ids = array_column($t['nodes'], 'id');
        $this->assertSame(count($ids), count(array_unique($ids)), 'ids are unique');
        $this->assertNotContains('aabbcc000007', $ids);
        $this->assertSame([], self::node($t, 'aabbcc000008')['detail']['ports']);
        $this->assertSame(self::GW, self::node($t, 'aabbcc000008')['parentId']);
        $this->assertSame('Unraid', self::node($t, self::NAS)['name']);
        $this->assertSame(9, self::node($t, 'internet')['detail']['latencyMs']);
    }
}
