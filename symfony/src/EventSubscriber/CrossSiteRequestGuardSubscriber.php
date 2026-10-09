<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses state-changing requests that a browser sent from another origin.
 *
 * Most JSON POST endpoints authenticate the session but carry no CSRF token
 * (the "same-origin fetch" convention), and the session cookie is only
 * SameSite=Lax. Lax stops cross-SITE posts, but a page on another port of the
 * same host (Sonarr on :8989, Bazarr on :6767 …) or on a sibling subdomain
 * behind a tunnel (sonarr.example.com next to prismarr.example.com) is
 * "same-site": the cookie rides along and the request runs as the logged-in
 * admin. One guard here covers every unsafe route at once.
 *
 * The decision uses Sec-Fetch-Site, which the browser computes itself — so
 * it is immune to reverse-proxy scheme/host rewriting (a TLS-terminating
 * tunnel makes the app see http:// while the page is https://). Only when a
 * browser omits it (older engines) does it fall back to Origin, compared by
 * host[:port] and never by scheme. A request carrying neither header is not
 * a browser cross-site request (curl, scripts, server-to-server) and passes:
 * CSRF needs a victim's browser, and browsers send Origin on every POST.
 */
class CrossSiteRequestGuardSubscriber implements EventSubscriberInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(private readonly LoggerInterface $logger) {}

    public static function getSubscribedEvents(): array
    {
        // Ahead of routing, the firewall and every controller.
        return [KernelEvents::REQUEST => ['onKernelRequest', 256]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $reason  = self::blockReason($request);
        if ($reason === null) {
            return;
        }

        $this->logger->warning('Cross-site request blocked', [
            'method' => $request->getMethod(),
            'path'   => $request->getPathInfo(),
            'reason' => $reason,
        ]);
        $event->setResponse(new JsonResponse(['ok' => false, 'error' => 'cross-site request blocked'], 403));
    }

    /** Why $request must be refused, or null when it may proceed. */
    public static function blockReason(Request $request): ?string
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return null;
        }

        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && $site !== '') {
            // same-origin: our own pages. none: the user acted directly.
            return in_array(strtolower($site), ['same-origin', 'none'], true) ? null : 'sec-fetch-site: ' . $site;
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return null;
        }
        if (strtolower($origin) === 'null') {
            return 'opaque origin';
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return 'unparsable origin';
        }
        $originHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));

        return $originHost === strtolower($request->getHttpHost()) ? null : 'origin: ' . $originHost;
    }
}
