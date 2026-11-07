<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Review 2026-11-06 §5 hygiene — the cheap template/JS fixes that have no
 * PHP seam: the settings Clear flag resets when the user types again
 * (AS-11), the series queue sizes honour the byte-unit preference (MH-3),
 * the series history overlay understands Sonarr v4's string enums (MT-11),
 * the films backdrop does not reset the background shorthand (MT-12), the
 * missing-films poll regex is permissive like films/series (MT-13), the
 * AssetMapper welcome log is gone (MH-12) and the Seerr confirm key points
 * at its real namespace (MH-5).
 */
class ReviewHygieneGuardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private static function src(string $rel): string
    {
        $s = file_get_contents(self::ROOT . $rel);
        self::assertNotFalse($s, $rel);
        return $s;
    }

    public function testClearFlagIsResetWhenTheUserTypesANewValue(): void
    {
        $s = self::src('templates/admin/settings.html.twig');
        $this->assertStringContainsString("flag.value = '0';", $s, 'Clear → type → Save must store the typed value, not the wipe');
    }

    public function testSeriesQueueSizesUseTheByteUnitPreference(): void
    {
        $s = self::src('templates/media/series.html.twig');
        $this->assertMatchesRegularExpression('/function fmtSize\(bytes\) \{\s*if \(window\.prismarrBytes\) return window\.prismarrBytes\(bytes\);/', $s);
    }

    public function testSeriesHistoryOverlayMapsSonarrV4StringEnums(): void
    {
        $s = self::src('templates/media/series.html.twig');
        $this->assertSame(2, substr_count($s, 'downloadFolderImported: {{'), 'both history overlays');
        $this->assertSame(2, substr_count($s, "downloadFolderImported:'primary'"));
    }

    public function testFilmsBackdropDoesNotResetTheBackgroundShorthand(): void
    {
        $s = self::src('templates/media/films.html.twig');
        $this->assertStringNotContainsString("backdrop.style.background = 'var(--tblr-bg-surface)';", $s);
        $this->assertStringContainsString("backdrop.style.backgroundColor = 'var(--tblr-bg-surface)';", $s);
    }

    public function testMissingFilmsPollRegexIsPermissive(): void
    {
        $s = self::src('templates/media/films_missing.html.twig');
        $this->assertStringNotContainsString('/(\d+) reports downloaded/', $s);
        $this->assertSame(2, substr_count($s, '/(\d+)\s+reports?\s+(?:downloaded|grabbed)/i'));
    }

    public function testAssetMapperWelcomeLogIsGone(): void
    {
        $this->assertStringNotContainsString('console.log', self::src('assets/app.js'));
    }

    public function testSeerrDeleteConfirmUsesTheCommonNamespace(): void
    {
        $s = self::src('templates/jellyseerr/user_detail.html.twig');
        $this->assertStringContainsString("'jellyseerr.common.confirm_delete_user_irreversible'|trans", $s);
        $this->assertStringNotContainsString("'jellyseerr.user_detail.confirm_delete_user_irreversible'", $s);
    }

    /**
     * Live check on :beta (2026-11-07): `d.floating|default(true)` floated
     * every quick-look chip because Twig's `default` filter treats `false` as
     * empty — a boolean that is deliberately false comes back as the default.
     * A boolean with a true default must be read with `?? true` (null
     * coalescing: undefined or null only).
     */
    public function testNoTemplateReadsABooleanThroughDefaultTrue(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!str_ends_with((string) $file, '.twig')) {
                continue;
            }
            $src = (string) file_get_contents((string) $file);
            if (preg_match_all('/[A-Za-z_.]+\\|default\\(true\\)/', $src, $m)) {
                $offenders[] = basename((string) $file) . ': ' . implode(', ', $m[0]);
            }
        }
        $this->assertSame([], $offenders, "`x|default(true)` turns a deliberate false into true — use `(x ?? true)`:\n" . implode("\n", $offenders));
    }

    /**
     * Live check on :beta (2026-11-07): Plex users' avatars are absolute
     * plex.tv URLs, and the Seerr pages prefixed the Seerr URL onto them.
     * Avatars go through seerr_avatar() (Twig) or seerrAvatar() (page JS),
     * which resolve Seerr paths and drop absolute URLs.
     */
    public function testSeerrPagesNeverConcatenateTheServiceUrlOntoAnAvatar(): void
    {
        $offenders = [];
        foreach (glob(self::ROOT . 'templates/jellyseerr/*.twig') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all("/\\{\\{\\s*service_url\\s*\\}\\}(?:'\\s*\\+|\\{\\{)|service_url\\s*~/", $src, $m)) {
                $offenders[] = basename($file) . ': ' . implode(', ', $m[0]);
            }
        }
        $this->assertSame([], $offenders, "use seerr_avatar(x, service_url) / seerrAvatar(x):\n" . implode("\n", $offenders));
    }

    /**
     * MT-11 (review 2026-11-06): `h.date.substring(0, 10)` on an ISO instant
     * is the UTC day — an evening grab west of UTC reads as tomorrow. Client
     * dates go through window._prismarrFmtDate(new Date(…)): the viewer's
     * zone and the Display date-format preference.
     */
    public function testNoTemplateSlicesTheUtcDayOutOfATimestamp(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!str_ends_with((string) $file, '.twig')) {
                continue;
            }
            // JS `.substring(0, 10)` and Twig `|slice(0,10)` alike (live check on
            // :beta: the Seerr pages' first render is Twig). A `data-*`
            // attribute is a sort key the page JS compares as text, not a
            // displayed date, so it may stay ISO.
            foreach (preg_split('/\R/', (string) file_get_contents((string) $file)) ?: [] as $n => $line) {
                if (preg_match('/\bdata-[a-z-]+="[^"]*(?:[Dd]ate|At)\s*\|\s*slice/', $line)) {
                    continue;
                }
                if (preg_match_all('/[A-Za-z_]*(?:[Dd]ate|At)\b\s*(?:\.\s*(?:substring|substr|slice)\(|\|\s*slice\()\s*0\s*,\s*10\s*\)/', $line, $m)) {
                    $offenders[] = basename((string) $file) . ':' . ($n + 1) . ': ' . implode(', ', $m[0]);
                }
            }
        }
        $this->assertSame([], $offenders, "a timestamp sliced to 10 chars is the UTC day — use `|prismarr_date` (Twig) or window._prismarrFmtDate(new Date(x)) (JS):\n" . implode("\n", $offenders));
    }
}
