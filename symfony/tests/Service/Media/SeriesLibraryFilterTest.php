<?php

namespace App\Tests\Service\Media;

use App\Service\Media\SeriesLibraryFilter;
use App\Service\Media\SeriesLibraryQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issue #19 — server-side filter / sort / pagination for the series library.
 *
 * Mirrors MovieLibraryFilterTest but exercises Sonarr-specific semantics:
 * the 7 status filters (continuing/ended/anime/missing-episodes/...), the
 * network facet, and the absence of quality/language filters.
 */
class SeriesLibraryFilterTest extends TestCase
{
    private SeriesLibraryFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new SeriesLibraryFilter();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function series(int $id, array $overrides = []): array
    {
        return array_replace([
            'id'             => $id,
            'title'          => "Series $id",
            'sortTitle'      => "series $id",
            'year'           => 2010,
            'status'         => 'continuing',
            'monitored'      => true,
            'seriesType'     => 'standard',
            'episodeCount'   => 10,
            'episodeFileCount' => 10,
            'percent'        => 100,
            'sizeOnDisk'     => 0,
            'sizeGb'         => 0,
            'network'        => null,
            'genres'         => [],
            'addedAt'        => new \DateTimeImmutable('2024-01-01T00:00:00Z'),
        ], $overrides);
    }

    private function ids(array $items): array
    {
        return array_map(fn(array $s) => $s['id'], $items);
    }

    public function testEmptyLibraryYieldsEmptyResult(): void
    {
        $result = $this->filter->apply([], new SeriesLibraryQuery());

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->libraryTotal);
        $this->assertSame(1, $result->totalPages);
        $this->assertSame([], $result->genres);
        $this->assertSame([], $result->networks);
    }

    public function testStatusContinuingMatchesUpstreamStatus(): void
    {
        $series = [
            $this->series(1, ['status' => 'continuing']),
            $this->series(2, ['status' => 'ended']),
            $this->series(3, ['status' => 'upcoming']),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(status: 'continuing'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testStatusEndedMatchesUpstreamStatus(): void
    {
        $series = [
            $this->series(1, ['status' => 'continuing']),
            $this->series(2, ['status' => 'ended']),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(status: 'ended'));

        $this->assertSame([2], $this->ids($result->items));
    }

    public function testStatusAnimeMatchesSeriesType(): void
    {
        // Sonarr stores anime in a separate seriesType field — the status
        // filter intersects that field, not the upstream status.
        $series = [
            $this->series(1, ['seriesType' => 'standard']),
            $this->series(2, ['seriesType' => 'anime']),
            $this->series(3, ['seriesType' => 'daily']),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(status: 'anime'));

        $this->assertSame([2], $this->ids($result->items));
    }

    public function testStatusMissingRequiresMonitoredEpisodesAndIncompleteness(): void
    {
        // Parity with series.html.twig: missing == monitored && percent < 100
        // && episodeCount > 0. A 100%-complete monitored series is NOT missing,
        // and an unmonitored series with gaps is NOT missing either.
        $series = [
            $this->series(1, ['monitored' => true, 'percent' => 80, 'episodeCount' => 10]),
            $this->series(2, ['monitored' => true, 'percent' => 100, 'episodeCount' => 10]),
            $this->series(3, ['monitored' => false, 'percent' => 50, 'episodeCount' => 10]),
            $this->series(4, ['monitored' => true, 'percent' => 50, 'episodeCount' => 0]),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(status: 'missing'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testStatusMonitoredAndUnmonitoredAreMirrorOpposites(): void
    {
        $series = [
            $this->series(1, ['monitored' => true]),
            $this->series(2, ['monitored' => false]),
        ];

        $this->assertSame([1], $this->ids(
            $this->filter->apply($series, new SeriesLibraryQuery(status: 'monitored'))->items
        ));
        $this->assertSame([2], $this->ids(
            $this->filter->apply($series, new SeriesLibraryQuery(status: 'unmonitored'))->items
        ));
    }

    public function testSearchIsCaseInsensitiveOnTitle(): void
    {
        $series = [
            $this->series(1, ['title' => 'Breaking Bad']),
            $this->series(2, ['title' => 'Better Call Saul']),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(q: 'BREAKING'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testGenreFilterRequiresExactMembership(): void
    {
        $series = [
            $this->series(1, ['genres' => ['Drama', 'Crime']]),
            $this->series(2, ['genres' => ['Comedy']]),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(genre: 'Drama'));

        $this->assertSame([1], $this->ids($result->items));
    }

    public function testNetworkFilterExactMatch(): void
    {
        $series = [
            $this->series(1, ['network' => 'AMC']),
            $this->series(2, ['network' => 'HBO']),
            $this->series(3, ['network' => 'AMC']),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(network: 'AMC'));

        $this->assertSame([1, 3], $this->ids($result->items));
    }

    public function testSortTitleKeepsLeadingArticles(): void
    {
        // Same rule as MovieLibraryFilter — "The Wire" sorts under T.
        $series = [
            $this->series(1, ['title' => 'Breaking Bad', 'sortTitle' => 'breaking bad']),
            $this->series(2, ['title' => 'The Wire',     'sortTitle' => 'wire']),
            $this->series(3, ['title' => 'Atlanta',      'sortTitle' => 'atlanta']),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(sort: 'title-asc'));

        $this->assertSame([3, 1, 2], $this->ids($result->items));
    }

    public function testSortPercentDescAndAsc(): void
    {
        $series = [
            $this->series(1, ['percent' => 100]),
            $this->series(2, ['percent' => 30]),
            $this->series(3, ['percent' => 75]),
        ];

        $this->assertSame([1, 3, 2], $this->ids(
            $this->filter->apply($series, new SeriesLibraryQuery(sort: 'percent-desc'))->items
        ));
        $this->assertSame([2, 3, 1], $this->ids(
            $this->filter->apply($series, new SeriesLibraryQuery(sort: 'percent-asc'))->items
        ));
    }

    public function testSortAddedDescUsesAddedAtImmutable(): void
    {
        // addedAt is a DateTimeImmutable, not a string — the comparator must
        // format consistently so the order is stable across PHP runtimes.
        $series = [
            $this->series(1, ['addedAt' => new \DateTimeImmutable('2024-01-01')]),
            $this->series(2, ['addedAt' => new \DateTimeImmutable('2026-05-10')]),
            $this->series(3, ['addedAt' => new \DateTimeImmutable('2025-06-15')]),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(sort: 'added-desc'));

        $this->assertSame([2, 3, 1], $this->ids($result->items));
    }

    public function testPaginationAndPageClamp(): void
    {
        $series = [];
        for ($i = 1; $i <= 5; $i++) {
            $series[] = $this->series($i, ['title' => sprintf('Series %02d', $i)]);
        }

        $page1 = $this->filter->apply($series, new SeriesLibraryQuery(perPage: 2, page: 1));
        $this->assertSame([1, 2], $this->ids($page1->items));
        $this->assertSame(3, $page1->totalPages);

        $beyond = $this->filter->apply($series, new SeriesLibraryQuery(perPage: 2, page: 42));
        $this->assertSame([5], $this->ids($beyond->items), 'clamps to the last page rather than rendering empty');
    }

    public function testUnlimitedBypassesPagination(): void
    {
        $series = [];
        for ($i = 1; $i <= 800; $i++) {
            $series[] = $this->series($i);
        }

        $result = $this->filter->apply($series, new SeriesLibraryQuery(perPage: 50, page: 1, unlimited: true));

        $this->assertCount(800, $result->items);
        $this->assertSame(1, $result->totalPages);
        $this->assertTrue($result->unlimited);
    }

    public function testFacetsComputedFromUnfilteredLibrary(): void
    {
        $series = [
            $this->series(1, ['genres' => ['Drama'], 'network' => 'HBO']),
            $this->series(2, ['genres' => ['Comedy'], 'network' => 'NBC']),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(genre: 'Drama'));

        $this->assertSame(['Comedy', 'Drama'], $result->genres, 'all genres visible even when filtering by one');
        $this->assertSame(['HBO', 'NBC'], $result->networks);
    }

    public function testFromRequestSanitizesUnknownStatus(): void
    {
        $request = Request::create('/?status=evilvalue&sort=hax&per_page=99');

        $query = SeriesLibraryQuery::fromRequest($request);

        $this->assertSame('all', $query->status);
        $this->assertSame('title-asc', $query->sort);
        $this->assertSame(200, $query->perPage);
    }

    public function testFromRequestHonorsLegacyFilterParam(): void
    {
        // v1.0 used ?filter=continuing — keep bookmarks working.
        $request = Request::create('/?filter=continuing');

        $query = SeriesLibraryQuery::fromRequest($request);

        $this->assertSame('continuing', $query->status);
    }

    public function testFromRequestPrefersStatusOverLegacyFilter(): void
    {
        $request = Request::create('/?filter=anime&status=ended');

        $query = SeriesLibraryQuery::fromRequest($request);

        $this->assertSame('ended', $query->status);
    }

    public function testFromRequestIgnoresLegacyFilterWhenInvalid(): void
    {
        $request = Request::create('/?filter=garbage');

        $query = SeriesLibraryQuery::fromRequest($request);

        $this->assertSame('all', $query->status);
    }

    public function testFromRequestHonorsLegacyFilterAndPaginationTogether(): void
    {
        $request = Request::create('/?filter=missing&page=4');

        $query = SeriesLibraryQuery::fromRequest($request);

        $this->assertSame('missing', $query->status);
        $this->assertSame(4, $query->page);
    }

    public function testHasActiveFilterDetection(): void
    {
        $this->assertFalse((new SeriesLibraryQuery())->hasActiveFilter());
        $this->assertFalse((new SeriesLibraryQuery(q: 'wire'))->hasActiveFilter(), 'text search alone does not enable bulk-filtered actions');
        $this->assertTrue((new SeriesLibraryQuery(status: 'continuing'))->hasActiveFilter());
        $this->assertTrue((new SeriesLibraryQuery(genre: 'Drama'))->hasActiveFilter());
        $this->assertTrue((new SeriesLibraryQuery(network: 'HBO'))->hasActiveFilter());
    }

    public function testWithoutPaginationKeepsFiltersButDropsPaging(): void
    {
        $query = new SeriesLibraryQuery(
            q: 'wire',
            status: 'missing',
            genre: 'Drama',
            network: 'HBO',
            page: 5,
            perPage: 50,
        );

        $bulk = $query->withoutPagination();

        $this->assertSame('wire', $bulk->q);
        $this->assertSame('missing', $bulk->status);
        $this->assertSame('Drama', $bulk->genre);
        $this->assertSame('HBO', $bulk->network);
        $this->assertTrue($bulk->unlimited);
    }

    public function testToQueryArrayStripsDefaultsForCleanUrls(): void
    {
        $this->assertSame([], (new SeriesLibraryQuery())->toQueryArray());

        $tweaked = new SeriesLibraryQuery(q: 'wire', status: 'continuing', network: 'HBO', sort: 'year-desc', perPage: 100);
        $this->assertSame(
            ['q' => 'wire', 'status' => 'continuing', 'network' => 'HBO', 'sort' => 'year-desc', 'per_page' => 100],
            $tweaked->toQueryArray()
        );
    }

    public function testCombinedFiltersIntersect(): void
    {
        $series = [
            $this->series(1, ['status' => 'continuing', 'seriesType' => 'anime', 'network' => 'Crunchyroll']),
            $this->series(2, ['status' => 'continuing', 'seriesType' => 'standard', 'network' => 'AMC']),
            $this->series(3, ['status' => 'ended', 'seriesType' => 'anime', 'network' => 'Crunchyroll']),
        ];

        $result = $this->filter->apply($series, new SeriesLibraryQuery(
            status: 'anime',
            network: 'Crunchyroll',
        ));

        $this->assertSame([1, 3], $this->ids($result->items));
    }
}
