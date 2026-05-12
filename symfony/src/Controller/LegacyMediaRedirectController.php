<?php

namespace App\Controller;

use App\Entity\ServiceInstance;
use App\Service\ServiceInstanceProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Keeps every pre-1.1.0 media URL working after the multi-instance move.
 *
 * In v1.0.x the Radarr/Sonarr pages lived at `/medias/films`, `/medias/series`,
 * `/medias/radarr/...` and `/medias/sonarr/...`. v1.1.0 prefixed them with an
 * instance slug (`/medias/{slug}/films`, …). Bookmarks and any browser still
 * showing a cached v1.0.x page (whose JS polls the old AJAX routes) would
 * otherwise 404 after `docker compose pull`. Each old path 307-redirects to the
 * default instance's new path, method preserved so cached POST handlers (queue
 * delete/grab, …) keep working too. 307 (not 308) so the redirect isn't cached
 * permanently — the target moves if the user renames the default instance.
 *
 * No default instance configured for that type → bounce to the home page,
 * which has its own "first usable service" fallback chain.
 */
final class LegacyMediaRedirectController extends AbstractController
{
    private const TYPE_BY_SEGMENT = [
        'films'  => ServiceInstance::TYPE_RADARR,
        'radarr' => ServiceInstance::TYPE_RADARR,
        'series' => ServiceInstance::TYPE_SONARR,
        'sonarr' => ServiceInstance::TYPE_SONARR,
    ];

    #[Route(
        '/medias/{section}/{rest}',
        name: 'legacy_media_redirect',
        requirements: ['section' => 'films|series|radarr|sonarr', 'rest' => '.*'],
        defaults: ['rest' => ''],
        // Pure fallback: a real route always wins first, so an instance whose
        // slug happens to be "films"/"radarr"/… still routes to MediaController.
        priority: -100,
    )]
    public function redirectLegacy(string $section, string $rest, ServiceInstanceProvider $instances): Response
    {
        $type = self::TYPE_BY_SEGMENT[$section];
        $default = $instances->getDefault($type);
        if ($default === null) {
            return $this->redirectToRoute('app_home');
        }

        // films/series → /medias/{slug}/films ; radarr/sonarr → /medias/{slug}/radarr
        $base = '/medias/' . $default->getSlug() . '/' . $section;
        $target = $rest !== '' ? $base . '/' . ltrim($rest, '/') : $base;

        return new RedirectResponse($target, Response::HTTP_TEMPORARY_REDIRECT);
    }
}
