<?php

namespace App\Tests\Repository\Media;

use App\Entity\Media\WokeometerTitle;
use App\Repository\Media\WokeometerTitleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real-SQLite tests for the Wokeometer catalog mirror. The schema is built
 * from ORM metadata (SchemaTool, same as AbstractWebTestCase) — migrations
 * never run in tests, so this also exercises the entity mapping the raw
 * DBAL SQL relies on (column names, unique index on wokeometer_id).
 */
class WokeometerTitleRepositoryTest extends KernelTestCase
{
    private WokeometerTitleRepository $repo;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $repo = $em->getRepository(WokeometerTitle::class);
        $this->assertInstanceOf(WokeometerTitleRepository::class, $repo);
        $this->repo = $repo;
        $this->db = $em->getConnection();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(string $wid, array $overrides = []): array
    {
        return array_merge([
            'wokeometerId'       => $wid,
            'mediaType'          => 'movie',
            'title'              => 'Title ' . $wid,
            'releaseDate'        => '2024-05-01',
            'wokeScore'          => 4,
            'tldr'               => 'Short summary.',
            'slug'               => 'title-' . $wid,
            'isAnalyzed'         => true,
            'lastUpdated'        => 1_700_000_000,
            'parentWokeometerId' => null,
            'seasonNumber'       => null,
            'externalSource'     => 'tmdb',
            'externalId'         => '100',
            'tmdbId'             => 100,
        ], $overrides);
    }

    private function tableCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM wokeometer_media');
    }

    /** @return array<string, mixed> */
    private function raw(string $wid): array
    {
        $r = $this->db->fetchAssociative('SELECT * FROM wokeometer_media WHERE wokeometer_id = ?', [$wid]);
        $this->assertIsArray($r, "row $wid should exist");
        return $r;
    }

    public function testInsertThenUpdateThenIdempotentRerun(): void
    {
        $n = $this->repo->upsertRows([$this->row('a'), $this->row('b', ['tmdbId' => 200, 'externalId' => '200'])], 1000, 1001);
        $this->assertSame(2, $n);
        $this->assertSame(2, $this->tableCount());

        $a = $this->raw('a');
        $this->assertSame('movie', $a['media_type']);
        $this->assertSame(100, (int) $a['tmdb_id']);
        $this->assertSame('tmdb', $a['external_source']);
        $this->assertSame('100', $a['external_id']);
        $this->assertSame('2024-05-01', $a['release_date']);
        $this->assertSame(4, (int) $a['woke_score']);
        $this->assertSame(1000, (int) $a['last_seen_at']);
        $this->assertSame(1001, (int) $a['synced_at']);
        $this->assertSame(1_700_000_000, (int) $a['wokeometer_updated_at']);
        $this->assertSame(1, (int) $a['is_analyzed']);

        // Newer data overwrites.
        $newer = $this->row('a', ['wokeScore' => 0, 'tldr' => 'Changed', 'lastUpdated' => 1_700_000_500]);
        $this->repo->upsertRows([$newer], 2000, 2001);
        $a = $this->raw('a');
        $this->assertSame(0, (int) $a['woke_score'], '0 is a valid score and must be stored, not nulled');
        $this->assertNotNull($a['woke_score']);
        $this->assertSame('Changed', $a['tldr']);
        $this->assertSame(2000, (int) $a['last_seen_at']);
        $this->assertSame(2001, (int) $a['synced_at']);
        $this->assertSame(2, $this->tableCount());

        // Re-running the same page (retry / idempotent replay) changes nothing.
        $this->assertSame(1, $this->repo->upsertRows([$newer], 2000, 2001));
        $this->repo->upsertRows([$newer], 2000, 2001);
        $this->assertSame(2, $this->tableCount());
        $this->assertSame($a, $this->raw('a'));
    }

    public function testMonotonicGuardRejectsOlderDataButBumpsLastSeen(): void
    {
        $this->repo->upsertRows([$this->row('a', ['tldr' => 'New', 'lastUpdated' => 5000])], 1000, 1000);

        $this->repo->upsertRows([$this->row('a', ['tldr' => 'Old', 'wokeScore' => 9, 'lastUpdated' => 4000])], 3000, 3001);

        $a = $this->raw('a');
        $this->assertSame('New', $a['tldr'], 'older lastUpdated must not overwrite');
        $this->assertSame(4, (int) $a['woke_score']);
        $this->assertSame(5000, (int) $a['wokeometer_updated_at']);
        $this->assertSame(1000, (int) $a['synced_at'], 'no data write → synced_at untouched');
        $this->assertSame(3000, (int) $a['last_seen_at'], 'last_seen_at is bumped even when the data update is rejected');
    }

    public function testLastSeenNeverMovesBackwards(): void
    {
        $this->repo->upsertRows([$this->row('a')], 5000, 5000);
        $this->repo->upsertRows([$this->row('a')], 4000, 4000);
        $this->assertSame(5000, (int) $this->raw('a')['last_seen_at']);
    }

    public function testRowsWithUnknownMediaTypeOrMissingIdAreSkipped(): void
    {
        $n = $this->repo->upsertRows([
            $this->row('a'),
            $this->row('b', ['mediaType' => 'series']),
            $this->row('c', ['mediaType' => 'episode']),
            $this->row(''),
        ], 1000, 1000);

        $this->assertSame(1, $n);
        $this->assertSame(1, $this->tableCount());
    }

    public function testEmptyInputIsANoop(): void
    {
        $this->assertSame(0, $this->repo->upsertRows([], 1000, 1000));
        $this->assertSame(0, $this->tableCount());
    }

    public function testFindForTmdbReturnsTypedRow(): void
    {
        $this->repo->upsertRows([$this->row('a', ['isAnalyzed' => false, 'wokeScore' => null])], 1000, 1001);

        $r = $this->repo->findForTmdb('movie', 100);

        $this->assertNotNull($r);
        $this->assertSame('a', $r['wokeometer_id']);
        $this->assertSame('movie', $r['media_type']);
        $this->assertSame(100, $r['tmdb_id']);
        $this->assertNull($r['woke_score']);
        $this->assertFalse($r['is_analyzed']);
        $this->assertSame('title-a', $r['slug']);
        $this->assertSame('Title a', $r['title']);
        $this->assertSame(1_700_000_000, $r['wokeometer_updated_at']);
        $this->assertSame(1000, $r['last_seen_at']);
        $this->assertSame(1001, $r['synced_at']);
    }

    public function testFindForTmdbMissingAndMismatchedTypeReturnNull(): void
    {
        $this->repo->upsertRows([$this->row('a')], 1000, 1000);

        $this->assertNull($this->repo->findForTmdb('movie', 999), 'unknown tmdb id');
        $this->assertNull($this->repo->findForTmdb('tv', 100), 'movie row must not match a tv lookup');
        $this->assertNull($this->repo->findForTmdb('series', 100), 'library vocabulary is not accepted');
    }

    public function testSeriesRowWinsOverSeasonRowsAndSeasonsNeverMatchAlone(): void
    {
        $this->repo->upsertRows([
            $this->row('s1', ['mediaType' => 'tv', 'tmdbId' => 50, 'parentWokeometerId' => 'show', 'seasonNumber' => 1, 'lastUpdated' => 9000, 'wokeScore' => 1]),
            $this->row('show', ['mediaType' => 'tv', 'tmdbId' => 50, 'lastUpdated' => 1000, 'wokeScore' => 7]),
            $this->row('s2', ['mediaType' => 'tv', 'tmdbId' => 50, 'parentWokeometerId' => 'show', 'seasonNumber' => 2, 'lastUpdated' => 9500, 'wokeScore' => 2]),
            // A season whose external id collides with a tmdb id that has no series row.
            $this->row('orphan', ['mediaType' => 'tv', 'tmdbId' => 60, 'parentWokeometerId' => 'other', 'seasonNumber' => 1]),
        ], 1000, 1000);

        $r = $this->repo->findForTmdb('tv', 50);
        $this->assertNotNull($r);
        $this->assertSame('show', $r['wokeometer_id']);
        $this->assertSame(7, $r['woke_score']);

        $this->assertNull($this->repo->findForTmdb('tv', 60), 'season rows are never matched to a series');
    }

    public function testNewestSeriesRowWinsWhenDuplicated(): void
    {
        $this->repo->upsertRows([
            $this->row('old', ['mediaType' => 'tv', 'tmdbId' => 50, 'lastUpdated' => 1000]),
            $this->row('new', ['mediaType' => 'tv', 'tmdbId' => 50, 'lastUpdated' => 2000]),
        ], 1000, 1000);

        $this->assertSame('new', $this->repo->findForTmdb('tv', 50)['wokeometer_id'] ?? null);
    }

    public function testFindForTmdbManyKeysByTmdbIdAndChunks(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1200; $i++) {
            $rows[] = $this->row('m' . $i, ['tmdbId' => $i, 'externalId' => (string) $i]);
        }
        $rows[] = $this->row('show', ['mediaType' => 'tv', 'tmdbId' => 7]);
        $rows[] = $this->row('season', ['mediaType' => 'tv', 'tmdbId' => 7, 'parentWokeometerId' => 'show', 'seasonNumber' => 1, 'lastUpdated' => 1_800_000_000]);
        $this->repo->upsertRows($rows, 1000, 1000);

        // 1100 distinct ids → three IN chunks of ≤ 500.
        $ids = range(1, 1100);
        $ids[] = 5000; // no row
        $ids[] = 3;    // duplicate
        $found = $this->repo->findForTmdbMany('movie', $ids);

        $this->assertCount(1100, $found);
        $this->assertSame('m1', $found[1]['wokeometer_id']);
        $this->assertSame('m1100', $found[1100]['wokeometer_id']);
        $this->assertSame(1100, $found[1100]['tmdb_id']);
        $this->assertArrayNotHasKey(5000, $found);
        $this->assertArrayNotHasKey(1101, $found);

        $tv = $this->repo->findForTmdbMany('tv', [7, 1]);
        $this->assertSame([7], array_keys($tv));
        $this->assertSame('show', $tv[7]['wokeometer_id'], 'series row wins over its newer season row');

        $this->assertSame([], $this->repo->findForTmdbMany('movie', []));
        $this->assertSame([], $this->repo->findForTmdbMany('series', [1]));
    }

    public function testCounts(): void
    {
        $this->assertSame(['total' => 0, 'movies' => 0, 'series' => 0, 'seasons' => 0, 'withTmdb' => 0], $this->repo->counts());

        $this->repo->upsertRows([
            $this->row('m1'),
            $this->row('m2', ['tmdbId' => null, 'externalSource' => 'imdb', 'externalId' => 'tt1']),
            $this->row('show', ['mediaType' => 'tv', 'tmdbId' => 50]),
            $this->row('s1', ['mediaType' => 'tv', 'tmdbId' => null, 'parentWokeometerId' => 'show', 'seasonNumber' => 1]),
            $this->row('s2', ['mediaType' => 'tv', 'tmdbId' => 51, 'parentWokeometerId' => 'show', 'seasonNumber' => 2]),
        ], 1000, 1000);

        $this->assertSame(['total' => 5, 'movies' => 2, 'series' => 1, 'seasons' => 2, 'withTmdb' => 3], $this->repo->counts());
    }

    public function testCountsClassifySeasonRowsByEitherMarker(): void
    {
        $this->repo->upsertRows([
            $this->row('show', ['mediaType' => 'tv', 'tmdbId' => 50]),
            $this->row('orphan-season', ['mediaType' => 'tv', 'tmdbId' => 51, 'seasonNumber' => 3]),       // season_number only
            $this->row('child', ['mediaType' => 'tv', 'tmdbId' => 52, 'parentWokeometerId' => 'show']),   // parent only
        ], 1000, 1000);

        $c = $this->repo->counts();
        $this->assertSame(1, $c['series'], 'only rows matchable as a series (no parent AND no season number)');
        $this->assertSame(2, $c['seasons'], 'a parent OR a season number makes a season row');
    }

    public function testCountMatching(): void
    {
        $this->repo->upsertRows([
            $this->row('m1', ['tmdbId' => 1]),
            $this->row('m2', ['tmdbId' => 2]),
            $this->row('show', ['mediaType' => 'tv', 'tmdbId' => 2]),
            $this->row('season', ['mediaType' => 'tv', 'tmdbId' => 3, 'parentWokeometerId' => 'x', 'seasonNumber' => 1]),
        ], 1000, 1000);

        $this->assertSame(2, $this->repo->countMatching('movie', [1, 2, 3, 2]));
        $this->assertSame(1, $this->repo->countMatching('tv', [1, 2, 3]), 'season rows do not count as series matches');
        $this->assertSame(0, $this->repo->countMatching('movie', []));
        $this->assertSame(0, $this->repo->countMatching('series', [1]));
    }

    public function testSweepUnseenDeletesOnlyOlderRows(): void
    {
        $this->repo->upsertRows([$this->row('old')], 1000, 1000);
        $this->repo->upsertRows([$this->row('new')], 2000, 2000);
        $this->repo->upsertRows([$this->row('edge')], 1500, 1500);

        $this->assertSame(1, $this->repo->sweepUnseen(1500));
        $this->assertSame(2, $this->tableCount());
        $this->assertFalse($this->db->fetchOne("SELECT 1 FROM wokeometer_media WHERE wokeometer_id = 'old'"));
        $this->assertSame(0, $this->repo->sweepUnseen(1500));
    }

    public function testSweepUnseenCanBeRestrictedToOneMediaType(): void
    {
        $this->repo->upsertRows([$this->row('old-movie'), $this->row('old-show', ['mediaType' => 'tv'])], 1000, 1000);
        $this->repo->upsertRows([$this->row('new-show', ['mediaType' => 'tv'])], 2000, 2000);

        $this->assertSame(1, $this->repo->sweepUnseen(1500, 'tv'));
        $wids = $this->db->fetchFirstColumn('SELECT wokeometer_id FROM wokeometer_media ORDER BY wokeometer_id');
        $this->assertSame(['new-show', 'old-movie'], $wids, 'movies untouched by a tv sweep');
        $this->assertSame(0, $this->repo->sweepUnseen(1500, 'series'), 'library vocabulary is not a media type');
        $this->assertSame(1, $this->repo->sweepUnseen(1500, 'movie'));
    }

    public function testCountSeenSince(): void
    {
        $this->repo->upsertRows([$this->row('m-old')], 1000, 1000);
        $this->repo->upsertRows([$this->row('m-new'), $this->row('t-new', ['mediaType' => 'tv'])], 2000, 2000);

        $this->assertSame(1, $this->repo->countSeenSince('movie', 2000));
        $this->assertSame(2, $this->repo->countSeenSince('movie', 1000));
        $this->assertSame(1, $this->repo->countSeenSince('tv', 1500));
        $this->assertSame(0, $this->repo->countSeenSince('tv', 2001));
        $this->assertSame(0, $this->repo->countSeenSince('series', 0));
    }

    public function testRowsCarryingASeasonNumberNeverMatch(): void
    {
        // A season row whose parent id the API left out: parent NULL, season set.
        $this->repo->upsertRows([
            $this->row('season-no-parent', ['mediaType' => 'tv', 'tmdbId' => 70, 'seasonNumber' => 2]),
            $this->row('odd-movie', ['tmdbId' => 71, 'seasonNumber' => 1]),
            $this->row('show', ['mediaType' => 'tv', 'tmdbId' => 72]),
        ], 1000, 1000);

        $this->assertNull($this->repo->findForTmdb('tv', 70));
        $this->assertNull($this->repo->findForTmdb('movie', 71));
        $this->assertSame([72], array_keys($this->repo->findForTmdbMany('tv', [70, 72])));
        $this->assertSame([], $this->repo->findForTmdbMany('movie', [71]));
        $this->assertSame(1, $this->repo->countMatching('tv', [70, 72]));
        $this->assertSame(0, $this->repo->countMatching('movie', [71]));
    }

    public function testTruncate(): void
    {
        $this->repo->upsertRows([$this->row('a'), $this->row('b')], 1000, 1000);
        $this->repo->truncate();
        $this->assertSame(0, $this->tableCount());
    }
}
