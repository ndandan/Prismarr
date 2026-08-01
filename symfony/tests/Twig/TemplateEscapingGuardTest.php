<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test-sentinelle (même esprit que TransmissionRouteParityTest) : ces
 * templates injectent des données de services tiers dans innerHTML. Chaque
 * motif ci-dessous a été un XSS confirmé le 2026-07-31 ; le test échoue si
 * l'un d'eux réapparaît sans échappement.
 *
 * La CSP n'est PAS un filet de sécurité ici : script-src porte
 * 'unsafe-inline' (imposé par les ~96 templates à script inline), donc la
 * charge s'exécute. Voir la Phase 2 du plan de remédiation.
 */
class TemplateEscapingGuardTest extends TestCase
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../templates/';

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function forbiddenPatterns(): iterable
    {
        yield 'discover: TMDb alternative titles' => [
            'decouverte/index.html.twig',
            "/\\+\\s*t\\.title\\s*\\+/",
            'les titres alternatifs TMDb sont du contenu communautaire ouvert',
        ];

        yield 'discover: TMDb review excerpt' => [
            'decouverte/index.html.twig',
            "/\\+\\s*excerpt\\s*\\+/",
            'le corps des critiques TMDb est du contenu communautaire ouvert',
        ];

        // Balayage du 2026-07-31 : les puits restants du même fichier, trouvés
        // en relisant chaque affectation innerHTML de decouverte/index.html.twig.
        yield 'discover: TMDb alternative title country' => [
            'decouverte/index.html.twig',
            "/\\+\\s*t\\.country\\s*\\+/",
            'même charge utile communautaire que le titre alternatif',
        ];

        yield 'discover: TMDb review link' => [
            'decouverte/index.html.twig',
            "/\\+\\s*r\\.url\\s*\\+/",
            "l'URL de la critique atterrit dans un href",
        ];

        yield 'discover: TMDb info table values' => [
            'decouverte/index.html.twig',
            "/Array\\.isArray\\(value\\)\\s*\\?\\s*value\\.join/",
            "addRow() reçoit les noms d'équipe, pays et langues TMDb",
        ];

        yield 'discover: TMDb production companies' => [
            'decouverte/index.html.twig',
            "/\\+\\s*c\\.name\\s*\\+/",
            'les noms de société de production viennent de TMDb',
        ];

        yield 'discover: TMDb networks' => [
            'decouverte/index.html.twig',
            "/\\+\\s*n\\.name\\s*\\+/",
            'les noms de chaîne viennent de TMDb',
        ];

        yield 'discover: TMDb watch providers' => [
            'decouverte/index.html.twig',
            "/\\+\\s*\\(p\\.name\\s*\\|\\|/",
            'les noms de plateforme viennent de TMDb',
        ];

        yield 'discover: TMDb season name' => [
            'decouverte/index.html.twig',
            "/\\(\\s*s\\.name\\s*\\|\\|/",
            'les noms de saison sont éditables par la communauté TMDb',
        ];

        yield 'discover: half-escaped attributes (quotes only)' => [
            'decouverte/index.html.twig',
            "/\\((?:v\\.name|c\\.name|n\\.name|p\\.name|s\\.overview)\\s*\\|\\|\\s*''\\)\\.replace/",
            "n'échapper que les guillemets laisse passer < et > : utiliser escHtml",
        ];

        yield 'discover: TMDb video gallery key' => [
            'decouverte/index.html.twig',
            "/\\+\\s*v\\.key\\s*\\+/",
            'la clé vidéo atterrit dans un attribut data-',
        ];

        yield 'discover: YouTube embed key' => [
            'decouverte/index.html.twig',
            "/\\+\\s*key\\s*\\+/",
            "la clé vidéo atterrit dans le src de l'iframe",
        ];

        yield 'discover: TMDb imdb_id link' => [
            'decouverte/index.html.twig',
            "/\\+\\s*d\\.imdb_id\\s*\\+/",
            "l'identifiant externe atterrit dans un href",
        ];

        yield 'discover: TMDb homepage link' => [
            'decouverte/index.html.twig',
            "/\\+\\s*d\\.homepage\\s*\\+/",
            "le site officiel est une URL libre saisie sur TMDb",
        ];

        yield 'discover: JustWatch provider link' => [
            'decouverte/index.html.twig',
            "/\\+\\s*d\\.providers\\.link\\s*\\+/",
            "le lien JustWatch atterrit dans un href",
        ];

        yield 'discover: TMDb genre names' => [
            'decouverte/index.html.twig',
            "/\\+\\s*g\\.name\\s*\\+/",
            'les libellés de genre viennent de /decouverte/genres',
        ];

        yield 'discover: detail endpoint error message' => [
            'decouverte/index.html.twig',
            "/'__MSG__',\\s*d\\.error\\s*\\)/",
            "d.error est figé aujourd'hui, mais le puits doit rester échappé",
        ];
    }

    #[DataProvider('forbiddenPatterns')]
    public function testTemplateDoesNotSpliceUnescapedServiceData(
        string $template,
        string $regex,
        string $why,
    ): void {
        $path = self::TEMPLATE_ROOT . $template;
        self::assertFileExists($path);

        self::assertDoesNotMatchRegularExpression(
            $regex,
            file_get_contents($path),
            sprintf('concaténation non échappée dans %s (%s)', $template, $why),
        );
    }
}
