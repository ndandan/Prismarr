<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\Usenet\NzbgetClient;
use App\Service\Media\Usenet\SabnzbdClient;
use App\Service\Media\Usenet\UsenetClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Usenet downloads page — one route family per client (sabnzbd | nzbget),
 * mirroring the qBittorrent page but for Usenet semantics (no seeding / ratio
 * / trackers). The {client} slug picks the right UsenetClientInterface; an
 * unknown or disabled client bounces home / 403 like qBittorrent (#15).
 *
 * Phase 2a — read only: the page shell + the JSON queue/history feed. Actions
 * (pause, resume, delete, speed limit, add NZB) land in the next slice.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/usenet/{client}', name: 'app_usenet_', requirements: ['client' => 'sabnzbd|nzbget'])]
class UsenetController extends AbstractController
{
    public function __construct(
        private readonly SabnzbdClient $sabnzbd,
        private readonly NzbgetClient $nzbget,
        private readonly HealthService $health,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    private function client(string $kind): UsenetClientInterface
    {
        return $kind === 'nzbget' ? $this->nzbget : $this->sabnzbd;
    }

    #[Route('', name: 'index')]
    public function index(string $client): Response
    {
        $label = $client === 'nzbget' ? 'NZBGet' : 'SABnzbd';
        if (!$this->health->isConfigured($client)) {
            $this->addFlash('warning', $this->translator->trans('usenet.disabled_notice', [
                'client' => $label,
            ]));
            return $this->redirectToRoute('app_home');
        }

        // Probe at render time so a configured-but-unreachable client shows an
        // explicit banner (like qBittorrent) instead of a silent empty page.
        // Use HealthService::diagnose() rather than the client's getVersion():
        // SABnzbd answers mode=version with 200 for ANY key, so getVersion()
        // would sail past a wrong API key and leave the page silently empty.
        // diagnose() probes mode=queue, which actually validates the key, and
        // tells a bad key (auth) apart from a host_whitelist block.
        $reason = null;
        try {
            $diag = $this->health->diagnose($client);
            if (!($diag['ok'] ?? false)) {
                $reason = match ($diag['category'] ?? '') {
                    'auth'           => 'auth',
                    'host_whitelist' => 'host_whitelist',
                    default          => 'unreachable',
                };
            }
        } catch (\Throwable $e) {
            $reason = 'unreachable';
            $this->logger->warning('Usenet index probe crashed', [
                'client'    => $client,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
        if ($reason !== null) {
            $this->logger->warning('Usenet {client} not OK on page render', [
                'client' => $client,
                'reason' => $reason,
            ]);
        }

        return $this->render('usenet/index.html.twig', [
            'client'       => $client,
            'client_label' => $label,
            'error'        => $reason !== null,
            'error_reason' => $reason ?? 'unreachable',
            'service_url'  => $this->config->get($client . '_url'),
        ]);
    }

    /**
     * JSON feed for the async page: the normalized queue snapshot plus the
     * recent history. Filtering / sorting is client-side, like qBittorrent.
     */
    #[Route('/api/queue', name: 'api_queue', methods: ['GET'])]
    public function apiQueue(string $client): JsonResponse
    {
        if (!$this->health->isConfigured($client)) {
            $label = $client === 'nzbget' ? 'NZBGet' : 'SABnzbd';
            return $this->json(['error' => $this->translator->trans('usenet.disabled_notice', [
                'client' => $label,
            ])], 403);
        }

        try {
            $c = $this->client($client);
            $queue   = $c->getQueue();
            $history = $c->getHistory(50);

            return $this->json([
                'paused'      => $queue->paused,
                'speed'       => $queue->speedBytes,
                'speed_limit' => $queue->speedLimitBytes,
                'remaining'   => $queue->remainingBytes,
                'active'      => $queue->activeCount,
                'queued'      => $queue->queuedCount,
                'eta'         => $queue->etaSeconds,
                'free_space'  => $queue->freeSpaceBytes,
                'items'       => array_map([$this, 'serializeItem'], $queue->items),
                'history'     => array_map([$this, 'serializeItem'], $history),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Usenet apiQueue failed', [
                'client'    => $client,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return $this->json(['error' => $this->translator->trans('usenet.api.unreachable')], 502);
        }
    }

    /**
     * Lightweight feed for the sidebar badge poll — only the active count, so
     * the badge stays cheap to refresh.
     */
    #[Route('/api/poll-summary', name: 'api_poll_summary', methods: ['GET'])]
    public function apiPollSummary(string $client): JsonResponse
    {
        if (!$this->health->isConfigured($client)) {
            return $this->json(['error' => 'disabled'], 403);
        }
        try {
            $queue = $this->client($client)->getQueue();
            return $this->json(['active' => $queue->activeCount]);
        } catch (\Throwable $e) {
            $this->logger->warning('Usenet poll-summary failed', [
                'client'    => $client,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return $this->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(\App\Service\Media\Usenet\UsenetDownload $d): array
    {
        return [
            'id'          => $d->id,
            'name'        => $d->name,
            'status'      => $d->status,
            'raw_status'  => $d->rawStatus,
            'size'        => $d->sizeBytes,
            'remaining'   => $d->remainingBytes,
            'percentage'  => $d->percentage,
            'category'    => $d->category,
            'eta'         => $d->etaSeconds,
            'speed'       => $d->speedBytes,
            'fail'        => $d->failMessage,
            'history'     => $d->isHistory,
        ];
    }
}
