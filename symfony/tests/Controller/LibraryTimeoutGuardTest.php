<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source sentinel (same spirit as TemplateEscapingGuardTest): these call
 * sites need the FULL library to answer correctly — an import scan that
 * times out at the short default reports every folder as new (duplicate
 * re-import), and the stats/rename/resolver paths render an empty library
 * as authoritative. Each must pass LIBRARY_TIMEOUT (review 2026-08-28,
 * finding 1: the issue-#41 fix made the 30s budget opt-in and these six
 * sites silently regressed to 4s/8s).
 */
class LibraryTimeoutGuardTest extends TestCase
{
    private const SRC_ROOT = __DIR__ . '/../../src/';

    /** @return iterable<string, array{0: string, 1: string, 2: int}> */
    public static function requiredTimeouts(): iterable
    {
        yield 'SonarrController full-library calls' => [
            'Controller/SonarrController.php',
            '/->getSeries\(SonarrClient::LIBRARY_TIMEOUT\)/',
            2, // import scan + stats
        ];
        yield 'RadarrController full-library calls' => [
            'Controller/RadarrController.php',
            '/->getMovies\(RadarrClient::LIBRARY_TIMEOUT\)/',
            3, // import scan + rename + stats
        ];
        yield 'TorrentResolverService series sweep' => [
            'Service/Media/TorrentResolverService.php',
            '/->getRawAllSeries\(SonarrClient::LIBRARY_TIMEOUT\)/',
            1,
        ];
        yield 'BazarrPosterResolver library calls' => [
            'Service/Media/BazarrPosterResolver.php',
            '/->getMovies\(RadarrClient::LIBRARY_TIMEOUT\)|->getSeries\(SonarrClient::LIBRARY_TIMEOUT\)/',
            2, // moviePosters + seriesPosters
        ];
        yield 'MediaLibraryRefresher library calls' => [
            'Service/Cache/MediaLibraryRefresher.php',
            '/->getMovies\(RadarrClient::LIBRARY_TIMEOUT\)|->getSeries\(SonarrClient::LIBRARY_TIMEOUT\)/',
            2,
        ];
    }

    #[DataProvider('requiredTimeouts')]
    public function testFullLibraryCallSitesPassLibraryTimeout(string $file, string $pattern, int $expected): void
    {
        $src = file_get_contents(self::SRC_ROOT . $file);
        $this->assertNotFalse($src);
        $this->assertSame(
            $expected,
            preg_match_all($pattern, $src),
            "$file must pass LIBRARY_TIMEOUT at its full-library call sites — the bare default times out on large libraries and returns [] indistinguishable from an empty library."
        );
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function forbiddenBareCalls(): iterable
    {
        yield 'SonarrController bare getSeries()' => ['Controller/SonarrController.php', '/->getSeries\(\)/'];
        yield 'RadarrController bare getMovies()' => ['Controller/RadarrController.php', '/->getMovies\(\)/'];
        yield 'TorrentResolverService bare getRawAllSeries()' => ['Service/Media/TorrentResolverService.php', '/->getRawAllSeries\(\)/'];
        yield 'BazarrPosterResolver bare library calls' => ['Service/Media/BazarrPosterResolver.php', '/->getMovies\(\)|->getSeries\(\)/'];
        yield 'MediaLibraryRefresher bare library calls' => ['Service/Cache/MediaLibraryRefresher.php', '/->getMovies\(\)|->getSeries\(\)/'];
    }

    #[DataProvider('forbiddenBareCalls')]
    public function testNoBareFullLibraryCallsRemain(string $file, string $pattern): void
    {
        $src = file_get_contents(self::SRC_ROOT . $file);
        $this->assertNotFalse($src);
        $this->assertDoesNotMatchRegularExpression($pattern, $src);
    }
}
