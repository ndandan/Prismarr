<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * Smoke tests for the Tautulli activity-page endpoints.
 *
 * The test env uses a fresh SQLite DB with no Tautulli config rows
 * (AbstractWebTestCase seeds: admin user, setup-done flag, default
 * Radarr/Sonarr instances — but NO tautulli_url / tautulli_api_key).
 *
 * As a result every test operates under the "unconfigured" path:
 *   - JSON API endpoints (apiPlays) fail open → 200 + neutral shape.
 *   - Page route (app_tautulli_index) is caught by
 *     ServiceRouteGuardSubscriber → redirect to admin_settings_index.
 */
class TautulliControllerTest extends AbstractWebTestCase
{
    /**
     * /tautulli/api/plays returns HTTP 200 with the neutral
     * {categories:[], series:[]} shape when Tautulli is not configured.
     *
     * TautulliClient::getPlaysByDate() fails open: missing config rows →
     * normalizePlaysByDate([]) → {categories:[], series:[]}.
     * The controller also wraps in a try/catch that returns the same shape.
     */
    public function testPlaysEndpointReturnsNeutralJsonWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tautulli/api/plays?range=30');

        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);
        self::assertJson($content);

        /** @var array{categories: mixed, series: mixed} $data */
        $data = json_decode($content, true);
        self::assertSame([], $data['categories']);
        self::assertSame([], $data['series']);
    }

    /**
     * An out-of-range ?range= value must not cause a 500; the endpoint
     * clamps the value server-side and returns 200.
     */
    public function testRangeParamOutOfBoundsDoesNotError(): void
    {
        $this->client->request('GET', '/tautulli/api/plays?range=9999');

        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);
        self::assertJson($content);

        /** @var array{categories: mixed, series: mixed} $data */
        $data = json_decode($content, true);
        self::assertSame([], $data['categories']);
        self::assertSame([], $data['series']);
    }

    /**
     * The page route /tautulli is guarded by ServiceRouteGuardSubscriber.
     * With no tautulli_url / tautulli_api_key in DB the guard fires
     * before the controller and redirects to admin_settings_index.
     */
    public function testActivityPageGuardedWhenUnconfigured(): void
    {
        $this->client->request('GET', '/tautulli');

        self::assertResponseRedirects('/admin/settings');
    }

    /**
     * The new chart endpoints fail open to the neutral {categories:[],series:[]}
     * JSON when Tautulli is unconfigured, and the plays endpoint accepts the
     * stream-type mode without erroring.
     */
    public function testChartEndpointsReturnNeutralJsonWhenUnconfigured(): void
    {
        foreach ([
            '/tautulli/api/plays?range=30&mode=stream',
            '/tautulli/api/activity-hour?range=30',
            '/tautulli/api/activity-dow?range=30',
            '/tautulli/api/clients-stream-type?range=30',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $content = $this->client->getResponse()->getContent();
            self::assertNotFalse($content);
            self::assertJson($content);
            /** @var array{categories: mixed, series: mixed} $data */
            $data = json_decode($content, true);
            self::assertSame([], $data['categories'], $url);
            self::assertSame([], $data['series'], $url);
        }
    }
}
