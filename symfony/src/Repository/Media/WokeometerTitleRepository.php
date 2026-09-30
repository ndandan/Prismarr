<?php

namespace App\Repository\Media;

use App\Entity\Media\WokeometerTitle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Local Wokeometer catalog mirror (`wokeometer_media`). Raw, parameter-bound
 * DBAL only — the sync writes pages of rows from a long-lived messenger
 * consumer, and lookups are single indexed reads on (tmdb_id, media_type).
 * No method here performs network I/O.
 *
 * Matching rule (spec D8): `movie` lookups match `media_type = 'movie'`;
 * `tv` lookups match only SERIES rows (`parent_wokeometer_id IS NULL`).
 * Season rows are stored and counted but never matched — a season's
 * external id may be a TMDb season id that collides with an unrelated show.
 * Belt and braces, a row carrying a positive `season_number` never matches
 * either (a season row whose parent id the API left out). The live API emits
 * `season_number = 0` on movies and a non-positive sentinel (e.g. -1) on series
 * rows, so only a parent id or a POSITIVE integer season number marks a
 * season; NULL, 0 and negatives all mean "not a season". The value is CAST to
 * INTEGER first so a stray TEXT value cannot slip through a type comparison,
 * and already-stored sentinels stay matchable without a resync.
 *
 * Row shape returned by findForTmdb()/findForTmdbMany() (snake_case columns,
 * integer columns cast to int, is_analyzed to bool, nulls preserved):
 *
 * @phpstan-type WokeometerRow array{
 *     id: int, wokeometer_id: string, media_type: string, tmdb_id: ?int,
 *     external_source: ?string, external_id: ?string, parent_wokeometer_id: ?string,
 *     season_number: ?int, title: string, release_date: ?string, woke_score: ?int,
 *     tldr: ?string, slug: ?string, is_analyzed: ?bool, wokeometer_updated_at: int,
 *     last_seen_at: int, synced_at: int
 * }
 *
 * @extends ServiceEntityRepository<WokeometerTitle>
 */
class WokeometerTitleRepository extends ServiceEntityRepository
{
    public const MEDIA_TYPES = ['movie', 'tv'];

    /** Max ids per `IN (…)` — well under SQLite's bound-parameter limit. */
    private const IN_CHUNK = 500;

    private const UPSERT_SQL = <<<'SQL'
        INSERT INTO wokeometer_media (wokeometer_id, media_type, tmdb_id, external_source, external_id,
            parent_wokeometer_id, season_number, title, release_date, woke_score, tldr, slug, is_analyzed,
            wokeometer_updated_at, last_seen_at, synced_at)
        VALUES (:wid, :type, :tmdb, :src, :ext, :parent, :season, :title, :rel, :score, :tldr, :slug, :analyzed,
            :upd, :seen, :now)
        ON CONFLICT(wokeometer_id) DO UPDATE SET
            media_type = excluded.media_type, tmdb_id = excluded.tmdb_id,
            external_source = excluded.external_source, external_id = excluded.external_id,
            parent_wokeometer_id = excluded.parent_wokeometer_id, season_number = excluded.season_number,
            title = excluded.title, release_date = excluded.release_date, woke_score = excluded.woke_score,
            tldr = excluded.tldr, slug = excluded.slug, is_analyzed = excluded.is_analyzed,
            wokeometer_updated_at = excluded.wokeometer_updated_at, synced_at = excluded.synced_at,
            last_seen_at = MAX(wokeometer_media.last_seen_at, excluded.last_seen_at)
        WHERE excluded.wokeometer_updated_at >= wokeometer_media.wokeometer_updated_at
        SQL;

    /** A row is a season when it has a parent id or a positive integer season number. */
    private const IS_SEASON = '(parent_wokeometer_id IS NOT NULL OR CAST(COALESCE(season_number, 0) AS INTEGER) > 0)';

    /** SQL fragment appended to every match query (spec D8 + season guard). */
    private const MATCHABLE = "((media_type = 'movie' OR parent_wokeometer_id IS NULL) AND CAST(COALESCE(season_number, 0) AS INTEGER) <= 0)";

    private const INT_COLUMNS = ['id', 'tmdb_id', 'season_number', 'woke_score', 'wokeometer_updated_at', 'last_seen_at', 'synced_at'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WokeometerTitle::class);
    }

    /**
     * Idempotent upsert of normalized rows (the WokeometerPageResult shape:
     * wokeometerId, mediaType, title, releaseDate, wokeScore, tldr, slug,
     * isAnalyzed, lastUpdated, parentWokeometerId, seasonNumber,
     * externalSource, externalId, tmdbId), in ONE transaction.
     *
     * Monotonic guard: an incoming row only overwrites data when its
     * `lastUpdated` is >= the stored one — never regress to older data.
     * `last_seen_at` (the deletion-sweep marker) is bumped to $seenAt for
     * every accepted row even when the guard rejects the data update, and
     * never moves backwards. `synced_at` = $now only when data was written.
     *
     * Rows whose mediaType is not movie|tv, or that lack a wokeometerId, are
     * skipped.
     *
     * @param list<array<string, mixed>> $rows
     * @return int number of rows accepted (valid shape), whether or not the
     *             monotonic guard let their data through
     */
    public function upsertRows(array $rows, int $seenAt, int $now): int
    {
        $accepted = [];
        foreach ($rows as $row) {
            $wid  = $row['wokeometerId'] ?? null;
            $type = $row['mediaType'] ?? null;
            if (!is_string($wid) || $wid === '' || !in_array($type, self::MEDIA_TYPES, true)) {
                continue;
            }
            $accepted[] = $row;
        }
        if ($accepted === []) {
            return 0;
        }

        $db = $this->db();
        $db->transactional(function (Connection $db) use ($accepted, $seenAt, $now): void {
            $ids = [];
            foreach ($accepted as $row) {
                $analyzed = $row['isAnalyzed'] ?? null;
                $db->executeStatement(self::UPSERT_SQL, [
                    'wid'      => (string) $row['wokeometerId'],
                    'type'     => (string) $row['mediaType'],
                    'tmdb'     => self::intOrNull($row['tmdbId'] ?? null),
                    'src'      => self::stringOrNull($row['externalSource'] ?? null),
                    'ext'      => self::stringOrNull($row['externalId'] ?? null),
                    'parent'   => self::stringOrNull($row['parentWokeometerId'] ?? null),
                    'season'   => self::intOrNull($row['seasonNumber'] ?? null),
                    'title'    => (string) ($row['title'] ?? ''),
                    'rel'      => self::stringOrNull($row['releaseDate'] ?? null),
                    'score'    => self::intOrNull($row['wokeScore'] ?? null),
                    'tldr'     => self::stringOrNull($row['tldr'] ?? null),
                    'slug'     => self::stringOrNull($row['slug'] ?? null),
                    'analyzed' => $analyzed === null ? null : ($analyzed ? 1 : 0),
                    'upd'      => (int) ($row['lastUpdated'] ?? 0),
                    'seen'     => $seenAt,
                    'now'      => $now,
                ], [
                    'tmdb'     => ParameterType::INTEGER,
                    'season'   => ParameterType::INTEGER,
                    'score'    => ParameterType::INTEGER,
                    'analyzed' => ParameterType::INTEGER,
                    'upd'      => ParameterType::INTEGER,
                    'seen'     => ParameterType::INTEGER,
                    'now'      => ParameterType::INTEGER,
                ]);
                $ids[] = (string) $row['wokeometerId'];
            }

            // Rows the monotonic guard rejected were still SEEN by this run:
            // bump their sweep marker so a full-run sweep never deletes them.
            foreach (array_chunk(array_values(array_unique($ids)), self::IN_CHUNK) as $chunk) {
                $db->executeStatement(
                    'UPDATE wokeometer_media SET last_seen_at = ? WHERE last_seen_at < ? AND wokeometer_id IN (?)',
                    [$seenAt, $seenAt, $chunk],
                    [ParameterType::INTEGER, ParameterType::INTEGER, ArrayParameterType::STRING],
                );
            }
        });

        return count($accepted);
    }

    /**
     * Best match for one TMDb id. For `tv` only series rows qualify; among
     * several candidates the series row wins, then the most recently
     * updated one.
     *
     * @return WokeometerRow|null
     */
    public function findForTmdb(string $mediaType, int $tmdbId): ?array
    {
        if (!in_array($mediaType, self::MEDIA_TYPES, true) || $tmdbId <= 0) {
            return null;
        }

        $row = $this->db()->fetchAssociative(
            'SELECT * FROM wokeometer_media WHERE tmdb_id = ? AND media_type = ? AND ' . self::MATCHABLE
            . ' ORDER BY parent_wokeometer_id IS NULL DESC, wokeometer_updated_at DESC, id DESC LIMIT 1',
            [$tmdbId, $mediaType],
            [ParameterType::INTEGER, ParameterType::STRING],
        );

        return $row === false ? null : self::castRow($row);
    }

    /**
     * Bulk variant of findForTmdb(): same preference per id, chunked IN of
     * ≤ 500 ids. Only matched ids appear in the result.
     *
     * @param list<int> $ids
     * @return array<int, WokeometerRow> keyed by tmdb id
     */
    public function findForTmdbMany(string $mediaType, array $ids): array
    {
        $ids = self::cleanIds($ids);
        if (!in_array($mediaType, self::MEDIA_TYPES, true) || $ids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, self::IN_CHUNK) as $chunk) {
            $rows = $this->db()->fetchAllAssociative(
                'SELECT * FROM wokeometer_media WHERE media_type = ? AND tmdb_id IN (?) AND ' . self::MATCHABLE
                . ' ORDER BY tmdb_id, parent_wokeometer_id IS NULL DESC, wokeometer_updated_at DESC, id DESC',
                [$mediaType, $chunk],
                [ParameterType::STRING, ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $cast = self::castRow($row);
                $key  = (int) $cast['tmdb_id'];
                if (!isset($out[$key])) {
                    $out[$key] = $cast; // first row per id = preferred
                }
            }
        }

        return $out;
    }

    /**
     * @return array{total: int, movies: int, series: int, seasons: int, withTmdb: int}
     */
    public function counts(): array
    {
        $isSeason = self::IS_SEASON;
        $r = $this->db()->fetchAssociative(<<<SQL
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN media_type = 'movie' THEN 1 ELSE 0 END), 0) AS movies,
                   COALESCE(SUM(CASE WHEN media_type = 'tv' AND NOT {$isSeason} THEN 1 ELSE 0 END), 0) AS series,
                   COALESCE(SUM(CASE WHEN media_type = 'tv' AND {$isSeason} THEN 1 ELSE 0 END), 0) AS seasons,
                   COALESCE(SUM(CASE WHEN tmdb_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS with_tmdb
            FROM wokeometer_media
            SQL);

        return [
            'total'    => (int) ($r['total'] ?? 0),
            'movies'   => (int) ($r['movies'] ?? 0),
            'series'   => (int) ($r['series'] ?? 0),
            'seasons'  => (int) ($r['seasons'] ?? 0),
            'withTmdb' => (int) ($r['with_tmdb'] ?? 0),
        ];
    }

    /**
     * How many of the given TMDb ids have a matchable row (same D8 rule as
     * findForTmdb). Duplicate ids count once.
     *
     * @param list<int> $ids
     */
    public function countMatching(string $mediaType, array $ids): int
    {
        $ids = self::cleanIds($ids);
        if (!in_array($mediaType, self::MEDIA_TYPES, true) || $ids === []) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk($ids, self::IN_CHUNK) as $chunk) {
            $total += (int) $this->db()->fetchOne(
                'SELECT COUNT(DISTINCT tmdb_id) FROM wokeometer_media WHERE media_type = ? AND tmdb_id IN (?) AND ' . self::MATCHABLE,
                [$mediaType, $chunk],
                [ParameterType::STRING, ArrayParameterType::INTEGER],
            );
        }

        return $total;
    }

    /**
     * Deletion sweep after a completed FULL run: removes rows the run never
     * returned (`last_seen_at < $before`, i.e. before the run's start),
     * restricted to one media type when `$mediaType` is given (the sync
     * sweeps each phase separately, and only a phase that saw rows).
     *
     * @return int rows deleted
     */
    public function sweepUnseen(int $before, ?string $mediaType = null): int
    {
        if ($mediaType === null) {
            return (int) $this->db()->executeStatement(
                'DELETE FROM wokeometer_media WHERE last_seen_at < ?',
                [$before],
                [ParameterType::INTEGER],
            );
        }
        if (!in_array($mediaType, self::MEDIA_TYPES, true)) {
            return 0;
        }

        return (int) $this->db()->executeStatement(
            'DELETE FROM wokeometer_media WHERE last_seen_at < ? AND media_type = ?',
            [$before, $mediaType],
            [ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    /**
     * Rows of one media type seen at or after `$since` (the sweep marker a
     * run stamps on every row it returned). 0 for an unknown type.
     */
    public function countSeenSince(string $mediaType, int $since): int
    {
        if (!in_array($mediaType, self::MEDIA_TYPES, true)) {
            return 0;
        }

        return (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM wokeometer_media WHERE media_type = ? AND last_seen_at >= ?',
            [$mediaType, $since],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    public function truncate(): void
    {
        $this->db()->executeStatement('DELETE FROM wokeometer_media');
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    /**
     * @param array<string, mixed> $row
     * @return WokeometerRow
     */
    private static function castRow(array $row): array
    {
        foreach (self::INT_COLUMNS as $col) {
            $row[$col] = $row[$col] === null ? null : (int) $row[$col];
        }
        $row['is_analyzed'] = $row['is_analyzed'] === null ? null : (bool) $row['is_analyzed'];

        /** @var WokeometerRow $row */
        return $row;
    }

    /**
     * @param array<mixed> $ids
     * @return list<int> positive, de-duplicated, order-preserving
     */
    private static function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    private static function intOrNull(mixed $v): ?int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);
    }

    private static function stringOrNull(mixed $v): ?string
    {
        return is_string($v) ? $v : (is_int($v) ? (string) $v : null);
    }
}
