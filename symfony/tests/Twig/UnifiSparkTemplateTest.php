<?php

namespace App\Tests\Twig;

use App\Dashboard\NetworkUsageChart;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The sparkline fragment needs no translator or kernel (no copy, only geometry),
 * so it renders against a bare Twig environment — enough to pin the contract the
 * route and the map JS rely on: null → an EMPTY body, data → an inline-SVG
 * fragment with no <script>/<style>.
 */
class UnifiSparkTemplateTest extends TestCase
{
    private function render(?array $spark): string
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
            ['strict_variables' => true, 'autoescape' => 'html'],
        );
        return $twig->render('unifi/_spark.html.twig', ['spark' => $spark]);
    }

    private function spark(): array
    {
        return NetworkUsageChart::build([
            ['ts' => 1751799300, 'downBytes' => 2.0e7, 'upBytes' => 1.0e6],
            ['ts' => 1751799600, 'downBytes' => 9.0e7, 'upBytes' => 4.0e6],
            ['ts' => 1751799900, 'downBytes' => 5.0e7, 'upBytes' => 2.0e6],
        ], 'H:i');
    }

    public function testNullRendersAnEmptyBody(): void
    {
        // Exactly '' — a stray newline would defeat the route's "empty = no data".
        $this->assertSame('', $this->render(null));
    }

    public function testRendersTheChartGeometryAtSparklineHeight(): void
    {
        $spark = $this->spark();
        $html  = $this->render($spark);

        $this->assertStringContainsString('viewBox="0 0 600 120"', $html);
        $this->assertStringContainsString('style="height:48px"', $html);
        $this->assertStringContainsString('class="unifi-chart-fill" points="' . $spark['downArea'] . '"', $html);
        $this->assertStringContainsString('points="' . $spark['downLine'] . '"', $html);
        $this->assertStringContainsString('points="' . $spark['upLine'] . '"', $html);
        $this->assertSame(count($spark['points']), substr_count($html, '<rect '));
        $this->assertStringContainsString('↓ ' . $spark['downTotal'], $html);
        $this->assertStringContainsString('↑ ' . $spark['upTotal'], $html);
    }

    public function testNoScriptOrStyleTagInFragment(): void
    {
        $html = $this->render($this->spark());

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<style', $html);
    }
}
