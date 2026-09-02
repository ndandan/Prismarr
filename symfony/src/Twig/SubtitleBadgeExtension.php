<?php

namespace App\Twig;

use App\Service\Media\BazarrSubtitleIndex;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `BazarrSubtitleIndex` lookups to templates so grid/list/quick-look
 * partials can render a per-item subtitle-status badge without each caller
 * needing to know about Bazarr. Usage:
 *
 *   {% set st = subtitle_status('movie', item.radarrId) %}
 *   {% set st = subtitle_status('series', item.sonarrSeriesId) %}
 *
 * `kind` outside `movie|series` (and any future kind that hasn't shipped a
 * lookup yet) resolves to the same `hidden` shape `BazarrSubtitleIndex`
 * itself returns for an unconfigured/absent item, so `_subtitle_badge.html.twig`
 * has a single "render nothing" branch to worry about.
 *
 * @phpstan-import-type SubtitleStatus from BazarrSubtitleIndex
 */
class SubtitleBadgeExtension extends AbstractExtension
{
    public function __construct(private readonly BazarrSubtitleIndex $index) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('subtitle_status', [$this, 'status']),
            new TwigFunction('subtitle_status_single', [$this, 'statusSingle']),
        ];
    }

    /** @return SubtitleStatus */
    public function status(string $kind, int $id): array
    {
        return match ($kind) {
            'movie'  => $this->index->movieStatus($id),
            'series' => $this->index->seriesStatus($id),
            default  => ['state' => 'hidden', 'count' => 0],
        };
    }

    /**
     * Single-item variant: falls back to ONE per-id Bazarr call when the
     * shared index is cold. Only for surfaces that render exactly one badge —
     * see BazarrSubtitleIndex::movieStatusSingle(). The series side has no
     * bulk-map problem (seriesStatus() is a small map and apiSubtitlesSeries()
     * already does a true per-id getEpisodes() call), so it falls through to
     * the same seriesStatus() the bulk function uses.
     *
     * @return SubtitleStatus
     */
    public function statusSingle(string $kind, int $id): array
    {
        return match ($kind) {
            'movie'  => $this->index->movieStatusSingle($id),
            'series' => $this->index->seriesStatus($id),
            default  => ['state' => 'hidden', 'count' => 0],
        };
    }
}
