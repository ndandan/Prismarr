<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Test-sentinelle (même esprit que TemplateEscapingGuardTest) : sous la
 * politique stricte, un <script> inline sans nonce ne s'exécute tout
 * simplement pas — la page s'affiche, le JS meurt en silence, et la suite
 * reste verte puisque PHPUnit n'exécute aucun JavaScript. Ce test est donc
 * le seul garde-fou automatique.
 *
 * Les balises <script src="..."> sont exemptées : la source 'self'
 * conservée dans script-src les autorise déjà.
 */
class CspNonceGuardTest extends TestCase
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../templates/';

    /**
     * Occurrences de `<script` qui ne sont pas une vraie balise ouvrante
     * (chaînes JS construisant du HTML, exemples en commentaire). Chaque
     * entrée doit être justifiée par un commentaire.
     *
     * @var list<string>
     */
    private const ALLOW_LIST = [];

    public function testEveryInlineScriptCarriesTheNonce(): void
    {
        $offenders = [];

        foreach ($this->templateFiles() as $relative => $path) {
            $html = file_get_contents($path);
            preg_match_all('/<script\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$tag, $offset]) {
                if (preg_match('/\bsrc\s*=/i', $tag)) {
                    continue;
                }
                if (str_contains($tag, 'csp_nonce()')) {
                    continue;
                }
                $line = substr_count(substr($html, 0, $offset), "\n") + 1;
                $entry = $relative . ':' . $line;
                if (in_array($entry, self::ALLOW_LIST, true)) {
                    continue;
                }
                $offenders[] = $entry;
            }
        }

        self::assertSame([], $offenders, "balises <script> inline sans nonce=\"{{ csp_nonce() }}\"");
    }

    /**
     * Les attributs on*= ne peuvent pas porter de nonce : la politique
     * stricte les bloque sans exception. Ils doivent être convertis en
     * addEventListener.
     *
     * Le motif couvre TOUT attribut on<lettres>=, entre guillemets simples
     * ou doubles — pas une liste fermée d'événements. Une énumération
     * explicite (click|change|submit|...) a un angle mort démontré : elle a
     * laissé passer un onmouseout="..." (Tâche 2, trouvé à l'œil, pas par ce
     * test) juste à côté d'un onmouseover="..." qu'elle attrapait. Le même
     * trou existerait pour ondblclick, oncontextmenu, onpaste, ontoggle, etc.
     * et pour toute valeur entre guillemets simples.
     *
     * Volontairement sensible à la casse (pas de /i) : les attributs HTML
     * on*= réels sont toujours en minuscules dans ce dépôt (onclick,
     * onmouseover, ...). Un /i sur `[a-z]+` fait remonter un faux positif
     * vérifié sur l'arbre actuel — `var onBadge = '...'` dans
     * prowlarr/apps.html.twig:314, une variable JS, pas un attribut — car
     * `onBadge` matche `on[A-Za-z]+` juste avant le `= '`. Rester sensible à
     * la casse élimine ce faux positif sans rouvrir l'angle mort ci-dessus :
     * tout event handler HTML réel est en minuscules.
     */
    public function testNoInlineEventHandlerAttributes(): void
    {
        $offenders = [];
        $pattern = '/\son[a-z]+\s*=\s*["\']/';

        foreach ($this->templateFiles() as $relative => $path) {
            $html = file_get_contents($path);
            preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [, $offset]) {
                $offenders[] = $relative . ':' . (substr_count(substr($html, 0, $offset), "\n") + 1);
            }
        }

        self::assertSame([], $offenders, 'gestionnaires inline restants (bloqués par la CSP stricte)');
    }

    /**
     * @return iterable<string, string> chemin relatif => chemin absolu
     */
    private function templateFiles(): iterable
    {
        $root = realpath(self::TEMPLATE_ROOT);
        self::assertIsString($root);

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                yield str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)) => $file->getPathname();
            }
        }
    }
}
