<?php

namespace App\Controller;

use App\Service\Media\TautulliClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only internal API for the "Current Plex activity" dashboard widget,
 * backed by the Tautulli API integration (TautulliClient::getActivity()).
 *
 * Every Tautulli call is made server-side: the API key never reaches the
 * browser, and the JSON returned here is the already-sanitized, normalized
 * shape (no IPs, tokens, machine ids, file paths or raw payload). The endpoint
 * always answers 200 with an `error` code in the body for the disabled /
 * unconfigured / unreachable / auth cases, so the widget never breaks the page.
 *
 * Only `get_activity` is exposed — no terminate_session, history, notifications
 * or any other mutating Tautulli command.
 */
#[IsGranted('ROLE_USER')]
#[Route('/tautulli', name: 'app_tautulli_')]
class TautulliController extends AbstractController
{
    public function __construct(
        private readonly TautulliClient $tautulli,
    ) {}

    /**
     * GET /tautulli/api/activity — sanitized current Plex activity as JSON.
     * Mirrors the qBittorrent/Jellyseerr poll-endpoint convention
     * (/{service}/api/...). Fails open: a thrown client returns the neutral
     * shape with error:"unreachable" rather than a 500 + stack trace.
     */
    #[Route('/api/activity', name: 'api_activity', methods: ['GET'])]
    public function apiActivity(): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getActivity());
        } catch (\Throwable) {
            // Defensive: getActivity() already fails open, but never let an
            // unexpected throwable leak a message/secret to the browser.
            return $this->json([
                'enabled'    => true,
                'configured' => true,
                'connected'  => false,
                'error'      => 'unreachable',
                'streamCount'       => 0,
                'directPlayCount'   => 0,
                'directStreamCount' => 0,
                'transcodeCount'    => 0,
                'bandwidth'         => [
                    'totalKbps' => 0, 'lanKbps' => 0, 'wanKbps' => 0,
                    'totalMbps' => 0.0, 'lanMbps' => 0.0, 'wanMbps' => 0.0,
                ],
                'sessions'          => [],
            ]);
        }
    }
}
