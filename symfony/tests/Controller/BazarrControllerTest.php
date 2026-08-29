<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

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
}
