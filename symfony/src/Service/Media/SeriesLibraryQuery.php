<?php

namespace App\Service\Media;

use Symfony\Component\HttpFoundation\Request;

/**
 * Read-only criteria for filtering / sorting / paginating the Sonarr series
 * library server-side. The Sonarr UI offers a different mix than Radarr:
 * 7 status filters (incl. anime + missing-episodes) and network instead of
 * quality / language.
 */
final class SeriesLibraryQuery
{
    /** Status filters mirror the legacy client-side JS for behavior parity. */
    public const STATUSES = ['all', 'monitored', 'continuing', 'ended', 'anime', 'unmonitored', 'missing'];

    public const SORTS = ['title-asc', 'title-desc', 'year-desc', 'year-asc', 'added-desc', 'size-desc', 'size-asc', 'percent-desc', 'percent-asc'];

    public const ALLOWED_PER_PAGE = [50, 100, 200, 500];

    public function __construct(
        public readonly string $q = '',
        public readonly string $status = 'all',
        public readonly string $genre = '',
        public readonly string $network = '',
        public readonly string $sort = 'title-asc',
        public readonly int $page = 1,
        public readonly int $perPage = 200,
        public readonly bool $unlimited = false,
    ) {}

    public static function fromRequest(Request $request, int $defaultPerPage = 200): self
    {
        $status = (string) $request->query->get('status', 'all');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'all';
        }

        // Backwards compat with v1.0 bookmarks that used ?filter= instead of
        // ?status=. The legacy param is honored only when ?status= isn't
        // explicitly set, so the new param always wins when both are present.
        if ($status === 'all' && !$request->query->has('status') && $request->query->has('filter')) {
            $legacy = (string) $request->query->get('filter');
            if (in_array($legacy, self::STATUSES, true)) {
                $status = $legacy;
            }
        }

        $sort = (string) $request->query->get('sort', 'title-asc');
        if (!in_array($sort, self::SORTS, true)) {
            $sort = 'title-asc';
        }

        $perPage = (int) $request->query->get('per_page', $defaultPerPage);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = in_array($defaultPerPage, self::ALLOWED_PER_PAGE, true) ? $defaultPerPage : 200;
        }

        $page = (int) $request->query->get('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        return new self(
            q: trim((string) $request->query->get('q', '')),
            status: $status,
            genre: trim((string) $request->query->get('genre', '')),
            network: trim((string) $request->query->get('network', '')),
            sort: $sort,
            page: $page,
            perPage: $perPage,
            unlimited: $request->query->getBoolean('unlimited'),
        );
    }

    public function hasActiveFilter(): bool
    {
        return $this->status !== 'all'
            || $this->genre !== ''
            || $this->network !== '';
    }

    public function withoutPagination(): self
    {
        return new self(
            q: $this->q,
            status: $this->status,
            genre: $this->genre,
            network: $this->network,
            sort: $this->sort,
            page: 1,
            perPage: $this->perPage,
            unlimited: true,
        );
    }

    /**
     * @return array<string, string|int>
     */
    public function toQueryArray(): array
    {
        $out = [];
        if ($this->q !== '')        $out['q'] = $this->q;
        if ($this->status !== 'all') $out['status'] = $this->status;
        if ($this->genre !== '')    $out['genre'] = $this->genre;
        if ($this->network !== '')  $out['network'] = $this->network;
        if ($this->sort !== 'title-asc') $out['sort'] = $this->sort;
        if ($this->perPage !== 200) $out['per_page'] = $this->perPage;

        return $out;
    }
}
