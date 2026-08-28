<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Guards indexer-supplied URLs before they reach the frontend.
 *
 * A release payload's `infoUrl` (and any other URL) is passed through verbatim
 * from whatever indexer answered the search, so a compromised/hostile indexer
 * could return a `javascript:`/`data:` URL. Reject anything that isn't http(s)
 * here rather than relying on a page CSP (which does not exist upstream). This
 * consolidates the identical guard that MediaController and ProwlarrClient each
 * carried while their fixes were separate upstream PRs (#99, #101).
 */
final class SafeUrl
{
    /** Return the URL only when it is a string http(s) URL, else null. */
    public static function httpOrNull(mixed $url): ?string
    {
        return (is_string($url) && preg_match('~^https?://~i', $url) === 1) ? $url : null;
    }
}
