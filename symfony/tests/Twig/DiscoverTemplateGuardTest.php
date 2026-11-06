<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Guards for the Discover page script: Turbo teardown of document listeners,
 * translated watchlist label, and CSS-url escaping of the search backdrop.
 */
final class DiscoverTemplateGuardTest extends TestCase
{
    private function src(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../templates/decouverte/index.html.twig');
    }

    public function testNoHardcodedWatchlistLabel(): void
    {
        self::assertStringNotContainsString('Dans la watchlist', $this->src());
        self::assertStringContainsString('TMDB_I18N.in_watchlist : TMDB_I18N.watchlist', $this->src());
    }

    public function testSearchBackdropUsesEscCssUrl(): void
    {
        $src = $this->src();
        self::assertStringContainsString('window.escCssUrl(b.backdrop)', $src);
        self::assertDoesNotMatchRegularExpression('/url\(\\\'\'\s*\+\s*encodeURI/', $src);
    }

    public function testDocumentListenersAreRemovedOnTurboNavigation(): void
    {
        $src = $this->src();
        self::assertStringContainsString('window.registerPageLifecycle(', $src);
        self::assertStringContainsString('document.removeEventListener(boundDocTypes[slot], window[slot])', $src);
        self::assertStringContainsString('delete window[slot]', $src);
        self::assertStringContainsString('if (!srchInput.isConnected) return;', $src);
    }

    public function testDetailFetchValidatesTypeAndId(): void
    {
        // DD-15(1): the pair also comes from ?detail=<type>/<id>; a crafted
        // `..\../logout` turned into an authenticated GET /logout.
        $src = $this->src();
        self::assertMatchesRegularExpression(
            '/function loadDetail\(type, id\)\s*\{\s*(?:\/\/[^\n]*\s*)*if \(!\/\^\(movie\|tv\)\$\/\.test\(String\(type\)\) \|\| !\/\^\\\\d\{1,12\}\$\/\.test\(String\(id\)\)\) return;/',
            $src,
            'loadDetail must validate type (movie|tv) and a numeric id before building the URL'
        );
    }
}
