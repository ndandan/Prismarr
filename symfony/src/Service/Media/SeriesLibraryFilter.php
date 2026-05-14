<?php

namespace App\Service\Media;

/**
 * Pure filter/sort/paginate for the normalized Sonarr series list (issue #19).
 *
 * Sonarr-specific semantics:
 *   - status='continuing' / 'ended' compare against the upstream `status` field
 *   - status='anime' compares against `seriesType`
 *   - status='missing' = monitored && percent < 100 && episodeCount > 0
 *     (matches the legacy applyFilters() in series.html.twig)
 *
 * Facets returned: genres + networks (no quality / language for series).
 */
final class SeriesLibraryFilter
{
    /**
     * @param array<int, array<string, mixed>> $series Normalized Sonarr series (SonarrClient::getSeries()).
     */
    public function apply(array $series, SeriesLibraryQuery $query): SeriesLibraryResult
    {
        $libraryTotal = count($series);

        [$genres, $networks] = $this->collectFacets($series);

        $filtered = [];
        foreach ($series as $entry) {
            if ($this->matches($entry, $query)) {
                $filtered[] = $entry;
            }
        }

        $this->sortInPlace($filtered, $query->sort);
        $total = count($filtered);

        if ($query->unlimited) {
            return new SeriesLibraryResult(
                items: $filtered,
                total: $total,
                libraryTotal: $libraryTotal,
                page: 1,
                perPage: max(1, $total),
                totalPages: 1,
                unlimited: true,
                genres: $genres,
                networks: $networks,
            );
        }

        $totalPages = $total === 0 ? 1 : (int) ceil($total / $query->perPage);
        $page = min($query->page, $totalPages);
        $offset = ($page - 1) * $query->perPage;
        $items = array_slice($filtered, $offset, $query->perPage);

        return new SeriesLibraryResult(
            items: $items,
            total: $total,
            libraryTotal: $libraryTotal,
            page: $page,
            perPage: $query->perPage,
            totalPages: $totalPages,
            unlimited: false,
            genres: $genres,
            networks: $networks,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $series
     * @return array{0: list<string>, 1: list<string>}
     */
    private function collectFacets(array $series): array
    {
        $genres = $networks = [];
        foreach ($series as $entry) {
            foreach ((array) ($entry['genres'] ?? []) as $genre) {
                if (is_string($genre) && $genre !== '') {
                    $genres[$genre] = true;
                }
            }
            $network = $entry['network'] ?? null;
            if (is_string($network) && $network !== '') {
                $networks[$network] = true;
            }
        }
        $g = array_keys($genres);
        $n = array_keys($networks);
        sort($g, SORT_NATURAL | SORT_FLAG_CASE);
        sort($n, SORT_NATURAL | SORT_FLAG_CASE);

        return [$g, $n];
    }

    /**
     * @param array<string, mixed> $series
     */
    private function matches(array $series, SeriesLibraryQuery $query): bool
    {
        if ($query->q !== '') {
            $needle = mb_strtolower($query->q);
            $hay = mb_strtolower((string) ($series['title'] ?? '') . '|' . (string) ($series['sortTitle'] ?? ''));
            if (!str_contains($hay, $needle)) {
                return false;
            }
        }

        $monitored   = (bool) ($series['monitored'] ?? false);
        $status      = (string) ($series['status'] ?? '');
        $seriesType  = (string) ($series['seriesType'] ?? '');
        $percent     = (float) ($series['percent'] ?? 0);
        $epCount     = (int) ($series['episodeCount'] ?? 0);

        switch ($query->status) {
            case 'monitored':
                if (!$monitored) return false;
                break;
            case 'continuing':
                if ($status !== 'continuing') return false;
                break;
            case 'ended':
                if ($status !== 'ended') return false;
                break;
            case 'anime':
                if ($seriesType !== 'anime') return false;
                break;
            case 'unmonitored':
                if ($monitored) return false;
                break;
            case 'missing':
                if (!$monitored || $percent >= 100 || $epCount <= 0) return false;
                break;
        }

        if ($query->genre !== '') {
            $genres = (array) ($series['genres'] ?? []);
            if (!in_array($query->genre, $genres, true)) {
                return false;
            }
        }

        if ($query->network !== '' && (string) ($series['network'] ?? '') !== $query->network) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function sortInPlace(array &$items, string $sort): void
    {
        usort($items, function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'title-asc'  => strnatcasecmp($this->titleSortKey($a), $this->titleSortKey($b)),
                'title-desc' => strnatcasecmp($this->titleSortKey($b), $this->titleSortKey($a)),
                'year-desc'  => ((int) ($b['year'] ?? 0)) <=> ((int) ($a['year'] ?? 0)),
                'year-asc'   => ((int) ($a['year'] ?? 0)) <=> ((int) ($b['year'] ?? 0)),
                'added-desc' => strcmp(
                    isset($b['addedAt']) ? $b['addedAt']->format(DATE_ATOM) : '',
                    isset($a['addedAt']) ? $a['addedAt']->format(DATE_ATOM) : '',
                ),
                'size-desc'  => ((int) ($b['sizeOnDisk'] ?? 0)) <=> ((int) ($a['sizeOnDisk'] ?? 0)),
                'size-asc'   => ((int) ($a['sizeOnDisk'] ?? 0)) <=> ((int) ($b['sizeOnDisk'] ?? 0)),
                'percent-desc' => ((float) ($b['percent'] ?? 0)) <=> ((float) ($a['percent'] ?? 0)),
                'percent-asc'  => ((float) ($a['percent'] ?? 0)) <=> ((float) ($b['percent'] ?? 0)),
                default      => 0,
            };
        });
    }

    /**
     * Sort on the raw title so a leading article stays in place (parity with
     * MovieLibraryFilter::titleSortKey). Falls back to sortTitle when title
     * is empty.
     *
     * @param array<string, mixed> $entry
     */
    private function titleSortKey(array $entry): string
    {
        $title = (string) ($entry['title'] ?? '');
        if ($title === '') {
            return (string) ($entry['sortTitle'] ?? '');
        }

        return mb_strtolower($title);
    }
}
