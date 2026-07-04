<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Client for the Houndarr widget API (GET <url>/api/v1/widget, `X-Api-Key`
 * header). Houndarr's API key authorizes exactly that one read-only summary
 * endpoint — there is no richer surface, so the whole integration is the
 * dashboard stat tile + health chip (no dedicated page). Optional service —
 * unconfigured (or kill switch off) returns null without exception.
 *
 * widget() shape:
 *  [
 *    'totals' => ?['tracked' => int, 'eligible' => int, 'gated' => int,
 *                  'unreleased' => int, 'searches7d' => int],  // null on error='auth'
 *    'generatedAtEpoch' => ?int,
 *    'error' => ?string ('auth' — bad/revoked key; null otherwise),
 *  ]
 *  null → unconfigured, disabled, or unreachable (transport / non-2xx).
 */
class HoundarrClient implements ResetInterface
{
    public const SERVICE = 'houndarr';

    private bool   $configLoaded = false;
    private bool   $enabled = true;
    private string $baseUrl = '';
    private string $apiKey = '';

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Allow-list normalization of the raw widget payload: the five documented
     * totals cast to int and clamped >= 0, generated_at parsed to an epoch,
     * everything else dropped. Tolerant of unknown `schema` values on purpose
     * (read totals if present) — a v2 bump shouldn't blank the tile.
     */
    public static function normalizeWidget(array $raw): array
    {
        $t = is_array($raw['totals'] ?? null) ? $raw['totals'] : [];
        $int = static fn(mixed $v): int => max(0, (int) (is_numeric($v) ? $v : 0));
        $epoch = isset($raw['generated_at']) ? strtotime((string) $raw['generated_at']) : false;

        return [
            'totals' => [
                'tracked'    => $int($t['tracked'] ?? 0),
                'eligible'   => $int($t['eligible'] ?? 0),
                'gated'      => $int($t['gated'] ?? 0),
                'unreleased' => $int($t['unreleased'] ?? 0),
                'searches7d' => $int($t['searches_7d'] ?? 0),
            ],
            'generatedAtEpoch' => $epoch !== false ? $epoch : null,
            'error' => null,
        ];
    }

    public function reset(): void
    {
        $this->configLoaded = false;
        $this->enabled = true;
        $this->baseUrl = '';
        $this->apiKey = '';
    }
}
