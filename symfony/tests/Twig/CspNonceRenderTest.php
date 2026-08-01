<?php

namespace App\Tests\Twig;

use App\Tests\AbstractWebTestCase;

/**
 * Le nonce doit atteindre les deux consommateurs critiques au boot :
 * la balise <meta> lue par Turbo et le script inline généré par
 * importmap(). Sans l'un ou l'autre, la page se charge mais tout le JS
 * meurt dès la première navigation Turbo.
 */
class CspNonceRenderTest extends AbstractWebTestCase
{
    public function testRenderedPageCarriesTheTurboMetaAndImportmapNonce(): void
    {
        $this->client->request('GET', '/tableau-de-bord');
        $html = $this->client->getResponse()->getContent();

        preg_match('/<meta name="csp-nonce" content="([^"]+)">/', $html, $meta);
        self::assertNotEmpty($meta, 'Turbo needs <meta name="csp-nonce">');

        self::assertStringContainsString(
            'nonce="' . $meta[1] . '"',
            $html,
            'importmap() must emit its bootstrap script with the same nonce',
        );
    }
}
