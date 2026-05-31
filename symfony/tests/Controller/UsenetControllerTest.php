<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;

/**
 * #20 — a configured-but-unreachable Usenet client must show the explicit
 * "unreachable" banner (like qBittorrent), not a silent empty page. The
 * page probes the client at render: an invalid host → getVersion() null →
 * error banner, with the live content hidden.
 */
class UsenetControllerTest extends AbstractWebTestCase
{
    public function testUnreachableSabnzbdShowsErrorBanner(): void
    {
        $em = $this->em();
        // Invalid host resolves to NXDOMAIN fast, so the probe fails quickly.
        $em->persist(new Setting('sabnzbd_url', 'http://sabnzbd.invalid:8080'));
        $em->persist(new Setting('sabnzbd_api_key', 'k'));
        $em->flush();

        $this->client->request('GET', '/usenet/sabnzbd');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        // Error banner shown, live content hidden.
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringNotContainsString('data-usenet-client', $html);
    }

    public function testUnconfiguredClientRedirectsHome(): void
    {
        // No sabnzbd_* settings seeded → not configured → redirect with flash.
        $this->client->request('GET', '/usenet/sabnzbd');
        $this->assertTrue($this->client->getResponse()->isRedirect());
    }
}
