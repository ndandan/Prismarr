<?php

namespace App\Controller;

use App\Controller\Concerns\ApiClientErrorTrait;
use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin-only Bazarr subtitle-management section. This task ships the shell +
 * the Wanted tab; Movies/Series/History are added in Task 6 under the same
 * route prefix so ServiceRouteGuardSubscriber's `app_bazarr_` rule covers
 * them too.
 *
 * Fails closed like every other media-client controller: an unreachable or
 * unconfigured Bazarr renders the shell with the error banner instead of a
 * 500, matching Tautulli/UniFi.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/bazarr', name: 'app_bazarr_')]
class BazarrController extends AbstractController
{
    use ApiClientErrorTrait;

    public function __construct(
        private readonly BazarrClient $bazarr,
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $error = false;
        $wantedMovies = [];
        $wantedEpisodes = [];
        $counts = ['movies' => 0, 'episodes' => 0, 'providers' => 0];

        try {
            if (!$this->bazarr->ping()) {
                $error = true;
            } else {
                $counts = $this->bazarr->getBadgeCounts();
                $wantedMovies = $this->bazarr->getWantedMovies();
                $wantedEpisodes = $this->bazarr->getWantedEpisodes();
            }
        } catch (\Throwable $e) {
            $error = true;
            $this->logger->warning('Bazarr index failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return $this->render('bazarr/index.html.twig', [
            'active_tab'      => 'wanted',
            'error'           => $error,
            'counts'          => $counts,
            'wanted_movies'   => $wantedMovies,
            'wanted_episodes' => $wantedEpisodes,
            'service_url'     => $this->config->get('bazarr_url'),
        ]);
    }
}
