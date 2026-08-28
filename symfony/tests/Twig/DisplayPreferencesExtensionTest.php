<?php

namespace App\Tests\Twig;

use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use App\Service\ThemeService;
use App\Twig\DisplayPreferencesExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Focused on the `prismarr_bytes` / `prismarr_speed` Twig filters added in
 * v1.0.6 to fix issue #4 ("International Units GB not Go"). The date/time
 * filters defer to DisplayPreferencesService and are covered there.
 */
#[AllowMockObjectsWithoutExpectations]
class DisplayPreferencesExtensionTest extends TestCase
{
    private function makeExtension(string $locale): DisplayPreferencesExtension
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn($locale);

        $stack = $this->createMock(RequestStack::class);
        $stack->method('getCurrentRequest')->willReturn($request);

        $prefs = $this->createMock(DisplayPreferencesService::class);

        return new DisplayPreferencesExtension($prefs, $stack);
    }

    /**
     * Critical: in EN we want GB / MB / KB / B (consumer convention used
     * by qBittorrent, Sonarr, Radarr) — NOT GiB / MiB which are technically
     * correct for 1024-based but never seen in user-facing UIs.
     *
     * @param int|float $bytes
     */
    #[DataProvider('englishBytesProvider')]
    public function testFilterBytesEnglish(mixed $bytes, string $expected): void
    {
        $ext = $this->makeExtension('en');
        $this->assertSame($expected, $ext->filterBytes($bytes));
    }

    public static function englishBytesProvider(): array
    {
        return [
            'small bytes'    => [42,                                  '42 B'],
            'kilobyte'       => [2048,                                '2.0 KB'],
            'megabyte'       => [5 * 1048576,                         '5.0 MB'],
            'gigabyte'       => [3 * 1073741824,                      '3.0 GB'],
            'terabyte'       => [2 * 1099511627776,                   '2.0 TB'],
            'fractional GB'  => [(int)(1.5 * 1073741824),             '1.5 GB'],
        ];
    }

    /**
     * In FR the legacy convention is "octets" — Go / Mo / Ko / o. The user
     * who opened issue #4 specifically wanted EN to render GB/MB instead of
     * carrying the FR abbreviations everywhere.
     *
     * @param int|float $bytes
     */
    #[DataProvider('frenchBytesProvider')]
    public function testFilterBytesFrench(mixed $bytes, string $expected): void
    {
        $ext = $this->makeExtension('fr');
        $this->assertSame($expected, $ext->filterBytes($bytes));
    }

    public static function frenchBytesProvider(): array
    {
        return [
            'small bytes'    => [42,                       '42 o'],
            'kilooctet'      => [2048,                     '2.0 Ko'],
            'mégaoctet'      => [5 * 1048576,              '5.0 Mo'],
            'gigaoctet'      => [3 * 1073741824,           '3.0 Go'],
            'téraoctet'      => [2 * 1099511627776,        '2.0 To'],
        ];
    }

    public function testFilterBytesNullAndEmpty(): void
    {
        $ext = $this->makeExtension('en');
        $this->assertSame('—', $ext->filterBytes(null));
        $this->assertSame('—', $ext->filterBytes(''));
        $this->assertSame('—', $ext->filterBytes('not-a-number'));
    }

    public function testFilterSpeedAppendsPerSecond(): void
    {
        $ext = $this->makeExtension('en');
        $this->assertSame('5.0 MB/s', $ext->filterSpeed(5 * 1048576));

        $extFr = $this->makeExtension('fr');
        $this->assertSame('1.5 Mo/s', $extFr->filterSpeed((int)(1.5 * 1048576)));
    }

    public function testGetByteUnitsExposesLocaleTable(): void
    {
        // The unit table is published to JS via the `prismarr_byte_units`
        // Twig function so window.prismarrBytes() can match server output.
        $en = $this->makeExtension('en')->getByteUnits();
        $this->assertSame('GB', $en['GB']);
        $this->assertSame('MB', $en['MB']);
        $this->assertSame('B',  $en['B']);

        $fr = $this->makeExtension('fr')->getByteUnits();
        $this->assertSame('Go', $fr['GB']);
        $this->assertSame('Mo', $fr['MB']);
        $this->assertSame('o',  $fr['B']);
    }

    public function testFilterBytesPrecisionParameter(): void
    {
        $ext = $this->makeExtension('en');
        $this->assertSame('1.50 MB',  $ext->filterBytes((int)(1.5 * 1048576), 2));
        $this->assertSame('1 MB',     $ext->filterBytes(1 * 1048576, 0));
    }

    public function testFilterBytesHandlesZero(): void
    {
        $ext = $this->makeExtension('en');
        $this->assertSame('0 B',  $ext->filterBytes(0));

        $extFr = $this->makeExtension('fr');
        $this->assertSame('0 o',  $extFr->filterBytes(0));
    }

    public function testLocaleFallbackToEnglishWhenNoRequest(): void
    {
        // Worker context with no active request — should still produce EN
        // labels rather than crashing.
        $stack = $this->createMock(RequestStack::class);
        $stack->method('getCurrentRequest')->willReturn(null);
        $prefs = $this->createMock(DisplayPreferencesService::class);

        $ext = new DisplayPreferencesExtension($prefs, $stack);
        $this->assertSame('1.0 GB', $ext->filterBytes(1073741824));
    }

    /**
     * The date/time filters render the em-dash placeholder for a null or
     * unparseable value — the same "no value" convention filterBytes()/
     * filterSpeed() use — so an unguarded `{{ x|prismarr_date }}` shows "—"
     * instead of an empty cell. Needs a real prefs service (not the mock the
     * byte tests use) so the formatters actually run.
     *
     * @param array<string, string|null> $stored
     */
    private function makeExtensionWithRealPrefs(array $stored = []): DisplayPreferencesExtension
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $key) => $stored[$key] ?? null);
        $prefs = new DisplayPreferencesService($config, new ThemeService($config));

        return new DisplayPreferencesExtension($prefs, new RequestStack());
    }

    public function testDateFiltersRenderEmDashForNull(): void
    {
        $ext = $this->makeExtensionWithRealPrefs();

        $this->assertSame('—', $ext->filterDate(null));
        $this->assertSame('—', $ext->filterTime(null));
        $this->assertSame('—', $ext->filterDateTime(null));
    }

    public function testDateFiltersRenderEmDashForEmptyOrUnparseable(): void
    {
        $ext = $this->makeExtensionWithRealPrefs();

        $this->assertSame('—', $ext->filterDate(''));
        $this->assertSame('—', $ext->filterDateTime('not-a-date'));
    }

    public function testDateFiltersStillFormatValidValues(): void
    {
        $ext = $this->makeExtensionWithRealPrefs([
            'display_date_format' => 'iso',
            'display_time_format' => '24h',
            'display_timezone'    => 'Europe/Paris',
        ]);
        $dt = new \DateTimeImmutable('2026-04-21 14:30:00', new \DateTimeZone('Europe/Paris'));

        $this->assertSame('2026-04-21', $ext->filterDate($dt));
        $this->assertSame('2026-04-21 · 14:30', $ext->filterDateTime($dt));
    }
}
