<?php

namespace App\EventSubscriber;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\CspNonceGenerator;
use App\Service\ServiceInstanceProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the Content-Security-Policy + X-Frame-Options headers on every
 * HTML response. Both CSP headers are skipped on non-HTML responses (JSON
 * API, binary): a CSP only governs document contexts, and since the nonce
 * lives in a session attribute, computing one for a JSON response would
 * start a session on cookieless API traffic — the Docker healthcheck alone
 * polls /api/health every 30s. X-Frame-Options is still set on every
 * response: it is not a CSP header and needs no nonce, so gating it would
 * drop a security header for no benefit.
 * (X-Frame-Options used to be a static Caddy header — it
 * moved here so the iframe-embedding opt-in below can control both in one
 * place; static assets served straight by Caddy no longer carry it, which
 * is harmless since clickjacking needs an interactive HTML page.)
 *
 * img-src is built dynamically from the configured service URLs
 * (Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent, Gluetun) so
 * that self-hosters on arbitrary IPs/ports see their service-hosted
 * images (e.g. Jellyseerr /avatarproxy/*) load correctly.
 *
 * script-src / connect-src stay strict — that is where the real
 * XSS/exfiltration protection lives. frame-ancestors is 'self' by default;
 * set PRISMARR_FRAME_ANCESTORS to a space-separated origin list (e.g.
 * "https://organizr.example.com") to allow embedding Prismarr in an iframe
 * there (issue #25). When that env is set X-Frame-Options is dropped, since
 * its only "allow" value (ALLOW-FROM) is ignored by modern browsers anyway.
 *
 * v1.1.0 — radarr/sonarr origins are aggregated across every enabled
 * instance (a multi-instance install with a 1080p + 4K Radarr needs
 * both whitelisted), the other services still use their flat setting.
 */
final class CspHeaderSubscriber implements EventSubscriberInterface
{
    /** Services still on flat settings (radarr/sonarr migrated to service_instance). */
    private const SERVICE_URL_KEYS = [
        'prowlarr_url',
        'jellyseerr_url',
        'qbittorrent_url',
        'gluetun_url',
    ];

    private const STATIC_IMG_HOSTS = [
        'https://image.tmdb.org',
        'https://ui-avatars.com',
        'https://artworks.thetvdb.com',
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly ServiceInstanceProvider $instances,
        private readonly CspNonceGenerator $nonce,
        // `default::VAR` yields null (not '') when the env is unset, so accept
        // ?string and coalesce in onResponse().
        #[Autowire(env: 'default::PRISMARR_FRAME_ANCESTORS')]
        private readonly ?string $frameAncestors = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // Strip control chars (CR/LF would smuggle a second header), `;`
        // (would close `frame-ancestors` and let a typo smuggle another CSP
        // directive) and `,` (browsers split comma-separated CSPs into
        // multiple policies and *intersect* them — a stray comma can't
        // weaken the policy but silently breaks the app for the admin who
        // typo'd it, and it's never legitimate in a value).
        $extraAncestors = trim(preg_replace('/[\x00-\x1F\x7F;,]/', '', $this->frameAncestors ?? '') ?? '');

        if ($extraAncestors === '') {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        } else {
            // ALLOW-FROM is dead in modern browsers, so the only way to allow
            // a foreign embedder is to drop this header and lean on CSP.
            $response->headers->remove('X-Frame-Options');
        }

        // CSP only governs document contexts, so a JSON/binary response gains
        // nothing from either header. Skipping them is also what keeps the
        // nonce unread on those responses: the nonce lives in a session
        // attribute, and reading it starts a session — so the cookieless
        // Docker healthcheck polling /api/health every 30s would otherwise
        // leave one orphan session per poll in var/data/sessions.
        if (!$this->governsADocument($response)) {
            return;
        }

        if ($response->headers->has('Content-Security-Policy')) {
            return;
        }

        $frameAncestors = "'self'" . ($extraAncestors !== '' ? ' ' . $extraAncestors : '');

        $imgHosts = self::STATIC_IMG_HOSTS;
        foreach (self::SERVICE_URL_KEYS as $key) {
            $url = $this->config->get($key);
            if (!$url) {
                continue;
            }
            $origin = $this->extractOrigin($url);
            if ($origin !== null) {
                $imgHosts[] = $origin;
            }
        }
        foreach ([ServiceInstance::TYPE_RADARR, ServiceInstance::TYPE_SONARR] as $type) {
            foreach ($this->instances->getEnabled($type) as $instance) {
                $origin = $this->extractOrigin($instance->getUrl());
                if ($origin !== null) {
                    $imgHosts[] = $origin;
                }
            }
        }
        $imgHosts = array_unique($imgHosts);

        $policy = fn(string $scriptSrc): string => sprintf(
            "default-src 'self'; "
            . "img-src 'self' data: blob: %s; "
            . "style-src 'self' 'unsafe-inline' https://rsms.me; "
            . "font-src 'self' https://rsms.me; "
            . "script-src %s; "
            . "connect-src 'self'; "
            . "frame-src https://www.youtube.com https://www.youtube-nocookie.com; "
            . "frame-ancestors %s; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "object-src 'none'",
            implode(' ', $imgHosts),
            $scriptSrc,
            $frameAncestors,
        );

        // Two-stage rollout (Phase 2). The enforcing policy still allows
        // inline script while the templates are being nonced — anything else
        // would break the app on deploy. The strict policy rides along in
        // Report-Only so a missed <script> shows up as a console violation
        // instead of a silently dead page. Both collapse to one enforcing
        // strict policy once the report-only round is clean.
        $response->headers->set('Content-Security-Policy', $policy("'self' 'unsafe-inline' data:"));
        $response->headers->set(
            'Content-Security-Policy-Report-Only',
            $policy("'self' 'nonce-" . $this->nonce->get() . "'"),
        );
    }

    /**
     * Whether this response is an HTML document, i.e. something a CSP can
     * actually govern.
     *
     * A missing Content-Type counts as HTML: Response::prepare() fills in
     * text/html when nothing set one, and this subscriber can also see
     * responses built by hand in tests. Turbo Stream responses
     * (text/vnd.turbo-stream.html) deliberately do not match — their scripts
     * are activated inside the existing document and are governed by *that*
     * document's policy, not by the stream response's headers.
     */
    private function governsADocument(Response $response): bool
    {
        $type = $response->headers->get('Content-Type');

        return $type === null || $type === '' || str_starts_with(strtolower($type), 'text/html');
    }

    /**
     * Extract scheme://host[:port] from a URL. Returns null if invalid.
     */
    private function extractOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? 'http';
        $origin = $scheme . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
}
