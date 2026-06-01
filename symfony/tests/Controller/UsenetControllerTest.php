<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\HealthService;
use App\Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * #20 — a configured-but-unreachable Usenet client must show an explicit
 * banner (like qBittorrent), not a silent empty page. The page probes the
 * client at render via HealthService::diagnose() (NOT getVersion(): SABnzbd
 * answers mode=version 200 for any key, so a wrong API key would slip past).
 * The diagnosis category drives the banner: auth (bad key/credentials),
 * host_whitelist (host blocked) or a generic unreachable.
 */
#[AllowMockObjectsWithoutExpectations]
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
        // Error banner shown, live content hidden. (We can't assert on
        // "data-usenet-client" — the JS block always references it by selector;
        // the stats markup `data-stat=` only renders inside {% if not error %}.)
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('data-reason="unreachable"', $html);
        $this->assertStringNotContainsString('data-stat="active"', $html);
    }

    public function testInvalidApiKeyShowsAuthBanner(): void
    {
        // Regression for #20: SABnzbd's mode=version returns 200 for ANY key,
        // so the old getVersion() probe left a wrong-key page silently empty.
        // diagnose() probes mode=queue and reports category 'auth' on a 403
        // "API Key Incorrect" → the page must surface the auth banner.
        $em = $this->em();
        $em->persist(new Setting('sabnzbd_url', 'http://sab.test:8080'));
        $em->persist(new Setting('sabnzbd_api_key', 'wrong-key'));
        $em->flush();

        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturn(true);
        $health->method('diagnose')->willReturn(['ok' => false, 'category' => 'auth', 'http' => 403]);
        static::getContainer()->set(HealthService::class, $health);

        $this->client->request('GET', '/usenet/sabnzbd');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('data-reason="auth"', $html);
        $this->assertStringNotContainsString('data-stat="active"', $html);
    }

    public function testHostWhitelistShowsDedicatedBanner(): void
    {
        // SABnzbd answers 403 for both a bad key and a host not in its
        // host_whitelist; diagnose() tells them apart so the admin gets the
        // actionable host_whitelist hint instead of a generic failure.
        $em = $this->em();
        $em->persist(new Setting('sabnzbd_url', 'http://sab.test:8080'));
        $em->persist(new Setting('sabnzbd_api_key', 'k'));
        $em->flush();

        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturn(true);
        $health->method('diagnose')->willReturn(['ok' => false, 'category' => 'host_whitelist', 'http' => 403]);
        static::getContainer()->set(HealthService::class, $health);

        $this->client->request('GET', '/usenet/sabnzbd');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('data-reason="host_whitelist"', $html);
        $this->assertStringNotContainsString('data-stat="active"', $html);
    }

    public function testUnconfiguredClientRedirectsHome(): void
    {
        // No sabnzbd_* settings seeded → not configured → redirect with flash.
        $this->client->request('GET', '/usenet/sabnzbd');
        $this->assertTrue($this->client->getResponse()->isRedirect());
    }
}
