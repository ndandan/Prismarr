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
                                ?float $planDown = null, ?float $planUp = null): array
    {
        $t = UnifiTopologyBuilder::build($devices ?? self::devices(), $clients ?? self::clients(),
            $health ?? self::health(), $planDown, $planUp);
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

    /**
     * The percentage is the busier direction against its own denominator; the
     * label must name that side. 100 Mb/s down of 1000 = 10 %, 38 Mb/s up of
     * 40 = 95 % → "95% … of 40 Mb/s upload plan", never "of 1000 Mb/s plan".
     */
    public function testInternetBasisNamesTheSideBehindThePercentage(): void
    {
        $html = $this->render(self::map(null, null, self::health(['tx_bytes-r' => 4_750_000]), 1000.0, 40.0));

        foreach (['internet', self::GW] as $id) {
            $section = self::section($html, $id);
            $this->assertStringContainsString('of 40 Mb/s upload plan', $section, $id);
            $this->assertStringContainsString('95%', $section, $id);
            $this->assertStringNotContainsString('1000 Mb/s', $section, $id);
        }

        // Download-heavy on the same plan: the plain headline wording.
        $down = $this->render(self::map(null, null, null, 1000.0, 400.0));
        $this->assertStringContainsString('of 1000 Mb/s plan', self::section($down, 'internet'));
        $this->assertStringNotContainsString('upload', self::section($down, 'internet'));

        $speedtest = $this->render(self::map(null, null,
            self::health(['tx_bytes-r' => 4_750_000, 'xput_down' => 900.0, 'xput_up' => 40.0])));
        $this->assertStringContainsString('of last speedtest upload', self::section($speedtest, 'internet'));
    }

    /**
     * util × 100 rounded to a whole number read "0%" on almost every home-LAN
     * row of the busiest list, whose rows are there BECAUSE util > 0.
     */
    public function testLoadShowsOneDecimalBelowTenPercent(): void
    {
        $busiest = self::section($this->render(self::map()), '');

        $row = static function (string $html, string $to): string {
            $found = preg_match('~<tr data-select="' . $to . '"[^>]*>.*?</tr>~s', $html, $m);
            self::assertSame(1, $found, "no busiest row for $to");
            return $m[0];
        };
        // NAS: 1 MB/s = 8 Mb/s of 2.5 Gb/s = 0.32 %.
        $this->assertStringContainsString('>0.3%<', $row($busiest, self::NAS));
        // Internet: 100 Mb/s of the 2.5 Gb/s port = 4 % (a whole number prints bare).
        $this->assertStringContainsString('>4%<', $row($busiest, self::GW));
        // Flex 5: 30 kB/s = 0.24 Mb/s of 2.5 Gb/s = 0.0096 % — on the list, so not "0%".
        $this->assertStringContainsString('>&lt;0.1%<', $row($busiest, self::FLEX5));
        $this->assertDoesNotMatchRegularExpression('~>0%<~', $busiest);

        // From 10 % up, whole numbers: 20 Mb/s of 40 → "20%", 40 % of the WAN → "40%".
        $html = $this->render(self::map(null, null, null, null, 40.0));
        $this->assertStringContainsString('>20%<', $row(self::section($html, ''), self::GW));
    }

    /**
     * Map nodes are toggle buttons (re-activating the selected one returns to
     * the busiest view), so they carry aria-pressed — rendered false, flipped
     * by the shell JS. Strip rows and port cells move the selection; they are
     * not toggles and must not claim to be.
     */
    public function testOnlyMapNodesCarryAriaPressed(): void
    {
        $html = $this->render(self::map());

        $nodes = preg_match_all('~<g class="unifi-node(?: offline)?"[^>]*>~', $html, $m);
        $this->assertSame(6, $nodes);
        foreach ($m[0] as $tag) {
            $this->assertStringContainsString('role="button"', $tag);
            $this->assertStringContainsString('aria-pressed="false"', $tag);
        }
        $this->assertSame(6, substr_count($html, 'aria-pressed='), 'nothing but the six nodes');
    }

    public function testEveryDetailSectionHasABackButtonAndAFocusableHeading(): void
    {
        $map  = self::map();
        $html = $this->render($map);

        foreach (array_column($map['nodes'], 'id') as $id) {
            $section = self::section($html, $id);
            $this->assertMatchesRegularExpression(
                '~<button type="button" class="[^"]*" data-select="">.*?Back to busiest links\s*</button>~s', $section, $id);
            $this->assertSame(1, preg_match_all('~<h4 class="[^"]*" tabindex="-1" data-detail-heading>~', $section), $id);
        }
        // The busiest view is where "back" leads: a heading to land on, no button.
        $busiest = self::section($html, '');
        $this->assertStringContainsString('tabindex="-1" data-detail-heading>Busiest links</h4>', $busiest);
        $this->assertStringNotContainsString('<button', $busiest);

        // The heading carries the node's name — what focus lands on and reads.
        $this->assertMatchesRegularExpression('~data-detail-heading>\s*Unraid~', self::section($html, self::NAS));
    }

    /**
     * Phones: the strip's tables must fit without a sideways scroller — its
     * scrollLeft would snap back on every 10 s swap. Rate cells break between
     * ↓ and ↑ there, so each direction is its own unbreakable span and the
     * cell itself is not text-nowrap (a utility with !important would pin it).
     */
    public function testStripRateCellsCanBreakBetweenDirections(): void
    {
        $clients   = self::clients();
        $clients[] = ['mac' => '11:22:33:44:55:09', 'name' => 'Kitchen speaker', 'is_wired' => false,
                      'ap_mac' => 'aa:bb:cc:00:00:01', 'radio' => 'ng', 'tx_bytes-r' => 3_000];
        $html = $this->render(self::map(null, $clients));

        foreach (['', self::U7, self::GW] as $id) {
            $section = self::section($html, $id);
            $this->assertMatchesRegularExpression(
                '~<td class="text-end unifi-rates"><span class="unifi-rate">↓ [^<]+</span><span class="unifi-rate-sep"> · </span><span class="unifi-rate">↑ [^<]+</span></td>~u',
                $section, "section '$id'");
            $this->assertStringNotContainsString('text-nowrap">↓', $section);
        }

        // The SVG labels keep the plain text form: a <span> inside <text> would not render.
        $this->assertMatchesRegularExpression('~<text class="unifi-node-rate"[^>]*>↓ [^<]+ · ↑ [^<]+</text>~u',
            self::node($html, self::NAS));

        $shell = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/unifi/index.html.twig');
        $this->assertMatchesRegularExpression(
            '~@media \(max-width: 575\.98px\) \{[^}]*\.unifi-rates \{ white-space: normal; \}~', $shell);
    }

    /**
     * The polite live region that announces a selection must sit OUTSIDE the
     * fragment: the swap replaces the fragment every 10 s, and a live region
     * that is re-created is not reliably announced (nor would a pending
     * announcement survive).
     */
    public function testSelectionLiveRegionLivesInTheShellNotTheFragment(): void
    {
        $this->assertStringNotContainsString('aria-live', $this->render(self::map()));

        $shell = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/unifi/index.html.twig');
        $this->assertMatchesRegularExpression(
            '~<div class="visually-hidden" aria-live="polite" data-unifi-map-live></div>~', $shell);
        // A sibling of the swapped body, not inside it.
        $this->assertMatchesRegularExpression(
            '~<div data-dash-body>.*?</div>\s*</div>\s*{% if region.name == \'map\' %}\s*(?:\{#.*?#\}\s*)?<div class="visually-hidden" aria-live~s',
            $shell);
    }

    /**
     * The flow dots keep their phase across swaps: the shell JS sets
     * --p1/--p2/--p3 from the timeline using the same three durations the CSS
     * animates with. The two lists must agree, or the dots jump again.
     */
    public function testFlowPhaseDurationsMatchTheCss(): void
    {
        $shell = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/unifi/index.html.twig');

        preg_match_all('~\.unifi-flow\.speed-(\d) \{ --d: ([\d.]+)s; animation-delay: var\(--p(\d), 0s\); \}~',
            $shell, $css, PREG_SET_ORDER);
        $this->assertCount(3, $css);
        $this->assertSame(1, preg_match('~var FLOW_MS = \{ p1: (\d+), p2: (\d+), p3: (\d+) \};~', $shell, $js));

        foreach ($css as [, $speed, $seconds, $var]) {
            $this->assertSame($speed, $var, 'speed-N reads --pN');
            $this->assertSame((int) round((float) $seconds * 1000), (int) $js[(int) $speed], "speed-$speed");
        }
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

    /**
     * The fragment is re-rendered every 10 s and swapped in with innerHTML, so
     * a nested vertical scroller's scrollTop snaps back to 0 on every poll
     * (an AP with ~25 clients was unreadable past the cap). The lists must
     * grow with the page, whose own scroll survives the swap.
     */
    public function testStripClientListsAreNotNestedVerticalScrollers(): void
    {
        $clients   = self::clients();
        $clients[] = ['mac' => '11:22:33:44:55:09', 'name' => 'Kitchen speaker', 'is_wired' => false,
                      'ap_mac' => 'aa:bb:cc:00:00:01', 'radio' => 'ng', 'tx_bytes-r' => 3_000];
        $html = $this->render(self::map(null, $clients));

        foreach ([self::U7, self::GW] as $host) {
            $section = self::section($html, $host);
            $this->assertStringContainsString('<div class="table-responsive">', $section, "$host client list");
            $this->assertDoesNotMatchRegularExpression('~max-height|overflow-y~', $section);
        }

        // No strip rule in the shell's CSS caps a height or scrolls vertically.
        $shell = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/unifi/index.html.twig');
        preg_match_all('~([^{}]*\.unifi-strip[^{}]*)\{([^}]*)\}~', $shell, $rules, PREG_SET_ORDER);
        $this->assertNotEmpty($rules);
        foreach ($rules as [, $selector, $body]) {
            $this->assertDoesNotMatchRegularExpression('~max-height|overflow-y~', $body, trim($selector));
        }
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
