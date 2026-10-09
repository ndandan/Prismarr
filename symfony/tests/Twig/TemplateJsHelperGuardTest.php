<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Source sentinels (no JS harness exists) for the shared inline-JS helpers:
 * one escCssUrl, a null-safe escape in the admin modals, and a client date
 * formatter that mirrors the PHP formats exactly instead of leaning on
 * hardcoded locale data.
 */
class TemplateJsHelperGuardTest extends TestCase
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../templates/';

    private function template(string $path): string
    {
        $src = file_get_contents(self::TEMPLATE_ROOT . $path);
        $this->assertNotFalse($src, $path);

        return $src;
    }

    public function testBaseDefinesTheSharedEscCssUrlNextToEscHtml(): void
    {
        $base = $this->template('base.html.twig');

        $this->assertStringContainsString('window.escCssUrl = function', $base);
        $this->assertLessThan(
            strpos($base, 'window.escCssUrl = function'),
            strpos($base, 'window.escHtml = function'),
            'escCssUrl sits right after escHtml',
        );
    }

    public function testTheSharedEscCssUrlEncodesTheCssStringTerminators(): void
    {
        $base  = $this->template('base.html.twig');
        $start = strpos($base, 'window.escCssUrl = function');
        $this->assertNotFalse($start);
        $body = substr($base, $start, 500);

        $this->assertStringContainsString('encodeURI', $body);
        $this->assertStringContainsString('%27', $body);
        $this->assertStringContainsString('%28', $body);
        $this->assertStringContainsString('%29', $body);
        $this->assertStringContainsString('== null', $body, 'null/undefined must become an empty string');
    }

    /** @return iterable<string, array{string}> */
    public static function jellyseerrTemplates(): iterable
    {
        yield 'index' => ['jellyseerr/index.html.twig'];
        yield 'users' => ['jellyseerr/users.html.twig'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jellyseerrTemplates')]
    public function testJellyseerrPagesReuseTheSharedEscCssUrl(string $path): void
    {
        $src = $this->template($path);

        $this->assertStringNotContainsString('function escCssUrl', $src, 'duplicate local copy');
        $this->assertStringContainsString('window.escCssUrl', $src);
    }

    public function testInstanceModalEscapeIsNullSafe(): void
    {
        $src   = $this->template('admin/_instance_modals.html.twig');
        $start = strpos($src, 'function esc(s)');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 400);

        // String(null) is the literal "null": a missing profile name must
        // render as nothing, not as the word "null".
        $this->assertMatchesRegularExpression('/s\s*(==|===)\s*null|s\s*==\s*undefined|window\.escHtml/', $body);
    }

    public function testClientDateFormatterDoesNotHardcodeTheFrenchLocale(): void
    {
        $base  = $this->template('base.html.twig');
        $start = strpos($base, 'window._prismarrFmtDate = function');
        $end   = strpos($base, 'window._prismarrFmtDateTime = function');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $region = substr($base, $start, $end - $start);

        // The PHP side formats 'fr' as d/m/Y and 24h as H:i by pattern, never
        // by locale; the client must build the same strings itself so an
        // unknown/blank preference lands on that same default.
        $this->assertStringNotContainsString("'fr-FR'", $region);
    }
}
