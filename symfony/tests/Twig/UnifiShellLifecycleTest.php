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

    /**
     * A hung sparkline request left hit.busy = true for the whole page
     * session, so that client's sparkline never loaded again.
     */
    public function testSparklineFetchIsAbortedAfterATimeout(): void
    {
        $shell = self::read('index.html.twig');

        $this->assertMatchesRegularExpression(
            "~function loadSpark\(.*?new AbortController\(\).*?setTimeout\(function \(\) \{ ctl\.abort\(\); \}, SPARK_TIMEOUT\).*?signal: ctl\.signal~s",
            $shell);
        $this->assertMatchesRegularExpression('~var SPARK_TIMEOUT = \d+;~', $shell);
    }

    /**
     * nextDue advanced before the fetch with no in-flight guard, so a console
     * slower than the tick stacked /panels requests and an older answer could
     * land after a newer one.
     */
    public function testPollSkipsATickWhileARequestIsInFlight(): void
    {
        $shell = self::read('index.html.twig');

        $this->assertMatchesRegularExpression(
            "~function tick\(\) \{\s*if \(document\.hidden \|\| inFlight\) return;~", $shell);
        // ...and a hung request can't block every later tick: it has a deadline,
        // and the guard is released however the request ends.
        $this->assertMatchesRegularExpression(
            "~inFlight = true;.*?setTimeout\(function \(\) \{ poll\.abort\(\); \}, POLL_TIMEOUT\);\s*fetch\(PANELS_URL.*?signal: poll\.signal~s",
            $shell);
        $this->assertMatchesRegularExpression(
            "~\.catch\(function \(\) \{\}\)\s*\.then\(function \(\) \{ clearTimeout\(pollTimer\); inFlight = false; \}\);~", $shell);
    }

    /**
     * The "Updated Ns ago" chip read "—" until the first poll (hydration never
     * stamped it) and only repainted on the 10 s tick.
     */
    public function testUpdatedChipIsStampedOnHydrationAndRepaintedEverySecond(): void
    {
        $shell = self::read('index.html.twig');

        $this->assertMatchesRegularExpression(
            "~function hydrate\(node\) \{.*?applyFragment\(node, html\);\s*lastTick = Date\.now\(\);\s*paintUpdated\(\);~s",
            $shell);
        $this->assertStringContainsString(
            'window._prismarrUnifiChipTimer = setInterval(paintUpdated, 1000);', $shell);

        // Cleared on navigation by the page's own once-per-session handler, or
        // it keeps running after leaving the tab (base.html.twig's pinned list
        // only knows the poll timer).
        $this->assertMatchesRegularExpression(
            "~if \(!window\._prismarrUnifiLifecycleBound\) \{.*?document\.addEventListener\('turbo:before-render', function \(\) \{\s*"
            . "if \(window\._prismarrUnifiChipTimer\) \{ clearInterval\(window\._prismarrUnifiChipTimer\); window\._prismarrUnifiChipTimer = null; \}~s",
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
