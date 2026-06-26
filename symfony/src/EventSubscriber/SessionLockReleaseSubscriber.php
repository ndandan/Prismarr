<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Releases the PHP session file lock as early as possible on read-only
 * requests, so concurrent requests don't serialise on it (issue: Unraid
 * lockups).
 *
 * Why this matters: PHP's native file session handler holds an exclusive
 * `flock` on the session file for the whole request. The firewall reads the
 * session once (on kernel.request) to authenticate; after that the dashboard
 * widgets, films/series pages, qBit and Usenet views never write to it, yet
 * the lock is kept until the response is sent. The dashboard fires ~6 widget
 * fragments in parallel, so they all block on the same session lock while
 * their slow Radarr/Sonarr calls run. On Unraid, the session file lives on
 * the FUSE share (`/mnt/user`, shfs), where `flock` contention is expensive
 * enough to peg every core and freeze the whole box.
 *
 * Closing the session right after the controller is resolved (auth already
 * done) drops the lock immediately, so the parallel fragments stop fighting
 * over it. We only do this for GET main requests: POSTs (CSRF mutations,
 * login, flash messages) keep the session open and write normally. The setup
 * wizard is excluded because it legitimately writes `_locale` to the session
 * on its language picker; internal routes (_profiler, _wdt) are skipped too.
 */
class SessionLockReleaseSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX_BLOCKLIST = [
        'app_setup_', // the wizard writes `_locale` to the session on GET
        '_',          // Symfony internals (_profiler, _wdt, _error)
    ];

    public static function getSubscribedEvents(): array
    {
        // After the firewall has authenticated (kernel.request), before the
        // controller runs. The session has been read; we no longer need the lock.
        return [KernelEvents::CONTROLLER => ['onKernelController', 0]];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!self::shouldRelease($request)) {
            return;
        }

        // save() flushes any pending change and releases the file lock; the
        // session stays readable for the rest of the request.
        $request->getSession()->save();
    }

    /**
     * A request is safe to release the session lock for when it's a GET main
     * request with an already-started session that isn't a route known to
     * write to the session.
     */
    public static function shouldRelease(Request $request): bool
    {
        if (!$request->isMethod('GET')) {
            return false;
        }

        if (!$request->hasSession()) {
            return false;
        }

        $session = $request->getSession();
        if (!$session->isStarted()) {
            return false;
        }

        $route = (string) $request->attributes->get('_route', '');
        foreach (self::ROUTE_PREFIX_BLOCKLIST as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
