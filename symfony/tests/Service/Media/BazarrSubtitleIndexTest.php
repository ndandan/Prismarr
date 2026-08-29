<?php
namespace App\Tests\Service\Media;

use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexTest extends TestCase
{
    public function testComputeMovieMissingWithCount(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus([
            'profileId' => 1, 'subtitles' => [['code2' => 'en']],
            'missing_subtitles' => [['code2' => 'fr'], ['code2' => 'es']],
        ]);
        $this->assertSame('missing', $s['state']);
        $this->assertSame(2, $s['count']);
    }

    public function testComputeMovieComplete(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus([
            'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => [],
        ]);
        $this->assertSame('complete', $s['state']);
    }

    public function testComputeMovieHiddenWhenNoProfile(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus(['profileId' => null, 'missing_subtitles' => [['code2' => 'fr']]]);
        $this->assertSame('hidden', $s['state']);
    }

    public function testComputeSeriesHiddenWhenNoFiles(): void
    {
        $s = BazarrSubtitleIndex::computeSeriesStatus(['profileId' => 1, 'episodeFileCount' => 0, 'episodeMissingCount' => 0]);
        $this->assertSame('hidden', $s['state']);
    }

    public function testComputeSeriesMissing(): void
    {
        $s = BazarrSubtitleIndex::computeSeriesStatus(['profileId' => 1, 'episodeFileCount' => 10, 'episodeMissingCount' => 3]);
        $this->assertSame('missing', $s['state']);
        $this->assertSame(3, $s['count']);
    }

    public function testUnknownMovieIsHidden(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([['radarrId' => 1, 'profileId' => 1, 'missing_subtitles' => []]]);
        $client->method('getSeries')->willReturn([]);
        $index = new BazarrSubtitleIndex($client);
        $this->assertSame('hidden', $index->movieStatus(999)['state']);
        $this->assertSame('complete', $index->movieStatus(1)['state']);
    }

    public function testMoviesFetchedOncePerRequest(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->willReturn([['radarrId' => 1, 'profileId' => 1, 'missing_subtitles' => []]]);
        $client->method('getSeries')->willReturn([]);
        $index = new BazarrSubtitleIndex($client);
        $index->movieStatus(1);
        $index->movieStatus(1);
        $index->movieStatus(2);
    }
}
