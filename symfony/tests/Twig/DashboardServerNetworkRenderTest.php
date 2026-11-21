<?php

namespace App\Tests\Twig;

use App\Tests\AbstractWebTestCase;

/**
 * The dashboard's Unraid section shows live throughput of the box's main
 * interface (Unraid API metrics.network rxSec / txSec, bytes per second),
 * formatted in bit/s like the UniFi WAN tile; no tile without the group.
 */
class DashboardServerNetworkRenderTest extends AbstractWebTestCase
{
    private function render(?array $network): string
    {
        return static::getContainer()->get('twig')->render('dashboard/_server.html.twig', ['server' => [
            'array' => null, 'system' => null, 'docker' => null, 'ups' => null, 'parity' => null,
            'network' => $network,
        ]]);
    }

    public function testShowsInterfaceAndBothRatesInBits(): void
    {
        // 8 000 B/s = 64 kbit/s; 92 500 B/s = 740 kbit/s; 1.5 MB/s = 12 Mbit/s.
        $html = $this->render(['interface' => 'br0', 'rx' => 8000.0, 'tx' => 1500000.0]);

        self::assertStringContainsString('server-net', $html);
        self::assertStringContainsString('br0', $html);
        self::assertStringContainsString('64 kbit/s', $html);
        self::assertStringContainsString('12 Mbit/s', $html);
        self::assertStringContainsString('>Network<', $html);
    }

    public function testNoTileWithoutTheGroup(): void
    {
        self::assertStringNotContainsString('server-net', $this->render(null));
    }

    public function testAnOverviewWithoutTheNetworkKeyStillRenders(): void
    {
        // A cached overview from before this change has no `network` key.
        $html = static::getContainer()->get('twig')->render('dashboard/_server.html.twig', ['server' => [
            'array' => null, 'system' => null, 'docker' => null, 'ups' => null, 'parity' => null,
        ]]);

        self::assertStringNotContainsString('server-net', $html);
    }
}
