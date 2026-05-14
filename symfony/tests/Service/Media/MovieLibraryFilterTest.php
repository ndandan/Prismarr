<?php

namespace App\Tests\Service\Media;

use App\Service\Media\MovieLibraryFilter;
use App\Service\Media\MovieLibraryQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issue #19 — server-side pagination / filter / sort for films.
 *
 * Sanity-check the filter service against the same scenarios the legacy
 * client-side JS handled, plus pagination edge cases (page clamping,
 * unlimited mode for shelf view, empty library, facets stability).
 */
class MovieLibraryFilterTest extends TestCase
{
    private MovieLibraryFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new MovieLibraryFilter();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function movie(int $id, array $overrides = []): array
    {
        return array_replace([
            'id'         => $id,
            'title'      => "Movie $id",
            'sortTitle'  => "movie $id",
            'year'       => 2000,
            'added'      => '2024-01-01T00:00:00Z',
            'sizeOnDisk' => 0,
            'hasFile'    => false,
            'monitored'  => true,
            'status'     => 'released',
            'quality'    => null,
            'genres'     => [],
            'languages'  => [],
        ], $overrides);
    }

    private function ids(array $items): array
    {
        return array_map(fn(array $m) => $m['id'], $items);
    }

    public function testEmptyLibraryYieldsEmptyResult(): void
    {
        $result = $this->filter->apply([], new MovieLibraryQuery());

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->libraryTotal);
        $this->assertSame(1, $result->totalPages, 'totalPages clamps to 1 on empty so the paginator UI is renderable');
        $this->assertSame([], $result->genres);
        $this->assertSame([], $result->qualities);
        $this->assertSame([], $result->languages);
    }

    public function testStatusAllReturnsAllMovies(): void
    {
        $movies = [
            $this->movie(1, ['hasFile' => true]),
            $this->movie(2, ['hasFile' => false, 'monitored' => true]),
            $this->movie(3, ['monitored' => false]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(status: 'all'));

        $this->assertSame([1, 2, 3], $this->ids($result->items));
    }

    public function testStatusDownloadedOnlyKeepsHasFile(): void
    {
        $movies = [
            $this->movie(1, ['hasFile' => true]),
            $this->movie(2, ['hasFile' => false, 'monitored' => true]),
            $this->movie(3, ['hasFile' => false, 'monitored' => false]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(status: 'downloaded'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testStatusMissingRequiresMonitoredAndNoFile(): void
    {
        // Parity with films.html.twig JS: missing == !hasFile && monitored.
        // An unmonitored movie without file is NOT missing — it's unmonitored.
        $movies = [
            $this->movie(1, ['hasFile' => false, 'monitored' => true]),
            $this->movie(2, ['hasFile' => true,  'monitored' => true]),
            $this->movie(3, ['hasFile' => false, 'monitored' => false]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(status: 'missing'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testStatusUnmonitoredRejectsMonitored(): void
    {
        $movies = [
            $this->movie(1, ['monitored' => false]),
            $this->movie(2, ['monitored' => true]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(status: 'unmonitored'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testStatusMonitoredRejectsUnmonitored(): void
    {
        $movies = [
            $this->movie(1, ['monitored' => true]),
            $this->movie(2, ['monitored' => false]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(status: 'monitored'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testSearchIsCaseInsensitiveOnTitle(): void
    {
        $movies = [
            $this->movie(1, ['title' => 'The Matrix']),
            $this->movie(2, ['title' => 'Inception']),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(q: 'matrix'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testSearchMatchesSortTitleToo(): void
    {
        // Radarr's sortTitle strips leading articles ("The Matrix" → "matrix").
        // The search must hit either field so admins typing "matrix" find the
        // movie whose display title is "The Matrix".
        $movies = [
            $this->movie(1, ['title' => 'The Matrix', 'sortTitle' => 'matrix']),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(q: 'matrix'));

        $this->assertCount(1, $result->items);
    }

    public function testQualityFilterExactMatch(): void
    {
        $movies = [
            $this->movie(1, ['quality' => 'WEBDL-1080p']),
            $this->movie(2, ['quality' => 'Bluray-2160p']),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(quality: 'WEBDL-1080p'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testGenreFilterRequiresExactMembership(): void
    {
        $movies = [
            $this->movie(1, ['genres' => ['Action', 'Sci-Fi']]),
            $this->movie(2, ['genres' => ['Drama']]),
            $this->movie(3, ['genres' => ['Sci-Fi']]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(genre: 'Sci-Fi'));

        $this->assertSame([1, 3], $this->ids($result->items));
    }

    public function testLanguageFilterIsSubstring(): void
    {
        // Legacy parity: dataset.language was "French, English" joined and
        // the JS did `.indexOf(fLang) !== -1`. A user typing "rench" matched.
        $movies = [
            $this->movie(1, ['languages' => ['French', 'English']]),
            $this->movie(2, ['languages' => ['Japanese']]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(language: 'rench'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testCombinedFiltersIntersect(): void
    {
        $movies = [
            $this->movie(1, ['hasFile' => true, 'quality' => '1080p', 'genres' => ['Action']]),
            $this->movie(2, ['hasFile' => true, 'quality' => '720p',  'genres' => ['Action']]),
            $this->movie(3, ['hasFile' => true, 'quality' => '1080p', 'genres' => ['Drama']]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(
            status: 'downloaded',
            quality: '1080p',
            genre: 'Action',
        ));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testSortTitleAscNatcaseInsensitive(): void
    {
        // strnatcasecmp ensures "Movie 2" sorts before "Movie 10" (natural)
        // and isn't fooled by case.
        $movies = [
            $this->movie(10, ['sortTitle' => 'movie 10']),
            $this->movie(2,  ['sortTitle' => 'Movie 2']),
            $this->movie(1,  ['sortTitle' => 'Movie 1']),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(sort: 'title-asc'));

        $this->assertSame([1, 2, 10], $this->ids($result->items));
    }

    public function testSortTitleDescReverses(): void
    {
        $movies = [
            $this->movie(1, ['title' => 'aaa']),
            $this->movie(2, ['title' => 'bbb']),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(sort: 'title-desc'));

        $this->assertSame([2, 1], $this->ids($result->items));
    }

    public function testTitleSortKeepsLeadingArticles(): void
    {
        // Radarr's sortTitle can strip "The" (config-dependent), which would
        // put "The Batman" under B. Joshua prefers articles kept — we sort
        // on the raw title, so "The Batman" lives under T.
        $movies = [
            $this->movie(1, ['title' => 'Dans le noir',  'sortTitle' => 'dans le noir']),
            $this->movie(2, ['title' => 'The Batman',    'sortTitle' => 'batman']),
            $this->movie(3, ['title' => 'Apocalypse Now','sortTitle' => 'apocalypse now']),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(sort: 'title-asc'));

        $this->assertSame([3, 1, 2], $this->ids($result->items), '"The Batman" sorts under T even if sortTitle is "batman"');
    }

    public function testSortYearDesc(): void
    {
        $movies = [
            $this->movie(1, ['year' => 1999]),
            $this->movie(2, ['year' => 2023]),
            $this->movie(3, ['year' => 2010]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(sort: 'year-desc'));

        $this->assertSame([2, 3, 1], $this->ids($result->items));
    }

    public function testSortYearAsc(): void
    {
        $movies = [
            $this->movie(1, ['year' => 1999]),
            $this->movie(2, ['year' => 2023]),
            $this->movie(3, ['year' => 2010]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(sort: 'year-asc'));

        $this->assertSame([1, 3, 2], $this->ids($result->items));
    }

    public function testSortAddedDesc(): void
    {
        $movies = [
            $this->movie(1, ['added' => '2024-01-01T00:00:00Z']),
            $this->movie(2, ['added' => '2026-05-10T00:00:00Z']),
            $this->movie(3, ['added' => '2025-06-15T00:00:00Z']),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(sort: 'added-desc'));

        $this->assertSame([2, 3, 1], $this->ids($result->items));
    }

    public function testSortSizeDesc(): void
    {
        $movies = [
            $this->movie(1, ['sizeOnDisk' => 1_000_000_000]),
            $this->movie(2, ['sizeOnDisk' => 5_000_000_000]),
            $this->movie(3, ['sizeOnDisk' => 2_000_000_000]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(sort: 'size-desc'));

        $this->assertSame([2, 3, 1], $this->ids($result->items));
    }

    public function testPaginationFirstPage(): void
    {
        $movies = [];
        for ($i = 1; $i <= 5; $i++) {
            $movies[] = $this->movie($i, ['sortTitle' => sprintf('m%02d', $i)]);
        }

        $result = $this->filter->apply($movies, new MovieLibraryQuery(perPage: 2, page: 1));

        $this->assertSame([1, 2], $this->ids($result->items));
        $this->assertSame(5, $result->total);
        $this->assertSame(3, $result->totalPages);
        $this->assertSame(1, $result->page);
    }

    public function testPaginationLastPagePartial(): void
    {
        $movies = [];
        for ($i = 1; $i <= 5; $i++) {
            $movies[] = $this->movie($i, ['sortTitle' => sprintf('m%02d', $i)]);
        }

        $result = $this->filter->apply($movies, new MovieLibraryQuery(perPage: 2, page: 3));

        $this->assertSame([5], $this->ids($result->items));
        $this->assertSame(3, $result->page);
    }

    public function testPageBeyondBoundsClampsToLast(): void
    {
        // A user bookmarks ?page=42 then deletes most of their library; we
        // serve the last page rather than blank.
        $movies = [
            $this->movie(1),
            $this->movie(2),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(perPage: 50, page: 42));

        $this->assertCount(2, $result->items);
        $this->assertSame(1, $result->page);
        $this->assertSame(1, $result->totalPages);
    }

    public function testUnlimitedBypassesPagination(): void
    {
        // Shelf view re-fetches with unlimited=1 so the by-status shelves
        // see the full filtered set, not just the current page.
        $movies = [];
        for ($i = 1; $i <= 1000; $i++) {
            $movies[] = $this->movie($i, ['sortTitle' => sprintf('m%04d', $i)]);
        }

        $result = $this->filter->apply($movies, new MovieLibraryQuery(perPage: 50, page: 1, unlimited: true));

        $this->assertCount(1000, $result->items);
        $this->assertSame(1, $result->totalPages);
        $this->assertTrue($result->unlimited);
    }

    public function testFacetsAreComputedFromUnfilteredLibrary(): void
    {
        // A user filtering by genre=Action still sees Drama in the genre
        // dropdown so they can switch. Otherwise narrowing locks you in.
        $movies = [
            $this->movie(1, ['genres' => ['Action'], 'quality' => '1080p', 'languages' => ['French']]),
            $this->movie(2, ['genres' => ['Drama'],  'quality' => '720p',  'languages' => ['English']]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(genre: 'Action'));

        $this->assertSame(['Action', 'Drama'], $result->genres);
        // SORT_NATURAL puts numeric prefixes in numeric order: 720p < 1080p.
        $this->assertSame(['720p', '1080p'], $result->qualities);
        $this->assertSame(['English', 'French'], $result->languages);
        $this->assertSame([1], $this->ids($result->items));
    }

    public function testFacetsAreDedupedAndSortedNaturally(): void
    {
        $movies = [
            $this->movie(1, ['genres' => ['Action', 'Sci-Fi']]),
            $this->movie(2, ['genres' => ['action', 'Drama']]),
            $this->movie(3, ['genres' => ['Sci-Fi']]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery());

        // Distinct case-sensitive entries kept (Radarr can surface either
        // variant), order is case-insensitive natural sort.
        $this->assertSame(['Action', 'action', 'Drama', 'Sci-Fi'], $result->genres);
    }

    public function testFacetsIgnoreEmptyAndDashPlaceholders(): void
    {
        // RadarrClient::normalizeMovie() falls back to '—' when a language
        // name is missing — the filter dropdown should never show it.
        $movies = [
            $this->movie(1, ['languages' => ['French', '—', '']]),
            $this->movie(2, ['languages' => ['English']]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery());

        $this->assertSame(['English', 'French'], $result->languages);
    }

    public function testFromRequestSanitizesUnknownStatus(): void
    {
        $request = Request::create('/medias/radarr-1/films?status=eviltext&sort=hax&per_page=99');

        $query = MovieLibraryQuery::fromRequest($request);

        $this->assertSame('all', $query->status);
        $this->assertSame('title-asc', $query->sort);
        $this->assertSame(200, $query->perPage, 'per_page falls back to default when not in the allowlist');
    }

    public function testFromRequestHonorsLegacyFilterParam(): void
    {
        // v1.0 used ?filter=missing — keep bookmarks working.
        $request = Request::create('/?filter=missing');

        $query = MovieLibraryQuery::fromRequest($request);

        $this->assertSame('missing', $query->status);
    }

    public function testFromRequestPrefersStatusOverLegacyFilter(): void
    {
        // Both legacy and new params present → new wins so power users can
        // override a stale bookmark without manually stripping it.
        $request = Request::create('/?filter=missing&status=downloaded');

        $query = MovieLibraryQuery::fromRequest($request);

        $this->assertSame('downloaded', $query->status);
    }

    public function testFromRequestIgnoresLegacyFilterWhenInvalid(): void
    {
        $request = Request::create('/?filter=evilvalue');

        $query = MovieLibraryQuery::fromRequest($request);

        $this->assertSame('all', $query->status);
    }

    public function testFromRequestHonorsLegacyFilterAndPaginationTogether(): void
    {
        // A v1.0 bookmark could carry both ?filter= and ?page= — verify the
        // two are translated independently so the user lands on the right
        // page of the right filtered subset.
        $request = Request::create('/?filter=missing&page=2');

        $query = MovieLibraryQuery::fromRequest($request);

        $this->assertSame('missing', $query->status);
        $this->assertSame(2, $query->page);
    }

    public function testFromRequestClampsPageToOne(): void
    {
        $request = Request::create('/?page=-5');

        $query = MovieLibraryQuery::fromRequest($request);

        $this->assertSame(1, $query->page);
    }

    public function testFromRequestTrimsWhitespace(): void
    {
        $request = Request::create('/?q=%20%20matrix%20%20&genre=%20Action%20');

        $query = MovieLibraryQuery::fromRequest($request);

        $this->assertSame('matrix', $query->q);
        $this->assertSame('Action', $query->genre);
    }

    public function testFromRequestRespectsCustomDefaultPerPage(): void
    {
        $request = Request::create('/');

        $query = MovieLibraryQuery::fromRequest($request, 100);

        $this->assertSame(100, $query->perPage, 'a sane custom default (50/100/200/500) is honored');
    }

    public function testHasActiveFilterDetection(): void
    {
        $this->assertFalse((new MovieLibraryQuery())->hasActiveFilter());
        $this->assertFalse((new MovieLibraryQuery(q: 'matrix'))->hasActiveFilter(), 'a text search alone does not enable bulk-filtered actions');
        $this->assertTrue((new MovieLibraryQuery(status: 'missing'))->hasActiveFilter());
        $this->assertTrue((new MovieLibraryQuery(genre: 'Action'))->hasActiveFilter());
        $this->assertTrue((new MovieLibraryQuery(quality: '1080p'))->hasActiveFilter());
        $this->assertTrue((new MovieLibraryQuery(language: 'French'))->hasActiveFilter());
    }

    public function testWithoutPaginationKeepsFiltersButDropsPaging(): void
    {
        $query = new MovieLibraryQuery(
            q: 'matrix',
            status: 'missing',
            genre: 'Action',
            page: 5,
            perPage: 50,
        );

        $bulk = $query->withoutPagination();

        $this->assertSame('matrix', $bulk->q);
        $this->assertSame('missing', $bulk->status);
        $this->assertSame('Action', $bulk->genre);
        $this->assertTrue($bulk->unlimited);
    }

    public function testToQueryArrayStripsDefaultsForCleanUrls(): void
    {
        $defaults = new MovieLibraryQuery();
        $this->assertSame([], $defaults->toQueryArray(), 'an empty form produces a clean /medias/.../films url');

        $tweaked = new MovieLibraryQuery(q: 'matrix', status: 'missing', sort: 'year-desc', perPage: 100);
        $this->assertSame(
            ['q' => 'matrix', 'status' => 'missing', 'sort' => 'year-desc', 'per_page' => 100],
            $tweaked->toQueryArray()
        );
    }

    public function testLibraryTotalReflectsUnfilteredCount(): void
    {
        $movies = [
            $this->movie(1, ['hasFile' => true]),
            $this->movie(2, ['hasFile' => false, 'monitored' => true]),
            $this->movie(3, ['hasFile' => false, 'monitored' => true]),
        ];

        $result = $this->filter->apply($movies, new MovieLibraryQuery(status: 'downloaded'));

        $this->assertSame(1, $result->total, 'total counts the filtered scope');
        $this->assertSame(3, $result->libraryTotal, 'libraryTotal stays anchored to the full library');
    }
}
