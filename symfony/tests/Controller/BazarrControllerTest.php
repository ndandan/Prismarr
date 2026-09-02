<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\Media\BazarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Tests\AbstractWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Smoke test for the Bazarr section's route guard.
 *
 * AbstractWebTestCase seeds a fresh SQLite DB with an admin user + completed
 * setup flag, but no bazarr_url / bazarr_api_key rows — so every test here
 * runs under the "unconfigured" path and ServiceRouteGuardSubscriber's
 * `app_bazarr_` rule (src/EventSubscriber/ServiceRouteGuardSubscriber.php)
 * fires before BazarrController::index() ever runs, redirecting to
 * admin_settings_index. That guard behavior — not the Wanted-tab markup —
 * is what this task's test proves; template rendering is exercised live on
 * :beta once Bazarr is actually configured (see the task's report).
 */
class BazarrControllerTest extends AbstractWebTestCase
{
    public function testBazarrRedirectsToAdminSettingsWhenUnconfigured(): void
    {
        $this->client->request('GET', '/bazarr');

        self::assertResponseRedirects('/admin/settings');
    }

    public function testMoviesSeriesHistoryRedirectWhenUnconfigured(): void
    {
        foreach (['/bazarr/movies', '/bazarr/series', '/bazarr/history'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/admin/settings');
        }
    }

    public function testSeriesDetailRedirectsWhenUnconfigured(): void
    {
        $this->client->request('GET', '/bazarr/series/7');

        self::assertResponseRedirects('/admin/settings');
    }

    /**
     * Anonymous access to the JSON API routes must be denied, same as every
     * other admin-only section. AbstractWebTestCase::setUp() already logs
     * $this->client in as admin, and the test kernel/client may only be
     * booted once per test (a second static::createClient() call throws) —
     * so we drop the session cookie to make the next requests anonymous
     * instead of spinning up a second client.
     */
    public function testJsonApiRoutesDenyAnonymousAccess(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/bazarr/api/search/movie/1');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'GET search/movie should redirect anonymous users');

        $this->client->request('GET', '/bazarr/api/search/episode/1');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'GET search/episode should redirect anonymous users');

        $this->client->request('POST', '/bazarr/api/download/movie');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST download/movie should redirect anonymous users');

        $this->client->request('POST', '/bazarr/api/download/episode');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST download/episode should redirect anonymous users');

        $this->client->request('POST', '/bazarr/api/auto/movie/1');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST auto/movie should redirect anonymous users');

        $this->client->request('POST', '/bazarr/api/auto/series/1');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST auto/series should redirect anonymous users');
    }

    /**
     * ServiceRouteGuardSubscriber's `app_bazarr_` rule carries an
     * `exclude_prefix` of `app_bazarr_api_`, so the JSON endpoints are exempt
     * from the guard (src/EventSubscriber/ServiceRouteGuardSubscriber.php).
     * A 302-to-HTML on a background fetch is unparseable to the caller, drops
     * the POST body, and leaves a flash message queued for whatever page the
     * user loads next. Unconfigured Bazarr therefore falls through to the
     * controller, whose client fails closed → jsonClientError(): a JSON 500
     * carrying `ok: false`.
     */
    public function testDownloadMovieAnswersJsonErrorWhenUnconfigured(): void
    {
        $this->client->request('POST', '/bazarr/api/download/movie', [
            'radarrid' => 42, 'provider' => 'x', 'subtitle' => 'y', 'hi' => 'False', 'forced' => 'False', 'original_format' => 'False',
        ]);

        self::assertFalse($this->client->getResponse()->isRedirect(), 'API routes must never redirect');
        self::assertResponseStatusCodeSame(500);
        self::assertJson((string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertSame('Bazarr', $payload['service']);
    }

    public function testAutoMovieAnswersJsonErrorWhenUnconfigured(): void
    {
        $this->client->request('POST', '/bazarr/api/auto/movie/42');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(500);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
    }

    public function testSearchMovieAnswersJsonErrorWhenUnconfigured(): void
    {
        $this->client->request('GET', '/bazarr/api/search/movie/42');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(500);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
    }

    public function testTheRefreshEndpointDeniesAnonymousAccess(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST api/refresh must redirect anonymous users');
    }

    /**
     * Fix round 1, MINOR 9: a logged-in but non-admin user must be denied too
     * — #[IsGranted('ROLE_ADMIN')] on the class throws AccessDeniedException,
     * which the security layer turns into a 403 (not a redirect: the user IS
     * authenticated, just under-privileged).
     */
    public function testTheRefreshEndpointDeniesNonAdminAccess(): void
    {
        $user = new User();
        $user->setEmail('member@test.local');
        $user->setDisplayName('Member');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'member-password'));

        $em = $this->em();
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Fix round 1, IMPORTANT 1: the response must be truthful, not an
     * unconditional {"ok": true}. Unconfigured Bazarr (this test's fixture)
     * is a clean-empty fetch — BazarrClient::ready() is false, so getMovies/
     * getSeries/getBadgeCounts never make an HTTP call and never record a
     * lastError — so the inline refresh genuinely succeeds by writing fresh
     * empty maps, and the endpoint must report exactly that.
     */
    public function testTheRefreshEndpointReturnsATruthfulShapeForAdmin(): void
    {
        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertFalse($this->client->getResponse()->isRedirect(), 'API routes must never redirect');
        self::assertResponseStatusCodeSame(200);
        self::assertJson((string) $this->client->getResponse()->getContent());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('ok', $payload);
        self::assertArrayHasKey('movies', $payload);
        self::assertArrayHasKey('series', $payload);
        self::assertArrayHasKey('reason', $payload);
        self::assertTrue($payload['ok']);
        self::assertSame('fresh', $payload['movies']);
        self::assertSame('fresh', $payload['series']);
        self::assertNull($payload['reason']);
    }

    /**
     * Fix round 1, IMPORTANT 1 + MINOR 9: an open breaker must skip the fetch
     * entirely (structurally guaranteed by apiRefresh() returning before it
     * ever calls BazarrIndexRefresher::refresh()) and answer ok:false with a
     * breaker_open reason, never a 500.
     */
    public function testTheRefreshEndpointAnswersBreakerOpenWithoutFetching(): void
    {
        $pool = static::getContainer()->get('cache.app');
        (new ServiceHealthCache($pool))->markDown(BazarrClient::SERVICE);

        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertSame('breaker_open', $payload['reason']);
    }
}
