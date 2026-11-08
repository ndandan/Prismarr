<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `seerr_avatar(user.avatar, service_url)` → the URL a Seerr avatar loads
 * from, or '' for the placeholder.
 *
 * A local Jellyfin user's avatar is a Seerr path (/avatarproxy/…), served by
 * the Seerr origin that CspHeaderSubscriber puts on img-src. A Plex user's is
 * an absolute plex.tv URL: prefixing the Seerr URL produced
 * `http://seerr:5055https://plex.tv/…`, and loading it as-is would be
 * CSP-blocked, so absolute avatars fall back to the placeholder. The Seerr
 * pages' inline seerrAvatar() helper is the client-side twin.
 */
final class SeerrAvatarExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('seerr_avatar', [self::class, 'resolve'])];
    }

    public static function resolve(?string $avatar, ?string $serviceUrl): string
    {
        $avatar     = (string) $avatar;
        $serviceUrl = rtrim((string) $serviceUrl, '/');
        if ($avatar === '' || $serviceUrl === '' || preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $avatar)) {
            return '';
        }
        return $serviceUrl . '/' . ltrim($avatar, '/');
    }
}
