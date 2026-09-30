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

        yield 'jellyseerr: requester display name' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*\\(user\\.displayName\\s*\\|\\|/",
            'le nom affiché vient de Plex/Jellyfin et est choisi par le demandeur',
        ];

        yield 'jellyseerr: moderator display name' => [
            'jellyseerr/index.html.twig',
            "/modName\\s*=\\s*modifier\\.displayName\\s*\\|\\|/",
            'même source que le demandeur, rendu dans la session ROLE_ADMIN',
        ];

        yield 'jellyseerr: TMDb overview' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*tmdb\\.overview\\s*\\+/",
            'synopsis TMDb concaténé dans innerHTML',
        ];

        // Balayage du 2026-07-31 : les puits restants des trois templates
        // Jellyseerr, trouvés en relisant chaque affectation innerHTML.
        yield 'jellyseerr: TMDb title in the hero' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*titleText\\s*\\+/",
            'le titre TMDb est éditable par la communauté',
        ];

        yield 'jellyseerr: TMDb genres' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*tmdb\\.genres\\.join/",
            'les libellés de genre viennent de TMDb',
        ];

        yield 'jellyseerr: TMDb backdrop in a CSS url()' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*backdrop\\s*\\+/",
            "un ') referme la chaîne CSS : encoder, pas échapper",
        ];

        yield 'jellyseerr: TMDb poster src' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*poster\\s*\\+/",
            "le chemin d'affiche atterrit dans un src",
        ];

        yield 'jellyseerr: requester avatar in a CSS url()' => [
            'jellyseerr/index.html.twig',
            "/url\\(\\\\'\\{\\{ service_url \\}\\}/",
            "l'avatar du demandeur atterrit dans un url('…') CSS",
        ];

        yield 'jellyseerr: moderator avatar in a CSS url()' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*modAvatar\\s*\\+/",
            'même puits CSS que le demandeur',
        ];

        yield 'jellyseerr: edit modal requester name' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*currentUserName\\s*\\+/",
            'la modale d\'édition rend le même nom choisi par le demandeur',
        ];

        yield 'jellyseerr: edit modal requester avatar' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*currentUserAvatar\\s*\\+/",
            'même puits CSS que la modale de détail',
        ];

        yield 'jellyseerr: edit modal user list names' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*name\\s*\\+/",
            'la liste déroulante rend le nom de chaque compte Jellyseerr',
        ];

        yield 'jellyseerr: edit modal user list avatars' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*avatar\\s*\\+/",
            "l'avatar sert dans un url('…') CSS et dans data-avatar",
        ];

        yield 'jellyseerr: *arr quality profile name' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*p\\.name\\s*\\+/",
            'le nom de profil est relayé depuis Radarr/Sonarr',
        ];

        yield 'jellyseerr: *arr root folder path' => [
            'jellyseerr/index.html.twig',
            "/\\+\\s*rf\\.path\\s*\\+/",
            'le chemin racine est relayé depuis Radarr/Sonarr',
        ];

        yield 'jellyseerr users: upstream error message' => [
            'jellyseerr/users.html.twig',
            "/'\\s*\\+\\s*msg;/",
            'jsonClientError relaie le message renvoyé par Jellyseerr',
        ];

        yield 'jellyseerr users: Jellyfin import username' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*uName\\s*\\+/",
            "le nom de compte Jellyfin est choisi côté serveur média",
        ];

        yield 'jellyseerr users: Jellyfin import thumb' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*uThumb\\s*\\+/",
            "la vignette atterrit dans un url('…') CSS",
        ];

        yield 'jellyseerr users: Jellyfin import id' => [
            'jellyseerr/users.html.twig',
            "/js-jf-user-cb\" value=\"'\\s*\\+\\s*u\\.id\\s*\\+/",
            "l'identifiant vient du serveur média et atterrit dans un attribut",
        ];

        yield 'jellyseerr users: profile display name' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*name\\s*\\+/",
            'même nom choisi par le demandeur, rendu dans la modale profil',
        ];

        yield 'jellyseerr users: profile email' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*\\(?u\\.email\\s*(?:\\+|\\|\\|)/",
            "l'adresse e-mail est saisie par le demandeur",
        ];

        yield 'jellyseerr users: profile Jellyfin username' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*\\(u\\.jellyfinUsername\\s*\\|\\|/",
            'même source que le nom affiché',
        ];

        yield 'jellyseerr users: profile Jellyfin id' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*\\(u\\.jellyfinUserId\\s*\\|\\|/",
            "l'identifiant vient du serveur média",
        ];

        yield 'jellyseerr users: profile avatar in a CSS url()' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*avatar\\s*\\+/",
            "l'avatar atterrit dans un url('…') CSS",
        ];

        yield 'jellyseerr users: profile backdrop in a CSS url()' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*backdrop\\s*\\+/",
            'même puits CSS, alimenté par TMDb',
        ];

        yield 'jellyseerr users: request poster src' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*poster\\s*\\+/",
            "le chemin d'affiche atterrit dans un src",
        ];

        yield 'jellyseerr users: request title' => [
            'jellyseerr/users.html.twig',
            "/\\+\\s*title\\s*\\+/",
            'le titre TMDb est éditable par la communauté',
        ];

        yield 'jellyseerr user detail: upstream error message' => [
            'jellyseerr/user_detail.html.twig',
            "/\\+\\s*\\(msg\\s*\\|\\|/",
            'jsonClientError relaie le message renvoyé par Jellyseerr',
        ];

        yield 'calendar: month cell title' => [
            'calendrier/index.html.twig',
            "/data-ev-idx=\"' \\+ evIdx \\+ '\">' \\+ title \\+ line2/",
            'titres Radarr/Sonarr bruts dans la vue mois',
        ];

        yield 'calendar: month cell episode subtitle' => [
            'calendrier/index.html.twig',
            "/cal-block-line2\">' \\+ ev\\.title \\+ '/",
            'titre d\'épisode brut dans la vue mois',
        ];

        yield 'calendar: day row title' => [
            'calendrier/index.html.twig',
            "/cal-day-row-title\">' \\+ title \\+ '/",
            'titres bruts dans la vue jour',
        ];

        // Balayage du 2026-07-31 : les puits restants des quatre chemins de
        // rendu du calendrier, trouvés en relisant chaque affectation innerHTML.
        yield 'calendar: week cell title' => [
            'calendrier/index.html.twig',
            "/'\">' \\+ evTitle\\(ev\\) \\+ line2/",
            'mêmes titres Radarr/Sonarr dans la vue semaine',
        ];

        yield 'calendar: half-escaped aria-label ternary' => [
            'calendrier/index.html.twig',
            "/window\\.escHtml \\? window\\.escHtml\\(/",
            "n'échapper que l'aria-label laissait le corps du bloc brut",
        ];

        yield 'calendar: poster src' => [
            'calendrier/index.html.twig',
            "/\\+\\s*ev\\.poster\\s*\\+/",
            "l'URL d'affiche atterrit dans un src",
        ];

        yield 'calendar: day row studio' => [
            'calendrier/index.html.twig',
            "/\\+ ev\\.studio\\b/",
            'le studio est relayé par Radarr',
        ];

        yield 'calendar: day row episode subtitle' => [
            'calendrier/index.html.twig',
            "/sxe \\+ \\(ev\\.title \\? '[^']*' \\+ ev\\.title/",
            "le titre d'épisode est relayé par Sonarr",
        ];

        yield 'calendar: day row network' => [
            'calendrier/index.html.twig',
            "/\\+ ev\\.network\\b/",
            'la chaîne est relayée par Sonarr',
        ];

        yield 'calendar: day popup metas' => [
            'calendrier/index.html.twig',
            "/\\+ metas\\.join\\(/",
            'studio, chaîne et genres viennent de Radarr/Sonarr',
        ];

        yield 'calendar: day popup episode subtitle' => [
            'calendrier/index.html.twig',
            "/cal-day-popup-ev-sub[^\\n]*' \\+ ev\\.title \\+/",
            "même titre d'épisode dans le popup du jour",
        ];

        yield 'calendar: day popup title' => [
            'calendrier/index.html.twig',
            "/cal-day-popup-ev-title\">' \\+ title \\+/",
            'titres bruts dans le popup du jour',
        ];

        // Balayage du 2026-07-31 : le jumeau escHtml de decouverte/explorer.html.twig
        // n'avait pas reçu le même durcissement que decouverte/index.html.twig
        // (Tâche 2) — il échappe & < > via textContent/innerHTML mais pas le
        // guillemet double, alors que la fonction alimente une dizaine
        // d'attributs entre guillemets (aria-label, data-tmdb-id, data-name,
        // data-photo, src, ...) remplis de données TMDb éditables par la
        // communauté.
        yield 'explorer: unescaped escHtml helper (missing quote hardening)' => [
            'decouverte/explorer.html.twig',
            "/d\\.textContent = s; return d\\.innerHTML; \\}/",
            "le guillemet double n'est pas échappé alors que escHtml alimente des attributs entre guillemets",
        ];

        // Review 2026-08-28 #2: the Sonarr health-warnings banner spliced the
        // warning text raw into innerHTML while the films sibling uses esc(w).
        yield 'series: Sonarr health warnings banner' => [
            'media/series.html.twig',
            "/\\+\\s*w\\s*\\+/",
            'Sonarr healthWarnings text lands raw in the page banner innerHTML (films sibling uses esc(w))',
        ];

        // Wokeometer modal integration: the TL;DR, title and url are third-party
        // text. They only ever go through textContent / a guarded href; never
        // splice them into an HTML string — neither by concatenation (with or
        // without a trailing `+`, parenthesised, `+=`) nor by a template literal.
        foreach (['media/films.html.twig', 'media/series.html.twig', 'decouverte/index.html.twig'] as $tpl) {
            foreach (self::wokeometerSplicePatterns() as $label => $regex) {
                yield "wokeometer in $tpl: $label" => [
                    $tpl,
                    $regex,
                    'Wokeometer TL;DR / title / url are third-party text: textContent / guarded href only',
                ];
            }
        }

        // The settings card shows the provider's error text and refusal
        // reasons: textContent only, never spliced into markup.
        foreach (self::settingsSplicePatterns() as $label => $regex) {
            yield "wokeometer settings card: $label" => [
                'admin/settings.html.twig',
                $regex,
                'lastErrorMessage / reason carry provider-derived text: textContent only',
            ];
        }

        // Review 2026-08-28 #3: f02bc5f hardened esc() against attribute
        // breakout in prowlarr/index.html.twig only; these byte-identical
        // siblings interpolate esc() into value="…"/data-*="…" attributes, so
        // an unescaped quote truncates the field and Save writes the truncated
        // value back to Prowlarr.
        foreach (['apps', 'download_clients', 'notifications', '_schema_page'] as $tpl) {
            yield "prowlarr/$tpl: esc() without quote escaping" => [
                "prowlarr/$tpl.html.twig",
                "/function esc\\(s\\)[^\\n]*return d\\.innerHTML; \\}/",
                'esc() must escape double quotes — its output lands in double-quoted attributes',
            ];
        }
    }

    /**
     * Splices of Wokeometer third-party fields (`w.` / `match.` /
     * `wokeometer.` + tldr|title|url) into a string: `+ x.tldr` (a trailing
     * `+` is NOT required), `+ (x.tldr)`, `+= x.tldr`, and `${x.tldr}`.
     *
     * @return array<string, string>
     */
    public static function wokeometerSplicePatterns(): array
    {
        return [
            'concatenation' => '/\+=?\s*\(?\s*(?:w|match|wokeometer)\.(?:tldr|title|url)\b/',
            'template literal' => '/\$\{\s*\(?\s*(?:match|w|wokeometer)\.(?:tldr|title|url)\b/',
        ];
    }

    /** @return array<string, string> */
    public static function settingsSplicePatterns(): array
    {
        return [
            'lastErrorMessage / reason concatenation' => '/\+=?\s*\(?\s*[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*\.(?:lastErrorMessage|reason)\b/',
            'lastErrorMessage / reason template literal' => '/\$\{[^}]*\.(?:lastErrorMessage|reason)\b/',
        ];
    }

    /**
     * The sentinels must actually catch the splice shapes the reviews found
     * (each fixture line is a real XSS shape, none of them caught by the old
     * "+ x +" pattern that required a trailing `+`).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function wokeometerSpliceFixtures(): iterable
    {
        yield 'innerHTML tail concat'  => ['x.innerHTML = "<p>" + match.tldr;'];
        yield 'href concat'            => ['h += "<a href=\"" + w.url + "\">";'];
        yield 'parenthesised'          => ['"<p>" + (match.tldr)'];
        yield 'template literal'       => ['`${match.tldr}`'];
        yield 'both sides'             => ['"<p>" + match.tldr + "</p>"'];
        yield 'compound assignment'    => ['html += wokeometer.title;'];
    }

    #[DataProvider('wokeometerSpliceFixtures')]
    public function testWokeometerSentinelsCatchEverySpliceShape(string $line): void
    {
        $caught = array_filter(self::wokeometerSplicePatterns(), static fn (string $re): bool => preg_match($re, $line) === 1);
        self::assertNotEmpty($caught, 'not caught: ' . $line);
    }

    public function testWokeometerSentinelsLeaveTheSafeSinksAlone(): void
    {
        foreach ([
            'tldr.textContent = match.tldr;',
            "link.setAttribute('href', match.url);",
            "var hasLink = typeof w.url === 'string' && w.url.indexOf('https://wokeometer.app/') === 0;",
            'errEl.textContent = s.lastErrorMessage;',
            'say(I18N.reason[reason], true);',
        ] as $line) {
            foreach ([...self::wokeometerSplicePatterns(), ...self::settingsSplicePatterns()] as $label => $re) {
                self::assertDoesNotMatchRegularExpression($re, $line, "$label flags a safe sink: $line");
            }
        }
        foreach (['el.innerHTML = "<b>" + s.lastErrorMessage;', 'm.innerHTML = `${d.reason}`;', 'h += d.state.lastErrorMessage;'] as $bad) {
            $caught = array_filter(self::settingsSplicePatterns(), static fn (string $re): bool => preg_match($re, $bad) === 1);
            self::assertNotEmpty($caught, 'settings sentinel misses: ' . $bad);
        }
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
