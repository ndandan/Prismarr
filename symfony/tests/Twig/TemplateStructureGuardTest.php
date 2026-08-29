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
}
