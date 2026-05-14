<?php

namespace App\Service\Media;

/**
 * Pure filter/sort/paginate for the normalized Radarr movie list (issue #19).
 *
 * The semantics of status, search, quality, genre and language are kept
 * identical to the legacy client-side JS that scanned `.media-card` dataset
 * attributes, so admins switching from v1.0 to v1.1 see the same counts
 * for the same filters. See films.html.twig applyFilters() (v1.0) for the
 * reference behavior.
 *
 * Facets (genres / qualities / languages) are computed on the unfiltered
 * library — applying a filter narrows the visible items, never the dropdowns.
 */
final class MovieLibraryFilter
{
    /**
     * @param array<int, array<string, mixed>> $movies Normalized Radarr movies (RadarrClient::getMovies()).
     */
    public function apply(array $movies, MovieLibraryQuery $query): MovieLibraryResult
    {
        $libraryTotal = count($movies);

        [$genres, $qualities, $languages] = $this->collectFacets($movies);

        $filtered = [];
        foreach ($movies as $movie) {
            if ($this->matches($movie, $query)) {
                $filtered[] = $movie;
            }
        }

        $this->sortInPlace($filtered, $query->sort);
        $total = count($filtered);

        if ($query->unlimited) {
            return new MovieLibraryResult(
                items: $filtered,
                total: $total,
                libraryTotal: $libraryTotal,
                page: 1,
                perPage: max(1, $total),
                totalPages: 1,
                unlimited: true,
                genres: $genres,
                qualities: $qualities,
                languages: $languages,
            );
        }

        $totalPages = $total === 0 ? 1 : (int) ceil($total / $query->perPage);
        $page = min($query->page, $totalPages);
        $offset = ($page - 1) * $query->perPage;
        $items = array_slice($filtered, $offset, $query->perPage);

        return new MovieLibraryResult(
            items: $items,
            total: $total,
            libraryTotal: $libraryTotal,
            page: $page,
            perPage: $query->perPage,
            totalPages: $totalPages,
            unlimited: false,
            genres: $genres,
            qualities: $qualities,
            languages: $languages,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $movies
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function collectFacets(array $movies): array
    {
        $genres = $qualities = $languages = [];
        foreach ($movies as $movie) {
            foreach ((array) ($movie['genres'] ?? []) as $genre) {
                if (is_string($genre) && $genre !== '') {
                    $genres[$genre] = true;
                }
            }
            $quality = $movie['quality'] ?? null;
            if (is_string($quality) && $quality !== '') {
                $qualities[$quality] = true;
            }
            foreach ((array) ($movie['languages'] ?? []) as $lang) {
                if (is_string($lang) && $lang !== '' && $lang !== '—') {
                    $languages[$lang] = true;
                }
            }
        }
        $g = array_keys($genres);
        $q = array_keys($qualities);
        $l = array_keys($languages);
        sort($g, SORT_NATURAL | SORT_FLAG_CASE);
        sort($q, SORT_NATURAL | SORT_FLAG_CASE);
        sort($l, SORT_NATURAL | SORT_FLAG_CASE);

        return [$g, $q, $l];
    }

    /**
     * @param array<string, mixed> $movie
     */
    private function matches(array $movie, MovieLibraryQuery $query): bool
    {
        if ($query->q !== '') {
            $needle = mb_strtolower($query->q);
            $hay = mb_strtolower((string) ($movie['title'] ?? '') . '|' . (string) ($movie['sortTitle'] ?? ''));
            if (!str_contains($hay, $needle)) {
                return false;
            }
        }

        $hasFile = (bool) ($movie['hasFile'] ?? false);
        $monitored = (bool) ($movie['monitored'] ?? false);

        switch ($query->status) {
            case 'monitored':
                if (!$monitored) return false;
                break;
            case 'downloaded':
                if (!$hasFile) return false;
                break;
            case 'missing':
                if ($hasFile || !$monitored) return false;
                break;
            case 'unmonitored':
                if ($monitored) return false;
                break;
        }

        if ($query->quality !== '' && (string) ($movie['quality'] ?? '') !== $query->quality) {
            return false;
        }

        if ($query->genre !== '') {
            $genres = (array) ($movie['genres'] ?? []);
            if (!in_array($query->genre, $genres, true)) {
                return false;
            }
        }

        if ($query->language !== '') {
            // Legacy parity: substring match across the joined language list.
            // A movie tagged "French, English" matches a filter of "rench".
            $langs = (array) ($movie['languages'] ?? []);
            $found = false;
            foreach ($langs as $lang) {
                if (is_string($lang) && str_contains($lang, $query->language)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
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
                'added-desc' => strcmp((string) ($b['added'] ?? ''), (string) ($a['added'] ?? '')),
                'size-desc'  => ((int) ($b['sizeOnDisk'] ?? 0)) <=> ((int) ($a['sizeOnDisk'] ?? 0)),
                'size-asc'   => ((int) ($a['sizeOnDisk'] ?? 0)) <=> ((int) ($b['sizeOnDisk'] ?? 0)),
                default      => 0,
            };
        });
    }

    /**
     * Sort key for title-asc / title-desc. Uses the raw `title` (lowercased)
     * rather than Radarr's `sortTitle`, because Radarr can strip leading
     * articles depending on its language config — and Joshua prefers them
     * kept ("The Batman" sits under T, not B). We fall back to `sortTitle`
     * only when `title` is missing, since `sortTitle` is normalized lowercase
     * by RadarrClient::normalizeMovie() either way.
     *
     * @param array<string, mixed> $movie
     */
    private function titleSortKey(array $movie): string
    {
        $title = (string) ($movie['title'] ?? '');
        if ($title === '') {
            return (string) ($movie['sortTitle'] ?? '');
        }

        return mb_strtolower($title);
    }
}
