<?php

namespace App\Service\Wokeometer;

use App\Repository\Media\WokeometerTitleRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read-only, local-only Wokeometer lookup for the detail views (quick-look,
 * Films/Series modals, Discover). Reads the SQLite mirror — NEVER calls the
 * Wokeometer API (a miss is simply "no data").
 *
 * Fail-closed: returns null when the integration is disabled/unconfigured
 * (without touching the repository) and on any DB error. The first error of
 * a request logs ONE warning and short-circuits every later call of that
 * request to null (no retry storm against a broken DB). Per-request memo
 * keyed `type:id` (misses memoised too); reset() clears memo + error flag
 * between FrankenPHP worker requests.
 *
 * View shape: {score: ?int, tldr: ?string, url: ?string, analyzed: ?bool,
 * title: string, updatedAt: int}. `url` only for a safe slug. A row with
 * neither a score nor a TL;DR has no content to show and yields null (a
 * bare "View full analysis" link would promise an analysis that is not
 * there) — every surface then renders nothing at all.
 *
 * @phpstan-type WokeometerView array{score: ?int, tldr: ?string, url: ?string, analyzed: ?bool, title: string, updatedAt: int}
 */
class WokeometerLookup implements ResetInterface
{
    /** @var array<string, WokeometerView|null> */
    private array $memo = [];

    private bool $failed = false;

    public function __construct(
        private readonly WokeometerSettings $settings,
        private readonly WokeometerTitleRepository $titles,
        private readonly LoggerInterface $logger,
    ) {}

    public function reset(): void
    {
        $this->memo   = [];
        $this->failed = false;
    }

    /**
     * @param string $mediaType 'movie' | 'tv' (TMDb vocabulary)
     * @return WokeometerView|null
     */
    public function forTmdb(string $mediaType, int $tmdbId): ?array
    {
        if (!$this->validType($mediaType) || $tmdbId <= 0 || $this->failed) {
            return null;
        }

        $key = $mediaType . ':' . $tmdbId;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        try {
            if (!$this->settings->isEnabled()) {
                return null;
            }
            $row = $this->titles->findForTmdb($mediaType, $tmdbId);
        } catch (\Throwable $e) {
            $this->fail($e);
            return null;
        }

        return $this->memo[$key] = $row === null ? null : $this->view($row);
    }

    /**
     * Bulk variant: one chunked repository read for the ids not memoised yet.
     *
     * @param list<int> $ids
     * @return array<int, WokeometerView> matched ids only, keyed by tmdb id (input order)
     */
    public function forTmdbMany(string $mediaType, array $ids): array
    {
        if (!$this->validType($mediaType) || $this->failed) {
            return [];
        }

        $wanted = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $wanted[$id] = $id;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $missing = [];
        foreach ($wanted as $id) {
            if (!array_key_exists($mediaType . ':' . $id, $this->memo)) {
                $missing[] = $id;
            }
        }

        try {
            if (!$this->settings->isEnabled()) {
                return [];
            }
            if ($missing !== []) {
                $rows = $this->titles->findForTmdbMany($mediaType, $missing);
                foreach ($missing as $id) {
                    $this->memo[$mediaType . ':' . $id] = isset($rows[$id]) ? $this->view($rows[$id]) : null;
                }
            }
        } catch (\Throwable $e) {
            $this->fail($e);
            return [];
        }

        $out = [];
        foreach ($wanted as $id) {
            $view = $this->memo[$mediaType . ':' . $id] ?? null;
            if ($view !== null) {
                $out[$id] = $view;
            }
        }
        return $out;
    }

    private function validType(string $mediaType): bool
    {
        return $mediaType === 'movie' || $mediaType === 'tv';
    }

    /**
     * @param array<string, mixed> $row repository row (snake_case columns)
     * @return WokeometerView|null null when there is neither a score nor a TL;DR
     */
    private function view(array $row): ?array
    {
        $score    = $row['woke_score'] ?? null;
        $tldr     = $row['tldr'] ?? null;
        $slug     = $row['slug'] ?? null;
        $analyzed = $row['is_analyzed'] ?? null;
        $type     = $row['media_type'] ?? '';

        $score = is_int($score) ? $score : null;
        $tldr  = is_string($tldr) ? self::plainText($tldr) : null;
        if ($score === null && $tldr === null) {
            return null;
        }

        return [
            'score'     => $score,
            'tldr'      => $tldr,
            'url'       => $this->settings->publicUrl(is_string($type) ? $type : '', is_string($slug) ? $slug : null),
            'analyzed'  => is_bool($analyzed) ? $analyzed : null,
            'title'     => (string) ($row['title'] ?? ''),
            'updatedAt' => (int) ($row['wokeometer_updated_at'] ?? 0),
        ];
    }

    /**
     * Display-side only (the stored TL;DR stays raw): Wokeometer's TL;DR
     * arrives as Markdown and we render plain text, so strip line-leading
     * block markers (blockquote `>`, `#` headings, `-`/`*`/`+`/`1.` list
     * bullets), links and images (`[text](url)` → text), inline-code
     * backticks, then `**strong**` / `__strong__` and single `*em*` / `_em_`
     * delimiters that wrap words (an underscore inside a word,
     * `snake_case_name`, is left alone). Markers are only recognised at the
     * start of a line, so mid-sentence `#1`, `5 > 4` or ` - ` survive.
     * Collapse line breaks to one space and trim. Empty → null.
     */
    private static function plainText(string $text): ?string
    {
        $out = preg_replace('/^[ \t]{0,3}(?:>[ \t]*)+/mu', '', $text) ?? $text;
        $out = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]+/mu', '', $out) ?? $out;
        $out = preg_replace('/^[ \t]*(?:[-*+]|\d{1,3}[.)])[ \t]+/mu', '', $out) ?? $out;
        $out = preg_replace('/!?\[([^\]]*)\]\([^)\s]*(?:[ \t]+"[^"]*")?\)/u', '$1', $out) ?? $out;
        $out = preg_replace('/`+([^`]+)`+/u', '$1', $out) ?? $out;
        $out = preg_replace('/(\*\*|__)(.+?)\1/su', '$2', $out) ?? $out;
        $out = preg_replace('/(?<![\w])[*_](\S(?:.*?\S)?)[*_](?![\w])/su', '$1', $out) ?? $out;
        $out = preg_replace('/\s*[\r\n]+\s*/u', ' ', $out) ?? $out;
        $out = trim($out);

        return $out !== '' ? $out : null;
    }

    private function fail(\Throwable $e): void
    {
        if (!$this->failed) {
            $this->logger->warning('Wokeometer local lookup failed; hiding Wokeometer data for this request', [
                'error_class' => $e::class,
            ]);
        }
        $this->failed = true;
    }
}
