<?php

namespace App\Tests\Twig;

use App\Tests\AbstractWebTestCase;

/**
 * The dashboard's Unraid Docker tile marks containers whose image has an
 * update waiting (Unraid's own isUpdateAvailable) and counts them in the
 * tile header; nothing extra when there are none or on an older API.
 */
class DashboardServerDockerUpdatesRenderTest extends AbstractWebTestCase
{
    /** @param array<string, mixed> $docker */
    private function render(array $docker): string
    {
        return static::getContainer()->get('twig')->render('dashboard/_server.html.twig', ['server' => [
            'array' => null, 'system' => null, 'docker' => $docker, 'ups' => null, 'parity' => null,
        ]]);
    }

    /** @return list<string> the chip elements, one per container */
    private function chips(string $html): array
    {
        preg_match_all('#<span class="server-chip-[^"]*"[^>]*>.*?</span>\s*(?=<span class="server-chip-|</div>)#s', $html, $m);
        return $m[0];
    }

    public function testMarksContainersWithAnUpdateAndCountsThem(): void
    {
        $html = $this->render([
            'running' => 3, 'total' => 3, 'stopped' => [], 'updates' => 2,
            'containers' => [
                ['name' => 'plex',   'running' => true, 'update' => true],
                ['name' => 'radarr', 'running' => true, 'update' => false],
                ['name' => 'sonarr', 'running' => true, 'update' => true],
            ],
        ]);

        self::assertStringContainsString('2 updates', $html);
        $chips = $this->chips($html);
        self::assertCount(3, $chips);
        self::assertStringContainsString('server-chip-update', $chips[0]);
        self::assertStringContainsString('title="Update available"', $chips[0]);
        self::assertStringNotContainsString('server-chip-update', $chips[1]);
        self::assertStringContainsString('server-chip-update', $chips[2]);
    }

    public function testASingleUpdateIsSingular(): void
    {
        $html = $this->render([
            'running' => 1, 'total' => 1, 'stopped' => [], 'updates' => 1,
            'containers' => [['name' => 'plex', 'running' => true, 'update' => true]],
        ]);

        self::assertStringContainsString('1 update<', $html);
    }

    public function testNoUpdatesShowsNoCountOrMarker(): void
    {
        $html = $this->render([
            'running' => 1, 'total' => 1, 'stopped' => [], 'updates' => 0,
            'containers' => [['name' => 'plex', 'running' => true, 'update' => false]],
        ]);

        self::assertStringNotContainsString('server-chip-update', $html);
        self::assertStringNotContainsString('update', strtolower(strip_tags($html)));
    }

    public function testOldGroupShapeStillRenders(): void
    {
        // A cached overview from before this change has no updates/update
        // keys; strict_variables must not trip.
        $html = $this->render([
            'running' => 1, 'total' => 1, 'stopped' => [],
            'containers' => [['name' => 'plex', 'running' => true]],
        ]);

        self::assertStringContainsString('plex', $html);
        self::assertStringNotContainsString('server-chip-update', $html);
    }
}
