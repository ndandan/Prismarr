<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Session-stable nonce for the Content-Security-Policy script-src directive.
 *
 * The value is stable for the lifetime of a session and rotates when a new
 * session starts. It began as a per-request value; live verification of the
 * Report-Only rollout showed that rotating per request is incompatible with
 * Hotwire Turbo, in two ways:
 *
 *  - Turbo Drive died app-wide. ux-turbo marks the ImportMapRenderer head
 *    scripts data-turbo-track="reload", and the renderer bakes the nonce
 *    into the es-module-shims loader's script *body*
 *    (script.setAttribute('nonce', '<value>')). Turbo blanks nonce
 *    *attributes* before computing its tracked-head signature but cannot
 *    reach a value inside script text, so the signature changed on every
 *    response and every navigation degraded to a full reload.
 *  - Under an enforcing header it would have blocked every body script on
 *    every Turbo-rendered page: Turbo's head merge swaps
 *    <meta name="csp-nonce"> to the new response's value and re-stamps that
 *    value onto each re-activated script, while the document is still
 *    governed by the policy sent with the original request.
 *
 * A session-stable value fixes both: the baked polyfill body is byte-stable
 * within a session, and Turbo's re-stamped nonce always matches the
 * governing policy. It is also the canonical approach in the Turbo
 * ecosystem (Rails ships a session-based CSP nonce generator by default).
 *
 * ResetInterface is kept, but its job changed. It no longer mints a new
 * nonce — rotation now comes from the session. It clears the in-memory memo
 * so that a FrankenPHP worker, whose container survives for minutes across
 * interleaved sessions, can never leak session A's nonce into session B's
 * response.
 *
 * The nonce is an independent random value held in a session attribute; it
 * is never derived from the session id, which would publish a session
 * identifier in every page's HTML. Symfony migrates the session id on login
 * but keeps the attributes, so the nonce survives the privilege change.
 * That is acceptable: reading the pre-auth value requires an XSS foothold,
 * which is the very thing this CSP exists to prevent.
 */
final class CspNonceGenerator implements ResetInterface
{
    private const SESSION_KEY = '_csp_nonce';

    private ?string $nonce = null;

    public function __construct(private readonly RequestStack $requestStack) {}

    public function get(): string
    {
        return $this->nonce ??= $this->resolve();
    }

    public function reset(): void
    {
        $this->nonce = null;
    }

    private function resolve(): string
    {
        $session = $this->session();

        // No session to anchor to (CLI, or a stateless route): fall back to a
        // per-request value. Memoized by get() so the whole response stays
        // consistent, but deliberately not stored anywhere.
        if ($session === null) {
            return self::mint();
        }

        $stored = $session->get(self::SESSION_KEY);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $session->set(self::SESSION_KEY, $nonce = self::mint());

        return $nonce;
    }

    /**
     * The generator runs on kernel.response for every single response, so it
     * must never throw. hasSession() can report true while the session is
     * unusable (a lazy factory is installed but resolving it fails on a
     * stateless route), hence the belt-and-braces catch.
     */
    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        try {
            return $request->getSession();
        } catch (SessionNotFoundException) {
            return null;
        }
    }

    private static function mint(): string
    {
        return base64_encode(random_bytes(16));
    }
}
