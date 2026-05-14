<?php

namespace App\Service\Media;

/**
 * Output of MovieLibraryFilter::apply() — exposes the page of items the
 * controller hands to Twig plus the facets needed to render the filter
 * dropdowns (computed on the unfiltered library so options never become
 * empty as the user narrows the view).
 */
final class MovieLibraryResult
{
    public function __construct(
        /** @var array<int, array<string, mixed>> The page of movies to render. */
        public readonly array $items,
        /** Count after filtering, before pagination. */
        public readonly int $total,
        /** Count of the unfiltered library — drives the stats cards. */
        public readonly int $libraryTotal,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly bool $unlimited,
        /** @var list<string> Sorted unique genres across the full library. */
        public readonly array $genres,
        /** @var list<string> Sorted unique quality names. */
        public readonly array $qualities,
        /** @var list<string> Sorted unique language names. */
        public readonly array $languages,
    ) {}
}
