<?php

namespace App\Service\Media;

/**
 * Output of SeriesLibraryFilter::apply(). Mirrors MovieLibraryResult except
 * for facet names: series surface `networks` instead of qualities/languages.
 */
final class SeriesLibraryResult
{
    public function __construct(
        /** @var array<int, array<string, mixed>> */
        public readonly array $items,
        public readonly int $total,
        public readonly int $libraryTotal,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly bool $unlimited,
        /** @var list<string> */
        public readonly array $genres,
        /** @var list<string> */
        public readonly array $networks,
    ) {}
}
