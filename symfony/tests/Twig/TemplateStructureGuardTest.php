<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Structural sentinels for template regressions found in the 2026-08-28
 * review that no functional test can see (inline JS + Twig structure).
 */
class TemplateStructureGuardTest extends TestCase
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../templates/';

    public function testQueueWarningBannerIsNotInjectedIntoTheCollapsedBody(): void
    {
        $src = file_get_contents(self::TEMPLATE_ROOT . 'media/series.html.twig');
        $this->assertNotFalse($src);
        // #queue-body is server-rendered display:none; a banner inserted inside
        // it is invisible until the user manually expands the queue, so blocked
        // imports were never surfaced (review 2026-08-28 #5).
        $this->assertStringNotContainsString("#queue-body').insertBefore", $src);
    }

    public function testGlobalDelugePollBlockAppearsExactlyOnce(): void
    {
        $src = file_get_contents(self::TEMPLATE_ROOT . 'base.html.twig');
        $this->assertNotFalse($src);
        // A rebase applied the block twice (66be45d + 2b987ff): both copies
        // rendered and self-started, double-firing every page load and racing
        // on the same localStorage keys (review 2026-08-28 #6).
        $this->assertSame(1, substr_count($src, 'Global Deluge poll'));
    }

    public function testGlobalTransmissionPollBlockAppearsExactlyOnce(): void
    {
        $src = file_get_contents(self::TEMPLATE_ROOT . 'base.html.twig');
        $this->assertNotFalse($src);
        $this->assertSame(1, substr_count($src, 'Global Transmission poll'));
    }

    public function testFilmsAndSeriesGridsIncludeSubtitleBadge(): void
    {
        $films = file_get_contents(self::TEMPLATE_ROOT . 'media/films.html.twig');
        $series = file_get_contents(self::TEMPLATE_ROOT . 'media/series.html.twig');
        $this->assertNotFalse($films);
        $this->assertNotFalse($series);
        // Task 10 (Bazarr integration): every view mode of the Films/Series
        // grids should surface the shared subtitle-status pill, not just the
        // primary card face.
        $this->assertStringContainsString('_subtitle_badge.html.twig', $films);
        $this->assertStringContainsString('_subtitle_badge.html.twig', $series);
    }

    public function testBaseIncludesBazarrSearchModalForAdmins(): void
    {
        $base = file_get_contents(self::TEMPLATE_ROOT . 'base.html.twig');
        $this->assertNotFalse($base);
        // Task 11 (Bazarr integration): the shared subtitle-search modal must
        // be mounted once, admin-gated, so the movie/episode search triggers
        // (Tasks 6/9/10) have a modal to open.
        $this->assertStringContainsString('bazarr/_search_modal.html.twig', $base);
    }

    public function testQuicklookBodyIncludesSubtitleBadge(): void
    {
        $body = file_get_contents(self::TEMPLATE_ROOT . 'dashboard/_quicklook_body.html.twig');
        $this->assertNotFalse($body);
        // Task 12 (Bazarr integration): the quick-look modal should surface
        // the shared subtitle-status pill for in-library items, keyed off the
        // *arr id threaded through by the DashboardController quick-look
        // builders (radarrId for movies, sonarrId for series).
        $this->assertStringContainsString('_subtitle_badge.html.twig', $body);
        $this->assertStringContainsString('ql.radarrId', $body);
        $this->assertStringContainsString('ql.sonarrId', $body);
    }

    public function testSearchRenderItemRendersSubtitleBadge(): void
    {
        $base = file_get_contents(self::TEMPLATE_ROOT . 'base.html.twig');
        $this->assertNotFalse($base);
        // Task 13 (Bazarr integration): the global search dropdown renders
        // results client-side from a JSON payload, so the subtitle badge has
        // to be built in JS from `item.subtitle`, not via a Twig include.
        $this->assertStringContainsString('item.subtitle', $base);
        // The click-to-search affordance on a 'missing' badge is admin-only;
        // it must check the same is_granted('ROLE_ADMIN')-derived JS flag
        // the rest of the page uses, not invent a separate auth signal.
        $this->assertStringContainsString('window.PRISMARR_IS_ADMIN', $base);
        $this->assertStringContainsString("is_granted('ROLE_ADMIN') ? 'true' : 'false'", $base);
    }

    public function testSubtitleChipsPartialExists(): void
    {
        // Bazarr visual & detail pass, Task 1: shared per-language chip strip
        // consumed by Tasks 4/7. Its markup contract (present/missing lists,
        // poster-chip class names) is load-bearing for that later JS mirror.
        $this->assertFileExists(self::TEMPLATE_ROOT . 'media/_subtitle_chips.html.twig');
    }

    public function testFilmsAndSeriesGridsUsePosterChipBacking(): void
    {
        $films = file_get_contents(self::TEMPLATE_ROOT . 'media/films.html.twig');
        $series = file_get_contents(self::TEMPLATE_ROOT . 'media/series.html.twig');
        $this->assertNotFalse($films);
        $this->assertNotFalse($series);
        // Bazarr visual & detail pass, Task 1: the poster-overlaid subtitle
        // badge must carry the solid-backed .poster-chip treatment so it
        // doesn't blend into a same-colored poster; the flat table/list-row
        // badge deliberately does not.
        $this->assertStringContainsString('poster-chip', $films);
        $this->assertStringContainsString('poster-chip', $series);
    }

    public function testTwigCommentDelimitersAreBalanced(): void
    {
        // Bazarr visual & detail pass regression: the Task 4/5 film & series
        // detail-modal inline scripts each opened a JS block comment with `/*`
        // but closed it with `#}` (a Twig delimiter). lint:twig can't see it
        // (`/*` and a bare `#}` are literal text to Twig, no `{#` opener), and
        // no test executes the JS — so the stray `#}` swallowed the modal's
        // populate functions into the comment, breaking the whole <script> and
        // leaving the detail modal an empty shell. A well-formed template has
        // balanced Twig comment delimiters; an unmatched `#}` (the exact
        // signature of that bug) makes the counts diverge.
        $files = [
            'media/films.html.twig',
            'media/series.html.twig',
            'bazarr/_grid.html.twig',
            'bazarr/index.html.twig',
            'bazarr/history.html.twig',
            'bazarr/series_detail.html.twig',
            'media/_subtitle_chips.html.twig',
        ];
        foreach ($files as $relPath) {
            $src = file_get_contents(self::TEMPLATE_ROOT . $relPath);
            $this->assertNotFalse($src);
            $this->assertSame(
                substr_count($src, '{#'),
                substr_count($src, '#}'),
                $relPath . ': unbalanced Twig comment delimiters — a `#}` with no matching `{#` '
                    . '(often a JS `/* … */` comment mistakenly closed with `#}`).'
            );
        }
    }

    /**
     * The per-id Bazarr fallback is a SINGLE-ITEM affordance. Reachable from a
     * grid template it becomes 588 Bazarr calls per page render (spec defect
     * C1). Only the quick-look body may use it.
     */
    public function testTheSingleItemSubtitleLookupIsUsedOnlyByTheQuickLookBody(): void
    {
        $root = self::TEMPLATE_ROOT;

        $this->assertSame(
            1,
            substr_count((string) file_get_contents($root . 'dashboard/_quicklook_body.html.twig'), 'subtitle_status_single('),
            'the quick-look body renders exactly one badge and must use the per-id lookup',
        );

        foreach ([
            'media/films.html.twig',
            'media/series.html.twig',
            'media/_subtitle_badge.html.twig',
            'bazarr/index.html.twig',
            'bazarr/_shell.html.twig',
            'bazarr/_bare.html.twig',
            'bazarr/_grid.html.twig',
            'bazarr/history.html.twig',
            'bazarr/series_detail.html.twig',
        ] as $file) {
            $this->assertStringNotContainsString(
                'subtitle_status_single(',
                (string) file_get_contents($root . $file),
                $file . ' renders many badges — the per-id lookup would be an N+1 against Bazarr',
            );
        }
    }

    public function testTheGridTearsDownOnBothTheFrameAndTheDocumentEvent(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/bazarr/_grid.html.twig');

        // A frame swap fires neither turbo:before-render nor turbo:render, so
        // a document-only binding leaks the observer and the debounce timer
        // and accumulates one dead listener per view switch.
        $this->assertSame(1, substr_count($src, "addEventListener('turbo:before-frame-render', teardown)"));
        $this->assertSame(1, substr_count($src, "addEventListener('turbo:before-render', teardown)"));
        $this->assertSame(1, substr_count($src, "removeEventListener('turbo:before-frame-render', teardown)"));
        $this->assertSame(1, substr_count($src, "removeEventListener('turbo:before-render', teardown)"));
    }

    public function testNewBazarrTemplatesBalanceTwigComments(): void
    {
        // ced9170: a JS comment opened with slash-star and closed with `#}`
        // silently swallowed the rest of a <script> and shipped a dead modal.
        foreach ([
            'bazarr/_shell.html.twig', 'bazarr/_bare.html.twig', 'bazarr/_warming.html.twig', 'bazarr/_grid.html.twig',
            'bazarr/history.html.twig', 'bazarr/series_detail.html.twig',
        ] as $file) {
            $src = (string) file_get_contents(__DIR__ . '/../../templates/' . $file);
            $this->assertSame(
                substr_count($src, '{#'),
                substr_count($src, '#}'),
                $file . ': unbalanced Twig comment delimiters',
            );
        }
    }

    /**
     * Fix round 1, CRITICAL 1. Every link INSIDE #bazarr-view targets the
     * frame by default (that is what the frame element does); a link to a
     * page that is not one of the frame's own views must escape with
     * data-turbo-frame="_top", or Turbo tries to satisfy the frame-scoped
     * fetch by finding id="bazarr-view" in that OTHER page's response, fails,
     * and replaces the current tab with "Content missing". The series-detail
     * drill-down is exactly such a page.
     */
    public function testGridSeriesCardLinksEscapeToTheTopLevel(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/bazarr/_grid.html.twig');

        $this->assertStringContainsString(
            "el.setAttribute('data-turbo-frame', '_top')",
            $src,
            '_grid.html.twig: the series-card <a> (built in buildCard(), linking to /bazarr/series/{id}) must escape the frame',
        );
    }

    /**
     * Fix round 1, CRITICAL 2. error/_service_banner.html.twig is rendered
     * inside #bazarr-view by bazarr/_bare.html.twig's error branch; its CTA
     * links to the settings page, which is not one of the frame's views.
     */
    public function testServiceBannerCtaEscapesToTheTopLevel(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/error/_service_banner.html.twig');

        $this->assertMatchesRegularExpression(
            '/<a href="\{\{ path\(_target_route\) \}\}" data-turbo-frame="_top"/',
            $src,
            '_service_banner.html.twig: the CTA anchor must carry data-turbo-frame="_top"',
        );
    }

    /**
     * Fix round 1, IMPORTANT 4 + CRITICAL 1 audit. The landing page's "View
     * movies"/"View series" buttons stay inside the frame's own view set, so
     * they target the frame + advance history like the pill nav; the
     * series-detail link in the same file is NOT one of the frame's views,
     * so it must escape instead.
     */
    public function testLandingPageLinksAreCorrectlyFrameScoped(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/bazarr/index.html.twig');

        $this->assertSame(
            2,
            substr_count($src, 'data-turbo-frame="bazarr-view" data-turbo-action="advance"'),
            'index.html.twig: "View movies" and "View series" must both target the frame and advance history',
        );
        $this->assertStringContainsString(
            'data-bazarr-nav data-turbo-frame="_top"',
            $src,
            'index.html.twig: the series-detail link must escape the frame',
        );
    }

    /**
     * Fix round 1, CRITICAL 3. The server always re-renders the warming
     * markup with no memory of a prior retry (there is nothing to read it
     * back from — the cache is still cold), so the "already retried once"
     * flag cannot be server state; it has to live out-of-band, keyed by
     * path. And frame.reload() is a no-op on a direct hit (the shell ships
     * the frame with no `src`), so the reload must go through
     * Turbo.visit(url, {frame}) instead, which assigns `src` either way.
     *
     * Final-review fix-wave: the out-of-band marker's PRIMARY store is now
     * sessionStorage, not a bare `window` property — `window.location.
     * reload()` (the fallback reload path, taken when Turbo.visit isn't
     * available) is a full document navigation that wipes any plain
     * `window` property, so a window-only flag would forget it had already
     * retried on every such reload and loop forever against a Bazarr that
     * never comes back. `window` is kept only as the fallback for when
     * sessionStorage itself throws (private browsing / disabled storage).
     */
    public function testWarmingReloadsViaTurboVisitWithAnOutOfBandRetryMarker(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/bazarr/_warming.html.twig');

        $this->assertStringContainsString(
            "window.Turbo.visit(path, { frame: 'bazarr-view' })",
            $src,
            '_warming.html.twig: reload must go through Turbo.visit(), not frame.reload() (a no-op with no src)',
        );
        $this->assertStringContainsString(
            'sessionStorage',
            $src,
            '_warming.html.twig: the one-shot auto-retry marker must survive the window.location.reload() fallback path, so it must live in sessionStorage, not only on `window`',
        );
        $this->assertStringContainsString(
            "'prismarr:bazarr-warm-retried:' + path",
            $src,
            '_warming.html.twig: the sessionStorage marker must be keyed by path, same as the window fallback',
        );
        $this->assertStringContainsString(
            'window.__bzWarmRetried',
            $src,
            '_warming.html.twig: a window fallback must remain for when sessionStorage throws (private browsing / disabled storage)',
        );
        $this->assertStringNotContainsString(
            'data-retried',
            $src,
            '_warming.html.twig: the dead data-retried attribute (written but never read) must be removed',
        );
    }

    /**
     * Fix round 1, MINOR 6. Same leak class as _grid.html.twig's teardown
     * (testTheGridTearsDownOnBothTheFrameAndTheDocumentEvent above): a frame
     * swap fires no document-level turbo:before-render, so a document-only
     * binding would leave the 4 s timer armed after the view it belongs to
     * is gone.
     */
    public function testWarmingTearsDownOnBothTheFrameAndTheDocumentEvent(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/bazarr/_warming.html.twig');

        $this->assertSame(1, substr_count($src, "addEventListener('turbo:before-frame-render', teardown)"));
        $this->assertSame(1, substr_count($src, "addEventListener('turbo:before-render', teardown)"));
        $this->assertSame(1, substr_count($src, "removeEventListener('turbo:before-frame-render', teardown)"));
        $this->assertSame(1, substr_count($src, "removeEventListener('turbo:before-render', teardown)"));
    }
}
