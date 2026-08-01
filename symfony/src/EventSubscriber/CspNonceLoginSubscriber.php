<?php

namespace App\EventSubscriber;

use App\Service\CspNonceGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Rotates the CSP nonce at the login boundary.
 *
 * CspNonceGenerator anchors the nonce to a session attribute (`_csp_nonce`)
 * so it stays byte-stable for Turbo Drive across a session's lifetime (see
 * that class's docblock). Symfony migrates the session id on login but
 * carries session *attributes* over unchanged, so without this listener the
 * pre-auth nonce would still be sitting in the session after login.
 *
 * That matters for a fixation-style attacker: someone who can plant a known
 * session id on a victim before they authenticate (independent of this CSP)
 * would then also know the exact nonce the victim's post-login, enforced
 * policy will trust — without ever needing an XSS foothold to read it.
 * Removing the attribute here forces CspNonceGenerator to mint a fresh one
 * on the next request. The cost is one full-page reload right at the login
 * boundary, which happens anyway (the login POST redirects to app_home).
 */
final class CspNonceLoginSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->remove(CspNonceGenerator::SESSION_KEY);
    }
}
