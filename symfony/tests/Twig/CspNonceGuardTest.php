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
    private const ASSETS_ROOT = __DIR__ . '/../../assets/';

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
                // Negative lookbehind, not \b: `\b` sits between `-` and `s`,
                // so a plain word-boundary version of this regex would also
                // exempt a future `<script data-src="...">` from the nonce
                // requirement — the attribute isn't `src`, it just ends in
                // it. `(?<![\w-])` refuses to match when the character right
                // before `src` is a letter, digit, underscore or hyphen.
                if (preg_match('/(?<![\w-])src\s*=/i', $tag)) {
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
     * Insensible à la casse (/i) — les attributs HTML sont insensibles à la
     * casse : onClick="..." ou onMouseOut="..." sont du HTML valide, honoré
     * par le navigateur et bloqué par la CSP stricte comme n'importe quel
     * on*= en minuscules. Une première version avait retiré le /i pour
     * éliminer un faux positif (`var onBadge = '...'` dans
     * prowlarr/apps.html.twig:314, une variable JS) mais ce raisonnement —
     * « ce dépôt écrit tout en minuscules aujourd'hui » — est exactement ce
     * qui avait rendu l'énumération aveugle à onmouseout au départ.
     *
     * Le vrai discriminant n'est pas la casse mais l'espace autour du `=` :
     * un attribut HTML s'écrit on*="..." (aucun espace), alors que
     * `var onBadge = '...'` a un espace de chaque côté. D'où `on[a-z]+=["']`
     * sans `\s*` autour du `=` : plus aucun faux positif sur `onBadge`, et
     * onClick=/onMouseOut= restent détectés.
     *
     * Angle mort suivant, trouvé en revue (pas par ce test) : la classe de
     * caractères `["']` après le `=` exige un guillemet, alors que le HTML
     * autorise une valeur d'attribut sans guillemets — `onclick=alert(1)`
     * s'exécute très bien dans un navigateur. `["'\w]` couvre aussi ce cas
     * (une valeur non guillemetée commence par un caractère de mot) sans
     * réintroduire le faux positif `onBadge` : le discriminant reste
     * l'absence d'espace autour du `=`, donc `onBadge = '...'` (espaces des
     * deux côtés) ne matche toujours pas.
     */
    public function testNoInlineEventHandlerAttributes(): void
    {
        $offenders = [];
        $pattern = '/\son[a-z]+=["\'\w]/i';

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
     * AssetMapper transforme un `import '....css'` en un module JS stub
     * `data:application/javascript,...` (vendor/symfony/asset-mapper/ImportMap/
     * ImportMapRenderer.php, constantes LOADER_CSS/LOADER_JSON et le
     * `data:application/javascript,` vide du cas préchargé). Sous la politique
     * stricte `script-src 'self' 'nonce-...'` (sans `data:`), le navigateur
     * refuse ce module — et cet échec fait échouer TOUT le graphe de modules
     * qui l'importe : Turbo, Stimulus (donc csrf_protection_controller.js et
     * son cookie CSRF sans état), Alpine et Chart.js ne démarrent plus
     * (`window.Turbo === undefined`, vérifié en direct).
     *
     * `assets/vendor/` est exclu : c'est du code tiers vendored par
     * AssetMapper, pas du code que nous écrivons, et il n'importe pas de CSS
     * aujourd'hui de toute façon.
     *
     * La feuille de style correspondante doit être liée directement par un
     * <link> dans un template (voir base.html.twig, juste après les feuilles
     * Tabler, et le test ci-dessous).
     */
    public function testNoJavaScriptModuleImportsCss(): void
    {
        $offenders = [];

        foreach ($this->assetJsFiles() as $relative => $path) {
            $js = file_get_contents($path);
            preg_match_all('/^\s*import\b[^;\n]*[\'"][^\'"]*\.css[\'"]/mi', $js, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [, $offset]) {
                $line = substr_count(substr($js, 0, $offset), "\n") + 1;
                $offenders[] = $relative . ':' . $line;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "import ... '*.css' dans un module JS — bloqué par le script-src strict, voir docblock",
        );
    }

    /**
     * Le lien direct est le remplacement du `import './styles/app.css'`
     * retiré de assets/app.js (test ci-dessus) : sans lui, styles/app.css ne
     * serait plus jamais chargé nulle part — disparu silencieusement de la
     * page, puisque rien ne signale l'absence d'un <link> qu'on a oublié
     * d'ajouter.
     */
    public function testBaseTemplateStillLinksTheAppStylesheet(): void
    {
        $html = file_get_contents(self::TEMPLATE_ROOT . 'base.html.twig');
        self::assertIsString($html);

        self::assertStringContainsString(
            "asset('styles/app.css')",
            $html,
            'base.html.twig doit lier styles/app.css directement (plus via import JS)',
        );
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

    /**
     * @return iterable<string, string> chemin relatif (depuis assets/) => chemin absolu
     */
    private function assetJsFiles(): iterable
    {
        $root = realpath(self::ASSETS_ROOT);
        self::assertIsString($root);

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.js')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_starts_with($relative, 'vendor/')) {
                continue;
            }

            yield $relative => $file->getPathname();
        }
    }
}
