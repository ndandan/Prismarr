<?php

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Renders the real quick-look body partial with a hand-built view-model and
 * pins the Wokeometer surface: badge + TL;DR block + link when data is
 * present, absolutely nothing when it is not, third-party text escaped.
 */
class QuickLookWokeometerRenderTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function ql(array $overrides = []): array
    {
        return array_merge([
            'title'        => 'The Matrix',
            'year'         => 1999,
            'poster'       => null,
            'backdrop'     => null,
            'overview'     => 'A hacker learns the truth.',
            'genres'       => ['Science Fiction'],
            'rating'       => 8.2,
            'metaLine'     => '136 min',
            'statusBadge'  => null,
            'actionUrl'    => '/films/radarr-1?open=42',
            'actionLabel'  => 'Manage',
            'inLibrary'    => true,
            'radarrId'     => null,
            'sonarrId'     => null,
            'airStatus'    => null,
            'releaseDates' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $ql
     */
    private function render(array $ql): string
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        return $twig->render('dashboard/_quicklook_body.html.twig', ['ql' => $ql]);
    }

    /** @return array<string, mixed> */
    private function woke(array $overrides = []): array
    {
        return array_merge([
            'score'     => 3,
            'tldr'      => 'A plain summary.',
            'url'       => 'https://wokeometer.app/media/movie/the-matrix',
            'analyzed'  => true,
            'title'     => 'The Matrix',
            'updatedAt' => 1_700_000_000,
        ], $overrides);
    }

    public function testBadgeBlockAndLinkRenderWithData(): void
    {
        $html = $this->render($this->ql(['wokeometer' => $this->woke()]));

        self::assertStringContainsString('ql-badge ql-woke', $html);
        self::assertStringContainsString('3/10', $html);
        self::assertStringContainsString('ql-woke-block', $html);
        self::assertStringContainsString('A plain summary.', $html);
        self::assertStringContainsString('ql-woke-attrib', $html);
        self::assertStringContainsString('href="https://wokeometer.app/media/movie/the-matrix"', $html);
        self::assertStringContainsString('↗', $html);
    }

    public function testExternalLinkOpensSafelyInANewTab(): void
    {
        $html = $this->render($this->ql(['wokeometer' => $this->woke()]));

        self::assertMatchesRegularExpression(
            '~<a href="https://wokeometer\.app/media/movie/the-matrix" target="_blank" rel="noopener" class="ql-action-secondary ql-woke-link">~',
            $html,
        );
    }

    public function testScoreZeroStillRendersTheBadge(): void
    {
        // 0/10 is a real score, not "unscored".
        $html = $this->render($this->ql(['wokeometer' => $this->woke(['score' => 0])]));

        self::assertStringContainsString('ql-badge ql-woke', $html);
        self::assertStringContainsString('0/10', $html);
    }

    public function testTldrIsEscaped(): void
    {
        $html = $this->render($this->ql(['wokeometer' => $this->woke(['tldr' => '<script>alert(1)</script> & co'])]));

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; co', $html);
    }

    public function testNullScoreOmitsTheBadgeButKeepsTheBlock(): void
    {
        $html = $this->render($this->ql(['wokeometer' => $this->woke(['score' => null])]));

        self::assertStringNotContainsString('ql-badge ql-woke', $html);
        self::assertStringContainsString('ql-woke-block', $html);
        self::assertStringContainsString('A plain summary.', $html);
    }

    public function testScoreAloneKeepsTheBlockWithoutTldrOrLink(): void
    {
        $html = $this->render($this->ql(['wokeometer' => $this->woke(['tldr' => null, 'url' => null])]));

        self::assertStringContainsString('ql-woke-block', $html);
        self::assertStringNotContainsString('ql-woke-tldr', $html);
        self::assertStringNotContainsString('ql-woke-link', $html);
        self::assertStringContainsString('ql-woke-attrib', $html);
    }

    public function testMissingScoreAndTldrRendersNothingEvenWithALink(): void
    {
        // Flipped from "omit only those parts": a bare link promises an
        // analysis that is not there (WokeometerLookup::view() returns null
        // for such rows; the template applies the same gate).
        $html = $this->render($this->ql(['wokeometer' => $this->woke(['score' => null, 'tldr' => null])]));

        self::assertStringNotContainsString('ql-woke', $html);
        self::assertStringNotContainsString('wokeometer.app', $html);
    }

    public function testHeaderBadgeIsSelfDescribing(): void
    {
        $html = $this->render($this->ql(['wokeometer' => $this->woke(['score' => 4])]));

        self::assertMatchesRegularExpression('~<span class="ql-badge ql-woke"[^>]*>Wokeometer 4/10</span>~', $html);
    }

    public function testNothingRendersWithoutData(): void
    {
        foreach ([$this->ql(), $this->ql(['wokeometer' => null])] as $ql) {
            $html = $this->render($ql);

            self::assertStringNotContainsString('ql-woke', $html);
            self::assertStringNotContainsString('wokeometer', strtolower($html));
            // The rest of the body is untouched.
            self::assertStringContainsString('The Matrix', $html);
            self::assertStringContainsString('A hacker learns the truth.', $html);
        }
    }
}
