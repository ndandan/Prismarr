<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 *
 * PRISMARR_TRUSTED_ORIGINS (comma-separated hosts or origins) extends that
 * Origin fallback for a reverse proxy that rewrites Host: over plain http
 * browsers send no Sec-Fetch-Site, so a proxy forwarding Host=127.0.0.1:7070
 * for a page on http://prismarr.lan would otherwise lock every POST out —
 * login included. It never overrides what Sec-Fetch-Site reports.
 */
class CrossSiteRequestGuardSubscriber implements EventSubscriberInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /** @var list<string> normalized host[:port] entries */
    private readonly array $trustedHosts;

    public function __construct(
        private readonly LoggerInterface $logger,
        // Nullable: `default::` yields null (not a PHP default) when unset.
        #[Autowire('%env(default::PRISMARR_TRUSTED_ORIGINS)%')]
        ?string $trustedOrigins = null,
    ) {
        $hosts = [];
        foreach (explode(',', (string) $trustedOrigins) as $entry) {
            $host = self::hostOf(trim($entry));
            if ($host !== null) {
                $hosts[] = $host;
            }
        }
        $this->trustedHosts = $hosts;
    }

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
        $reason  = self::blockReason($request, $this->trustedHosts);
        if ($reason === null) {
            return;
        }

        $this->logger->warning('Cross-site request blocked', [
            'method' => $request->getMethod(),
            'path'   => $request->getPathInfo(),
            'reason' => $reason,
            // The host the app saw: a mismatch with the Origin above usually
            // means a proxy rewrites Host — see PRISMARR_TRUSTED_ORIGINS.
            'host'   => $request->getHttpHost(),
        ]);
        $event->setResponse(new JsonResponse(['ok' => false, 'error' => 'cross-site request blocked'], 403));
    }

    /**
     * Why $request must be refused, or null when it may proceed.
     *
     * @param list<string> $trustedHosts extra host[:port] values accepted by the Origin fallback
     */
    public static function blockReason(Request $request, array $trustedHosts = []): ?string
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

        $originHost = self::hostOf($origin);
        if ($originHost === null) {
            return 'unparsable origin';
        }
        if ($originHost === strtolower($request->getHttpHost()) || in_array($originHost, $trustedHosts, true)) {
            return null;
        }

        return 'origin: ' . $originHost;
    }

    /** host[:port], lowercased, from an origin ("https://h:8443") or a bare host ("h:8443"). */
    private static function hostOf(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $parts = parse_url(str_contains($value, '://') ? $value : 'http://' . $value);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        return strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
}
