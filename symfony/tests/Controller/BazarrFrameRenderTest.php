<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\HealthService;
use App\Tests\AbstractWebTestCase;

/**
 * The only test in the suite that actually renders Bazarr markup. Without the
 * two settings rows the route guard redirects before the controller body runs,
 * which is why the frame/full split could otherwise ship broken (the CI blind
 * spot BazarrControllerTest's docblock records).
 *
 * ServiceRouteGuardSubscriber's OWN health check (step 2 of its rule: a
 * configured-but-unreachable service redirects every non-index sub-route to
 * the section index, `src/EventSubscriber/ServiceRouteGuardSubscriber.php`)
 * runs on every request, before the controller. Tripping BazarrClient's
 * circuit breaker (BazarrControllerTest's approach for the JSON routes,
 * which are exempt from this guard) trips THAT guard too — HealthService
 * reports 'degraded', which is unhealthy, and every /bazarr/movies|series
 * request would 302 to /bazarr before ever reaching BazarrController, making
 * the frame-vs-full split untestable on those routes.
 *
 * BazarrClient itself cannot be swapped for a test double here either: the
 * `turbo` bundle's Doctrine broadcaster listens on every entity flush and
 * builds the Twig environment to render its broadcast template, which pulls
 * in every registered Twig extension — including SubtitleBadgeExtension,
 * which depends on BazarrSubtitleIndex, which depends on BazarrClient. That
 * happens on AbstractWebTestCase::setUp()'s OWN seed flushes, before this
 * class's setUp() body ever runs, so BazarrClient is already a real,
 * container-built singleton by the time a test method starts — Symfony's
 * test container refuses to replace an already-initialized private service.
 *
 * HealthService is not part of that Twig-extension graph, so it is still
 * swappable: faking ONLY its isHealthy() lets the guard through, while the
 * real (unmocked) BazarrClient still runs its own ping() inside the
 * controller against an address nothing listens on, so the controller
 * renders its ordinary error state — exercising the exact
 * try/ping()/catch → error-banner path every other media controller test
 * uses, just without the guard intercepting it first.
 */
class BazarrFrameRenderTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        $em->persist(new Setting('bazarr_url', 'http://127.0.0.1:1'));
        $em->persist(new Setting('bazarr_api_key', 'k'));
        $em->flush();

        $health = $this->createStub(HealthService::class);
        $health->method('isHealthy')->willReturn(true);
        static::getContainer()->set(HealthService::class, $health);

        // The functional test client reboots the kernel (a fresh container)
        // before every request by default, which would silently drop the
        // override above on any test method making more than one request.
        $this->client->disableReboot();
    }

    public function testAPlainRequestRendersTheFullPage(): void
    {
        $this->client->request('GET', '/bazarr/movies');

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('<html', $html, 'a normal navigation gets the whole document');
        $this->assertStringContainsString('id="bazarr-view"', $html, 'the shell always contains the frame');
    }

    public function testAFrameRequestRendersOnlyTheFrame(): void
    {
        $this->client->request('GET', '/bazarr/movies', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('<html', $html, 'a frame navigation must not ship the whole document');
        $this->assertStringContainsString(
            'id="bazarr-view"',
            $html,
            'Turbo matches the replacement by finding the frame element IN the response — a bare inner fragment leaves the frame empty',
        );
    }

    public function testBothShapesVaryOnTheTurboFrameHeader(): void
    {
        $this->client->request('GET', '/bazarr/movies');
        $this->assertStringContainsString('Turbo-Frame', (string) $this->client->getResponse()->headers->get('Vary'));
    }

    public function testTheLandingAndSeriesViewsBranchTheSameWay(): void
    {
        foreach (['/bazarr', '/bazarr/series'] as $path) {
            $this->client->request('GET', $path, [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);
            $this->assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent(), $path);
        }
    }

    public function testHistoryStaysAFullTurboDrivePage(): void
    {
        $this->client->request('GET', '/bazarr/history', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $this->assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
    }
}
