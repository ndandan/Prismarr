<?php

namespace App\Controller;

use App\Service\Wokeometer\WokeometerLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Local, read-only Wokeometer lookup for the Films/Series modals, fetched on
 * open. Reads the SQLite mirror only — this path never calls the Wokeometer
 * API (a miss is simply "no data"). Authenticated via the firewall's
 * catch-all `^/ ROLE_USER` access rule; visible to every logged-in user.
 *
 * Contract (fixed): always HTTP 200 `{"ok": true, "match": null | {...}}`,
 * fail-closed — any error, a disabled integration and a miss all look the
 * same to the caller: `match: null`. Never echoes an exception message.
 */
class WokeometerController extends AbstractController
{
    public function __construct(
        private readonly WokeometerLookup $lookup,
    ) {}

    #[Route(
        '/wokeometer/api/lookup/{type}/{tmdbId}',
        name: 'app_wokeometer_api_lookup',
        requirements: ['type' => 'movie|tv', 'tmdbId' => '\d+'],
        methods: ['GET'],
    )]
    public function lookup(string $type, string $tmdbId): JsonResponse
    {
        try {
            $match = $this->lookup->forTmdb($type, (int) $tmdbId);
        } catch (\Throwable) {
            $match = null;
        }

        $response = $this->json(['ok' => true, 'match' => $match]);
        $response->headers->set('Cache-Control', 'private, max-age=60');

        return $response;
    }
}
