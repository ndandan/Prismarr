<?php

namespace App\Tests\Twig;

use App\Twig\SeerrAvatarExtension;
use PHPUnit\Framework\TestCase;

/**
 * Live check on :beta (2026-11-07): Plex-backed Seerr users carry an absolute
 * plex.tv avatar, and the pages glued the Seerr URL in front of it
 * (`http://seerr:5055https://plex.tv/...`). Only a Seerr-hosted path is an
 * image the CSP img-src allows; anything absolute falls back to the
 * placeholder, like the issues page already did.
 */
class SeerrAvatarExtensionTest extends TestCase
{
    public function testASeerrPathIsResolvedAgainstTheServiceUrl(): void
    {
        $this->assertSame('http://seerr:5055/avatarproxy/abc', SeerrAvatarExtension::resolve('/avatarproxy/abc', 'http://seerr:5055'));
        $this->assertSame('http://seerr:5055/avatarproxy/abc', SeerrAvatarExtension::resolve('avatarproxy/abc', 'http://seerr:5055/'), 'one slash, whichever side carries it');
    }

    public function testAnAbsoluteAvatarIsDropped(): void
    {
        foreach (['https://plex.tv/users/1/avatar?c=2', 'http://gravatar.com/x', '//plex.tv/x', 'data:image/png;base64,AA', 'HTTPS://PLEX.TV/x'] as $abs) {
            $this->assertSame('', SeerrAvatarExtension::resolve($abs, 'http://seerr:5055'), $abs);
        }
    }

    public function testNothingToResolveIsEmpty(): void
    {
        $this->assertSame('', SeerrAvatarExtension::resolve(null, 'http://seerr:5055'));
        $this->assertSame('', SeerrAvatarExtension::resolve('', 'http://seerr:5055'));
        $this->assertSame('', SeerrAvatarExtension::resolve('/avatarproxy/abc', ''), 'no Seerr URL configured');
        $this->assertSame('', SeerrAvatarExtension::resolve('/avatarproxy/abc', null));
    }

    public function testRegistersTheTwigFunction(): void
    {
        $names = array_map(fn($f) => $f->getName(), (new SeerrAvatarExtension())->getFunctions());
        $this->assertSame(['seerr_avatar'], $names);
    }
}
