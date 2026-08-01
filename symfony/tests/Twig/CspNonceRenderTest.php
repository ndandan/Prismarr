<?php

namespace App\Tests\Twig;

use App\Tests\AbstractWebTestCase;

/**
 * Le nonce doit atteindre les deux consommateurs critiques au boot :
 * la balise <meta> lue par Turbo et le script inline généré par
 * importmap(). Sans l'un ou l'autre, la page se charge mais tout le JS
 * meurt dès la première navigation Turbo.
 *
 * Deux couplages supplémentaires sont vérifiés ici, et seulement ici,
 * parce qu'ils ne se manifestent qu'à travers le conteneur réel :
 *  - l'en-tête Content-Security-Policy (la politique appliquée) doit
 *    annoncer exactement le nonce présent dans le HTML (une page dont le
 *    nonce ne correspond pas à sa propre politique n'exécute plus rien) ;
 *  - la valeur est stable dans une session et change d'une session à
 *    l'autre. La rotation par requête tuait Turbo Drive : le nonce est
 *    recopié tel quel dans le *corps* du script de chargement du polyfill
 *    es-module-shims, que Turbo ne peut pas neutraliser avant de comparer
 *    les signatures de <head>, donc chaque navigation devenait un reload.
 *  - une réponse JSON ne reçoit aucune CSP et ne démarre aucune session.
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

    public function testTheEnforcingHeaderAnnouncesTheNonceRenderedInThePage(): void
    {
        $this->client->request('GET', '/tableau-de-bord');
        $response = $this->client->getResponse();

        $nonce = $this->metaNonce($response->getContent());

        self::assertStringContainsString(
            "'nonce-" . $nonce . "'",
            (string) $response->headers->get('Content-Security-Policy'),
            'the governing policy and the page must carry the same nonce',
        );
    }

    public function testTheNonceIsStableWithinASessionAndRotatesForANewOne(): void
    {
        $this->client->request('GET', '/tableau-de-bord');
        $first = $this->metaNonce($this->client->getResponse()->getContent());

        $this->client->request('GET', '/tableau-de-bord');
        $second = $this->metaNonce($this->client->getResponse()->getContent());

        self::assertSame($second, $first, 'a rotating nonce reloads the page on every Turbo visit');

        // Client neuf = cookie de session neuf = session neuve.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/tableau-de-bord');
        $other = $this->metaNonce($this->client->getResponse()->getContent());

        self::assertNotSame($first, $other, 'a nonce shared by every session is a fixed public value');
    }

    public function testAnAnonymousJsonEndpointServesNoCspAndStartsNoSession(): void
    {
        // Le healthcheck Docker interroge /api/health toutes les 30 s, sans
        // cookie (`curl -fsS`). Le nonce vit dans un attribut de session et
        // le lire démarre la session : sans le filtrage HTML, chaque sondage
        // laisserait une session orpheline dans var/data/sessions (le seul
        // volume monté en production).
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/api/health');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->headers->all('set-cookie'), 'no session may be started');
        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    private function metaNonce(string $html): string
    {
        self::assertSame(
            1,
            preg_match('/<meta name="csp-nonce" content="([^"]+)">/', $html, $meta),
            'Turbo needs <meta name="csp-nonce">',
        );

        return $meta[1];
    }
}
