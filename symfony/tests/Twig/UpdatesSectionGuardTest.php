<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Garde-fou pour la section Updates fork-aware : le fork (ndandan/Prismarr)
 * est la cible principale partout dans #section-updates ; l'amont (Shoshuo)
 * ne doit apparaître que dans le bloc informatif dédié ; et chaque clé de
 * traduction utilisée par la section existe dans les DEUX locales (CI ne
 * rend aucun template admin, donc une clé manquante partirait en silence
 * sinon).
 */
class UpdatesSectionGuardTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../templates/admin/settings.html.twig';

    private function updatesSection(): string
    {
        $html  = (string) file_get_contents(self::TEMPLATE);
        $start = strpos($html, 'id="section-updates"');
        $end   = strpos($html, 'id="section-about"');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function testUpdatesSectionTargetsTheFork(): void
    {
        $section = $this->updatesSection();
        self::assertStringContainsString('github.com/ndandan/Prismarr', $section);
        self::assertStringNotContainsString('hub.docker.com', $section);
    }

    public function testShoshuoAppearsOnlyInsideTheUpstreamBlock(): void
    {
        $section     = $this->updatesSection();
        $upstreamPos = strpos($section, 'admin.updates.upstream_title');
        self::assertNotFalse($upstreamPos, 'the upstream block marker must exist');

        $pos = 0;
        while (($pos = strpos($section, 'Shoshuo', $pos)) !== false) {
            self::assertGreaterThan($upstreamPos, $pos, 'Shoshuo link found before the upstream block');
            $pos++;
        }
    }

    public function testEveryNewTranslationKeyExistsInBothLocales(): void
    {
        $keys = [
            'behind', 'up_to_date', 'no_remote_info', 'comparison_unavailable', 'fork_commits',
            'ghcr_package', 'fork_issues', 'upgrade_hint_html', 'whats_new', 'recent_commits',
            'notes_unavailable', 'upstream_title', 'upstream_last_release',
            'upstream_releases', 'roadmap',
        ];
        foreach (['en', 'fr'] as $locale) {
            $catalog = Yaml::parseFile(__DIR__ . '/../../translations/messages+intl-icu.' . $locale . '.yaml');
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog['admin']['updates'] ?? [], "admin.updates.$key missing in $locale");
            }
            self::assertArrayHasKey('fork_source', $catalog['admin']['about']['links'] ?? [], "admin.about.links.fork_source missing in $locale");
            self::assertArrayHasKey('fork_bug', $catalog['admin']['about']['links'] ?? [], "admin.about.links.fork_bug missing in $locale");
            self::assertArrayHasKey('upstream_label', $catalog['admin']['about']['links'] ?? [], "admin.about.links.upstream_label missing in $locale");
        }
    }
}
