<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Turbo lifecycle contract of the UniFi tab's shell script
 * (templates/unifi/index.html.twig). The script re-runs on every Turbo
 * visit, and its regions share the dashboard's data-dash-* contract, so
 * three things must hold or the two pages interfere with each other:
 *
 *  - its retry buttons are its own: the dashboard binds a once-per-session
 *    document click on [data-dash-retry], so a UniFi button carrying that
 *    attribute ran BOTH handlers (two fetches, the wrong page's renderer);
 *  - its document listeners are bound once per session, not once per visit;
 *  - a Back restore re-hydrates regions that were still loading when the
 *    snapshot was taken, instead of leaving their spinner up until the next
 *    poll (up to 300 s for history).
 */
class UnifiShellLifecycleTest extends TestCase
{
    private const DIR = __DIR__ . '/../../templates/unifi/';

    private static function read(string $file): string
    {
        return (string) file_get_contents(self::DIR . $file);
    }

    public function testRetryButtonsDoNotUseTheDashboardsAttribute(): void
    {
        foreach (['index.html.twig', '_infra.html.twig', '_live.html.twig', '_map.html.twig'] as $file) {
            $this->assertStringNotContainsString('data-dash-retry', self::read($file), $file);
        }
        foreach (['_infra.html.twig', '_live.html.twig', '_map.html.twig'] as $file) {
            $this->assertStringContainsString('<button type="button" class="btn btn-primary" data-unifi-retry>', self::read($file), $file);
        }
        $this->assertStringContainsString("e.target.closest('[data-unifi-retry]')", self::read('index.html.twig'));
    }

    public function testRegionsAreMarkedAsUnifiAndOnlyThoseAreHydrated(): void
    {
        $shell = self::read('index.html.twig');

        $this->assertMatchesRegularExpression('~<div class="mb-3"\s+data-unifi-region\s+data-dash-widget=~', $shell);
        // Scoped, so a turbo:load on the dashboard never reaches its widgets.
        $this->assertStringContainsString("document.querySelectorAll('[data-unifi-region][data-dash-url]')", $shell);
        $this->assertStringNotContainsString("document.querySelectorAll('[data-dash-widget][data-dash-url]')", $shell);
    }

    public function testDocumentListenersAreBoundOncePerSession(): void
    {
        $shell = self::read('index.html.twig');

        // No bare per-run registration of the hydrator.
        $this->assertStringNotContainsString("document.addEventListener('turbo:load', hydrateAll)", $shell);
        $this->assertStringContainsString('window._prismarrUnifiHydrateAll = hydrateAll;', $shell);
        $this->assertMatchesRegularExpression(
            "~if \(!window\._prismarrUnifiLifecycleBound\) \{\s*window\._prismarrUnifiLifecycleBound = true;"
            . ".*?document\.addEventListener\('turbo:load'.*?document\.addEventListener\('turbo:before-cache'~s",
            $shell);
    }

    public function testSnapshotForgetsWhichRegionsWereLoaded(): void
    {
        $shell = self::read('index.html.twig');

        $this->assertMatchesRegularExpression(
            "~document\.addEventListener\('turbo:before-cache', function \(\) \{.*?"
            . "querySelectorAll\('\[data-unifi-region\]'\).*?delete [a-z]+\[i\]\.dataset\.dashLoaded~s",
            $shell);
    }
}
