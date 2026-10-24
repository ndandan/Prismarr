<?php

namespace App\Tests\Twig;

use App\Dashboard\TopologyLayout;
use App\Service\Unifi\UnifiTopologyBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Renders the real map fragment (real Twig, real translator) over the probe-
 * shaped network of UnifiTopologyBuilderTest, pushed through the real builder
 * and layout, and pins the DOM contract the map JS relies on: one node group
 * per on-map node, one detail section per node plus the busiest-links one,
 * edge classes, escaping, and the empty state.
 *
 * Every client id here is all digits, and so is the U7's — PHP turns such an
 * array key into an int, so these fixtures catch an id read back from a key.
 */
class UnifiMapRenderTest extends KernelTestCase
{
    private const GW     = 'aabbcc000001';
    private const FLEX10 = 'aabbcc000002';
    private const FLEX5  = 'aabbcc000003';
    private const U7     = '784558000004'; // all digits: an int array key in PHP
    private const NAS    = '112233445501';
    private const PHONE  = '112233445502';
    private const LAPTOP = '112233445503';

    private static function port(int $idx, int $speed, string $media, float $tx = 0, float $rx = 0,
                                 bool $up = true, array $extra = []): array
    {
        return ['port_idx' => $idx, 'up' => $up, 'speed' => $speed, 'media' => $media,
                'tx_bytes-r' => $tx, 'rx_bytes-r' => $rx] + $extra;
    }

    /** UnifiTopologyBuilderTest's network: UDM → Flex 10 (→ Flex 5, U7 Pro, NAS), two Wi-Fi clients. */
    private static function devices(): array
    {
        return [
            [
                'mac' => 'aa:bb:cc:00:00:02', 'type' => 'usw', 'model' => 'USWED37', 'name' => 'Office Flex',
                'state' => 1,
                'uplink' => ['type' => 'wire', 'uplink_mac' => 'AA:BB:CC:00:00:01', 'uplink_remote_port' => 2,
                             'port_idx' => 9, 'speed' => 2500, 'max_speed' => 10000, 'media' => '10GE',
                             'rx_bytes-r' => 0, 'tx_bytes-r' => 0],
                'port_table' => [
                    self::port(1, 2500, '2P5GE', 1_000_000, 25_000, extra: [
                        'rx_errors' => 2, 'tx_errors' => 1, 'rx_dropped' => 3, 'tx_dropped' => 0]),
                    self::port(2, 0, '2P5GE', up: false),
                    self::port(5, 2500, '2P5GE', 400_000, 60_000, extra: [
                        'poe_enable' => true, 'poe_power' => '14.20']),
                    self::port(7, 2500, '2P5GE', 30_000, 5_000),
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
                'uplink' => ['type' => 'wire', 'port_idx' => 4, 'speed' => 2500, 'max_speed' => 10000,
                             'media' => '2.5GE', 'rx_bytes-r' => 1_296_116, 'tx_bytes-r' => 2_239_404],
                'port_table' => [
                    self::port(1, 2500, '2.5GE', 972_904, 33_907),
                    self::port(2, 2500, '2.5GE', 1_500_000, 70_000),
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
             'wired-tx_bytes-r' => 999, 'wired-rx_bytes-r' => 999],
            ['mac' => '11:22:33:44:55:03', 'hostname' => 'laptop', 'is_wired' => false,
             'ap_mac' => '78-45-58-00-00-04', 'essid' => 'Home', 'radio' => '6e', 'radio_proto' => 'be',
             'channel' => 37, 'signal' => -48, 'wifi_tx_retries_percentage' => 1.5, 'roam_count' => 0,
             'uptime' => 600, 'tx_bytes-r' => 400_000, 'rx_bytes-r' => 30_000],
        ];
    }

    private static function health(array $www = []): array
    {
        return [
            ['subsystem' => 'wan', 'status' => 'ok', 'isp_name' => 'Example Fiber'],
            $www + ['subsystem' => 'www', 'status' => 'ok', 'rx_bytes-r' => 12_500_000,
                    'tx_bytes-r' => 1_000_000, 'latency' => 9, 'drops' => 2, 'xput_down' => 0, 'xput_up' => 0],
        ];
    }

    private static function idOf(string $mac): string
    {
        return strtolower(str_replace([':', '-'], '', $mac));
    }

    private static function withPort(array $devices, string $id, int $idx, array $changes): array
    {
        foreach ($devices as $i => $d) {
            if (self::idOf($d['mac']) !== $id) continue;
            foreach ($d['port_table'] as $j => $p) {
                if ($p['port_idx'] === $idx) $devices[$i]['port_table'][$j] = $changes + $p;
            }
        }
        return $devices;
    }

    /** The reader's shape: builder output plus the layout. */
    private static function map(?array $devices = null, ?array $clients = null, ?array $health = null,
                                ?float $planDown = null): array
    {
        $t = UnifiTopologyBuilder::build($devices ?? self::devices(), $clients ?? self::clients(),
            $health ?? self::health(), $planDown, null);
        self::assertNotNull($t);
        return $t + ['layout' => TopologyLayout::build($t['nodes'], $t['edges'])];
    }

    private function render(?array $map): string
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        return $twig->render('unifi/_map.html.twig', ['map' => $map]);
    }

    /** @return array{0: string, 1: string} the edge group's class list and inner markup */
    private static function edge(string $html, string $to): array
    {
        $found = preg_match('~<g class="unifi-edge ([^"]*)" data-edge-to="' . $to . '">(.*?)</g>~s', $html, $m);
        self::assertSame(1, $found, "no edge group for $to");
        return [$m[1], $m[2]];
    }

    /** The node group's markup (it holds no nested <g>). */
    private static function node(string $html, string $id): string
    {
        $found = preg_match('~<g class="unifi-node[^"]*" data-select="' . $id . '".*?</g>~s', $html, $m);
        self::assertSame(1, $found, "no node group for $id");
        return $m[0];
    }

    /** The detail section's markup. */
    private static function section(string $html, string $id): string
    {
        $found = preg_match('~<section data-detail="' . $id . '"[^>]*>.*?</section>~s', $html, $m);
        self::assertSame(1, $found, "no detail section for '$id'");
        return $m[0];
    }

    public function testRendersOneNodeGroupPerOnMapNode(): void
    {
        $map  = self::map();
        $html = $this->render($map);

        preg_match_all('~<g class="unifi-node(?: offline)?" data-select="([^"]*)" data-parent="([^"]*)" tabindex="0" role="button" aria-label="[^"]*"~',
            $html, $m);
        $drawn    = $m[1];
        $expected = array_values(array_map(static fn(array $n): string => $n['id'],
            array_filter($map['nodes'], static fn(array $n): bool => $n['onMap'])));
        sort($drawn);
        sort($expected);

        $this->assertSame($expected, $drawn);
        $this->assertCount(6, $drawn); // internet, gateway, two switches, the U7, the NAS
        $this->assertNotContains(self::PHONE, $drawn, 'Wi-Fi clients are never drawn on the map');
        $this->assertNotContains(self::LAPTOP, $drawn);

        $parents = array_combine($m[1], $m[2]);
        $this->assertSame('', $parents['internet']);
        $this->assertSame(self::FLEX10, $parents[self::U7]);
        $this->assertSame(self::FLEX10, $parents[self::FLEX5]);
    }

    public function testEveryNodeHasADetailSectionPlusBusiest(): void
    {
        $map  = self::map();
        $html = $this->render($map);

        preg_match_all('~<section data-detail="([^"]*)"~', $html, $m);
        $sections = $m[1];
        $expected = array_merge([''], array_column($map['nodes'], 'id'));
        sort($sections);
        sort($expected);
        $this->assertSame($expected, $sections, 'one per node, Wi-Fi included, plus busiest');

        // Busiest is the default view; every node section starts hidden.
        $this->assertStringNotContainsString('hidden', substr(self::section($html, ''), 0, 40));
        foreach (array_column($map['nodes'], 'id') as $id) {
            $this->assertMatchesRegularExpression('~<section data-detail="' . $id . '"[^>]*\shidden>~', $html);
        }

        // A sparkline slot in each client section, and nowhere else.
        preg_match_all('~<div data-spark-slot="([^"]*)"></div>~', $html, $s);
        $slots = $s[1];
        sort($slots);
        $this->assertSame([self::NAS, self::PHONE, self::LAPTOP], $slots);
        $this->assertStringContainsString('<div data-spark-slot="' . self::PHONE . '"></div>', self::section($html, self::PHONE));
    }

    public function testIdleEdgeHasNoFlowPath(): void
    {
        // Nothing moving on the Flex 5's cable.
        $devices = self::withPort(self::devices(), self::FLEX10, 7, ['tx_bytes-r' => 0, 'rx_bytes-r' => 0]);
        $html    = $this->render(self::map($devices));

        [$class, $inner] = self::edge($html, self::FLEX5);
        $this->assertStringContainsString('tier-idle', $class);
        $this->assertStringContainsString('class="unifi-link"', $inner);
        $this->assertStringNotContainsString('unifi-flow', $inner);

        // A link carrying traffic does get its dots.
        [, $busy] = self::edge($html, self::NAS);
        $this->assertStringContainsString('unifi-flow', $busy);
    }

    public function testUnderNegotiatedEdgeHasClassAndLabel(): void
    {
        // The Flex 5 linked at 1G on a 2.5G port, both ends 2.5G-capable.
        $devices = self::withPort(self::devices(), self::FLEX10, 7, ['speed' => 1000]);
        $html    = $this->render(self::map($devices));

        [$class] = self::edge($html, self::FLEX5);
        $this->assertMatchesRegularExpression('~(^|\s)under(\s|$)~', $class);
        $this->assertStringContainsString('1 Gbit/s — can do 2.5 Gbit/s', self::node($html, self::FLEX5));

        [$other] = self::edge($html, self::U7);
        $this->assertDoesNotMatchRegularExpression('~(^|\s)under(\s|$)~', $other);
        $this->assertStringNotContainsString('can do', self::node($html, self::U7));
    }

    public function testHostileClientNameIsEscaped(): void
    {
        $clients            = self::clients();
        $clients[1]['name'] = '<img src=x onerror=1>';
        $html = $this->render(self::map(null, $clients));

        $this->assertStringNotContainsString('<img src=x onerror=1>', $html);
        $this->assertStringContainsString('aria-label="&lt;img src=x onerror=1&gt;"', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=1&gt;', self::section($html, self::NAS));
    }

    public function testNullMapRendersEmptyStateWithRetry(): void
    {
        $html = $this->render(null);

        $this->assertStringContainsString('data-dash-retry', $html);
        $this->assertStringContainsString('UniFi console unreachable', $html);
        $this->assertStringNotContainsString('data-unifi-map', $html);
    }

    public function testNoScriptOrStyleTagInFragment(): void
    {
        $html = $this->render(self::map());

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertDoesNotMatchRegularExpression('~\son[a-z]+=~i', $html, 'no inline handlers (CSP)');
    }

    public function testShellContract(): void
    {
        $map  = self::map();
        $html = $this->render($map);

        $this->assertStringContainsString('<div class="card" data-unifi-map>', $html);
        $this->assertStringContainsString('<div class="unifi-map-scroll" data-unifi-map-scroll>', $html);
        $this->assertStringContainsString('<div class="unifi-strip" data-unifi-strip>', $html);
        $this->assertStringContainsString(
            'viewBox="0 0 ' . $map['layout']['width'] . ' ' . $map['layout']['height'] . '"', $html);
    }

    public function testAllDigitIdsKeepTheirHooks(): void
    {
        $html = $this->render(self::map());

        foreach ([self::U7, self::NAS] as $id) {
            $this->assertStringContainsString('data-select="' . $id . '"', $html);
            $this->assertStringContainsString('data-detail="' . $id . '"', $html);
            $this->assertStringContainsString('data-edge-to="' . $id . '"', $html);
        }
        // The AP's client rows select the (all-digit) Wi-Fi ids, busiest first.
        $ap = self::section($html, self::U7);
        $this->assertMatchesRegularExpression(
            '~data-select="' . self::LAPTOP . '".*data-select="' . self::PHONE . '"~s', $ap);
    }

    public function testFlowSpeedDirectionAndWidthFollowTheEdge(): void
    {
        // The NAS uploading hard: 200 MB/s up on a 2.5G link = util 0.64.
        $devices = self::withPort(self::devices(), self::FLEX10, 1, ['tx_bytes-r' => 1_000, 'rx_bytes-r' => 200_000_000]);
        $html    = $this->render(self::map($devices));

        [$class, $inner] = self::edge($html, self::NAS);
        $this->assertStringContainsString('tier-busy', $class);
        $this->assertStringContainsString('class="unifi-flow speed-3 reverse"', $inner);
        $this->assertStringContainsString('stroke-width="3.42"', $inner); // 1.5 + 3 · 0.64

        // Downstream-dominant, util 0.0032 → slowest, forward.
        [, $u7] = self::edge($html, self::U7);
        $this->assertStringContainsString('class="unifi-flow speed-1"', $u7);
    }

    public function testInternetSectionStatesItsBasis(): void
    {
        $this->assertStringContainsString('of port speed', self::section($this->render(self::map()), 'internet'));

        $plan = $this->render(self::map(null, null, null, 500.0));
        $this->assertStringContainsString('of 500 Mb/s plan', self::section($plan, 'internet'));

        $speedtest = $this->render(self::map(null, null, self::health(['xput_down' => 940.0])));
        $this->assertStringContainsString('of last speedtest', self::section($speedtest, 'internet'));
    }

    public function testWifiClientOnTheGatewaysOwnRadioIsReachable(): void
    {
        // A client on the UDM's radio: its parent is the gateway, not an AP.
        $clients   = self::clients();
        $clients[] = ['mac' => '11:22:33:44:55:09', 'name' => 'Kitchen speaker', 'is_wired' => false,
                      'ap_mac' => 'aa:bb:cc:00:00:01', 'essid' => 'Home', 'radio' => 'ng', 'radio_proto' => 'n',
                      'signal' => -70, 'tx_bytes-r' => 3_000, 'rx_bytes-r' => 500];
        $html = $this->render(self::map(null, $clients));

        $own = self::section($html, '112233445509');
        $this->assertStringContainsString('data-parent="' . self::GW . '"', $own);
        $this->assertStringContainsString('<div data-spark-slot="112233445509"></div>', $own);
        $this->assertStringContainsString('Dream Machine', $own);
        $this->assertStringContainsString('data-select="112233445509"', self::section($html, self::GW));
    }

    public function testSwitchPortsSelectWhatIsPluggedIn(): void
    {
        $flex = self::section($this->render(self::map()), self::FLEX10);

        foreach ([self::NAS, self::U7, self::FLEX5, self::GW] as $id) { // port 9 is its uplink → the gateway
            $this->assertMatchesRegularExpression('~class="unifi-port[^"]*" data-select="' . $id . '"~', $flex);
        }
        $this->assertStringContainsString('14.2 W', $flex);
    }

    public function testNodesComeInColumnThenRowOrderForTabbing(): void
    {
        $map  = self::map();
        $html = $this->render($map);

        preg_match_all('~<g class="unifi-node[^"]*" data-select="([^"]*)"~', $html, $m);
        $boxes = $map['layout']['boxes'];
        $ids   = array_values(array_map(static fn(array $n): string => $n['id'],
            array_filter($map['nodes'], static fn(array $n): bool => $n['onMap'])));
        usort($ids, static fn(string $a, string $b): int =>
            [$boxes[$a]['x'], $boxes[$a]['y']] <=> [$boxes[$b]['x'], $boxes[$b]['y']]);

        $this->assertSame($ids, $m[1]);
        $this->assertSame(['internet', self::GW, self::FLEX10], array_slice($m[1], 0, 3));
    }

    public function testNullLayoutStillRendersTheStrip(): void
    {
        $map           = self::map();
        $map['layout'] = null;
        $html = $this->render($map);

        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringContainsString('data-unifi-map-scroll', $html);
        $this->assertStringContainsString('<section data-detail="">', $html);
        $this->assertStringContainsString('data-detail="' . self::NAS . '"', $html);
    }
}
