<?php

namespace App\Controller;

use App\Service\Media\TautulliClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    /**
     * GET /tautulli/api/image?img=/library/metadata/123/thumb/456 — streams a
     * Plex poster fetched server-side via Tautulli's pms_image_proxy. The API
     * key stays on the server; the browser only ever sees the image bytes.
     *
     * The `img` value is allow-listed to Plex library image paths inside
     * TautulliClient::fetchImage (SSRF / open-relay guard). A miss returns 404
     * so the widget's CSS placeholder shows through rather than a broken image.
     * The response is privately cacheable for a day — the poster URL is stable
     * per session, so the browser reuses it across the widget's 10s refreshes.
     */
    #[Route('/api/image', name: 'api_image', methods: ['GET'])]
    public function apiImage(Request $request): Response
    {
        $img = (string) $request->query->get('img', '');
        $image = $img !== '' ? $this->tautulli->fetchImage($img) : null;
        if ($image === null) {
            throw $this->createNotFoundException();
        }

        $response = new Response($image['body']);
        $response->headers->set('Content-Type', $image['contentType']);
        $response->setMaxAge(86400);
        $response->setPrivate();

        return $response;
    }

    /**
     * GET /tautulli/api/metadata/{ratingKey} — renders the info-modal body for a
     * Plex item. ratingKey is digits-only (route requirement). Optional
     * player/device query params are display-only (live now-playing line) and
     * are Twig-escaped. Fails open: the template renders a clean error state.
     */
    #[Route('/api/metadata/{ratingKey}', name: 'api_metadata', methods: ['GET'], requirements: ['ratingKey' => '\d+'])]
    public function apiMetadata(string $ratingKey, Request $request): Response
    {
        try {
            $data = $this->tautulli->getMetadata($ratingKey);
        } catch (\Throwable) {
            $data = ['enabled' => true, 'configured' => true, 'connected' => false, 'error' => 'unreachable', 'metadata' => []];
        }

        return $this->render('dashboard/_plex_metadata.html.twig', [
            'plex'   => $data,
            'player' => trim((string) $request->query->get('player', '')),
            'device' => trim((string) $request->query->get('device', '')),
        ]);
    }
}
