<?php

namespace App\Service\Media;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-cached radarrId/sonarrSeriesId -> Bazarr subtitle status lookup, for badge rendering.
 *
 * @phpstan-type SubtitleStatus array{state: 'complete'|'missing'|'hidden', count: int, hasProfile: bool}
 */
class BazarrSubtitleIndex implements ResetInterface
{
    /** @var SubtitleStatus */
    private const HIDDEN = ['state' => 'hidden', 'count' => 0, 'hasProfile' => false];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $movies = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $series = null;

    public function __construct(private readonly BazarrClient $client)
    {
    }

    public function reset(): void
    {
        $this->movies = null;
        $this->series = null;
    }

    public function invalidate(): void
    {
        $this->reset();
    }

    /** @return SubtitleStatus */
    public function movieStatus(int $radarrId): array
    {
        if ($this->movies === null) {
            $this->movies = [];
            foreach ($this->client->getMovies() as $m) {
                if (isset($m['radarrId'])) {
                    $this->movies[(int) $m['radarrId']] = $m;
                }
            }
        }

        return isset($this->movies[$radarrId]) ? self::computeMovieStatus($this->movies[$radarrId]) : self::HIDDEN;
    }

    /** @return SubtitleStatus */
    public function seriesStatus(int $sonarrSeriesId): array
    {
        if ($this->series === null) {
            $this->series = [];
            foreach ($this->client->getSeries() as $s) {
                if (isset($s['sonarrSeriesId'])) {
                    $this->series[(int) $s['sonarrSeriesId']] = $s;
                }
            }
        }

        return isset($this->series[$sonarrSeriesId]) ? self::computeSeriesStatus($this->series[$sonarrSeriesId]) : self::HIDDEN;
    }

    /**
     * @param array<string, mixed> $movie
     * @return SubtitleStatus
     */
    public static function computeMovieStatus(array $movie): array
    {
        if (($movie['profileId'] ?? null) === null) {
            return self::HIDDEN;
        }

        $missing = is_countable($movie['missing_subtitles'] ?? null) ? count($movie['missing_subtitles']) : 0;

        return $missing > 0
            ? ['state' => 'missing', 'count' => $missing, 'hasProfile' => true]
            : ['state' => 'complete', 'count' => 0, 'hasProfile' => true];
    }

    /**
     * @param array<string, mixed> $series
     * @return SubtitleStatus
     */
    public static function computeSeriesStatus(array $series): array
    {
        if (($series['profileId'] ?? null) === null) {
            return self::HIDDEN;
        }

        $files = (int) ($series['episodeFileCount'] ?? 0);
        if ($files === 0) {
            return self::HIDDEN;
        }

        $missing = (int) ($series['episodeMissingCount'] ?? 0);

        return $missing > 0
            ? ['state' => 'missing', 'count' => $missing, 'hasProfile' => true]
            : ['state' => 'complete', 'count' => 0, 'hasProfile' => true];
    }
}
